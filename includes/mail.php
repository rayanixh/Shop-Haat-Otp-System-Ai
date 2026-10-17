<?php
/**
 * ShopHaat email service — verification codes, password recovery, security
 * notifications, delivery log and the provider webhook.
 *
 * This is the single layer every authentication email flow goes through. It sits
 * on top of config/mail.php (PHPMailer transport) and owns:
 *
 *   - the challenge policy: cryptographically random six-digit codes, bcrypt
 *     hashes only, purpose scoping, expiry, attempt limits, resend cooldown,
 *     per-address and per-IP rate limits, single use, opportunistic cleanup;
 *   - the email templates (professional, mobile-friendly HTML plus a plain-text
 *     alternative, UTF-8, no images required to be read);
 *   - the delivery log and the honest reporting of what actually happened;
 *   - the verifier decision: SMTP OTP when it is configured, Firebase when it is
 *     the only configured verifier, and never a silent "verified" flag;
 *   - the provider webhook that moves a message from "accepted" to
 *     delivered/bounced/complaint, signature checked before anything is stored.
 *
 * Nothing here stores a plaintext code, a password, a reset token or a secret.
 */
declare(strict_types=1);

require_once SH_ROOT . '/config/mail.php';

const SH_EMAIL_PURPOSE_SIGNUP = 'signup';
const SH_EMAIL_PURPOSE_RESET  = 'password_reset';
const SH_EMAIL_PURPOSE_CHANGE = 'password_change';

/**
 * Create/upgrade the mail tables and settings on an existing store, and
 * grandfather the accounts that already exist.
 *
 * Why grandfathering matters: the email_verified column has never meant "the
 * inbox was confirmed by a code we sent" for accounts created before this
 * system existed (the column was written by the Firebase sync only). Applying
 * the new rule retroactively would lock every current customer out of checkout
 * the moment an operator switched the gate on. Instead, accounts present when
 * the upgrade runs are marked verified once — stamped in settings so it happens
 * exactly once — and accounts created afterwards start unverified and have to
 * complete the code flow.
 */
function sh_email_schema_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        $ver = (int)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'email_schema_version' LIMIT 1", [], 0);
        $pdo = sh_db();

        if ($ver < SH_EMAIL_SCHEMA_VERSION) {
            // CREATE TABLE IF NOT EXISTS only: an upgrade never alters or drops a
            // table that already holds data.
            foreach (sh_email_schema_sql() as $ddl) { $pdo->exec($ddl); }

            // The verification flag predates this system on some stores (the
            // Firebase sync added it). Add it where it is genuinely missing —
            // checked through information_schema, so nothing is altered twice.
            $cols = ['email_verified' => 'TINYINT(1) NOT NULL DEFAULT 0',
                     'email_verified_at' => 'DATETIME DEFAULT NULL'];
            foreach ($cols as $col => $def) {
                $exists = (int)sh_val('SELECT COUNT(*) FROM information_schema.columns
                                       WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                    ['users', $col], 0);
                if ($exists === 0) { $pdo->exec("ALTER TABLE users ADD COLUMN `$col` $def"); }
            }

            sh_email_seed_settings($pdo);
            sh_setting_save('email_schema_version', (string)SH_EMAIL_SCHEMA_VERSION);
        }

        // One-time grandfathering for stores created before this system.
        if (sh_setting('email_verify_grandfathered', '0') !== '1') {
            $affected = (int)$pdo->exec(
                'UPDATE users SET email_verified = 1,
                                  email_verified_at = COALESCE(email_verified_at, created_at)
                 WHERE email_verified = 0 AND email <> ""'
            );
            sh_setting_save('email_verify_grandfathered', '1');
            if ($affected > 0 && function_exists('sh_log_line')) {
                sh_log_line('mail', 'Grandfathered ' . $affected . ' pre-existing account(s) as email-verified during the mail migration.');
            }
        }
    } catch (Throwable $e) {
        if (function_exists('sh_log_exception')) { sh_log_exception($e, 'email-schema'); }
    }
}

// ---------------------------------------------------------------------------
// Settings accessors (never read raw keys in pages)
// ---------------------------------------------------------------------------

function sh_email_verifier(): string
{
    $provider = (string)sh_setting('email_delivery_provider', 'smtp');
    $mail = sh_mail_service_status();
    if ($provider === 'firebase' && function_exists('sh_fb_enabled') && sh_fb_enabled()) {
        return 'firebase';
    }
    if (sh_setting('email_verify_enabled', '1') === '1' && (bool)$mail['ready']) { return 'smtp_otp'; }
    if (function_exists('sh_fb_enabled') && sh_fb_enabled()) { return 'firebase'; }
    return (bool)$mail['ready'] ? 'smtp_otp' : 'none';
}

function sh_email_delivery_provider(): string
{
    $p = (string)sh_setting('email_delivery_provider', 'smtp');
    return in_array($p, ['smtp', 'firebase'], true) ? $p : 'smtp';
}

function sh_email_verify_method(): string
{
    return 'otp_code';
}

/** Can the store send an authentication email right now? */
function sh_email_can_send(): bool
{
    $s = sh_mail_service_status();
    return (bool)$s['ready'];
}

function sh_email_verify_enabled(): bool
{
    return sh_setting('email_verify_enabled', '1') === '1' && sh_email_can_send();
}

function sh_email_forgot_enabled(): bool
{
    return sh_setting('email_forgot_enabled', '1') === '1';
}

function sh_email_otp_expiry_minutes(): int
{
    $m = (int)sh_setting('email_otp_expiry_minutes', '10');
    return ($m >= 5 && $m <= 60) ? $m : 10;
}

function sh_email_otp_max_attempts(): int
{
    $n = (int)sh_setting('email_otp_max_attempts', '5');
    return ($n >= 3 && $n <= 20) ? $n : 5;
}

function sh_email_otp_max_resends(): int
{
    $n = (int)sh_setting('email_otp_max_resends', '5');
    return ($n >= 1 && $n <= 20) ? $n : 5;
}

function sh_email_otp_resend_cooldown(): int
{
    $n = (int)sh_setting('email_otp_resend_cooldown', '60');
    return ($n >= 30 && $n <= 900) ? $n : 60;
}

function sh_email_otp_code_length(): int
{
    return 6;
}

/** Hide most of an address before it is written to a log or shown in a message. */
function sh_email_mask(string $email): string
{
    $email = trim($email);
    if ($email === '' || !str_contains($email, '@')) { return '(none)'; }
    [$local, $domain] = explode('@', $email, 2);
    $keep = mb_substr($local, 0, 2);
    return $keep . '***@' . $domain;
}

// ---------------------------------------------------------------------------
// Challenge lifecycle
// ---------------------------------------------------------------------------

/** Cryptographically secure numeric code, leading zeros preserved. */
function sh_email_otp_generate(): string
{
    $len = sh_email_otp_code_length();
    $max = (10 ** $len) - 1;
    return str_pad((string)random_int(0, $max), $len, '0', STR_PAD_LEFT);
}

/** How many challenges of any purpose were created for this address/ip recently. */
function sh_email_challenge_count(string $column, string $value, int $seconds): int
{
    // Two literal statements instead of an interpolated column name: the column
    // never comes from a request, and there is nothing to inject even by mistake.
    $sql = match ($column) {
        'email'      => 'SELECT COUNT(*) FROM email_challenges WHERE email = ? AND created_at > ?',
        'ip_address' => 'SELECT COUNT(*) FROM email_challenges WHERE ip_address = ? AND created_at > ?',
        default      => '',
    };
    if ($sql === '') { return 0; }
    return (int)sh_val($sql, [$value, date('Y-m-d H:i:s', time() - $seconds)], 0);
}

/**
 * Decide whether a new code may be issued for this address, and create it.
 *
 * The rules, in order:
 *   - a cooldown since the last send for the same address and purpose;
 *   - a per-address hourly cap (email_otp_email_limit);
 *   - a per-IP hourly cap (email_otp_ip_limit);
 *   - a resend cap per challenge.
 * When a code is issued the previous pending code for the same purpose is
 * revoked, so exactly one code is ever accept-able at a time.
 *
 * @return array{ok:bool,error?:string,code?:string,challenge_id?:int,expires_at?:string,wait?:int}
 */
function sh_email_challenge_issue(string $email, string $purpose, ?int $userId = null, array $ctx = []): array
{
    $email = mb_strtolower(trim($email));
    $purpose = (string)preg_replace('~[^a-z_]~', '', strtolower($purpose));
    if ($email === '' || !sh_valid_email($email) || $purpose === '') {
        return ['ok' => false, 'error' => 'A valid email address is required.'];
    }

    $ip = mb_substr(sh_client_ip(), 0, 45);
    $last = sh_one(
        'SELECT id, last_sent_at, resend_count, max_resends FROM email_challenges
         WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1',
        [$email, $purpose]
    );
    if ($last !== null && !empty($last['last_sent_at'])) {
        $wait = sh_email_otp_resend_cooldown() - (time() - (int)strtotime((string)$last['last_sent_at']));
        if ($wait > 0) {
            return ['ok' => false, 'error' => 'A code was sent a moment ago. Please wait ' . $wait . ' seconds before requesting another.', 'wait' => $wait];
        }
    }

    // A fresh code per request plus the resend budget for that address, per hour.
    $emailHourlyLimit = max(3, (int)sh_setting('email_otp_email_limit', '3')) + sh_email_otp_max_resends();
    if (sh_email_challenge_count('email', $email, 3600) >= $emailHourlyLimit) {
        return ['ok' => false, 'error' => 'Too many codes were requested for this address. Please try again later.'];
    }
    if ($ip !== '' && sh_email_challenge_count('ip_address', $ip, 3600) >= max(3, (int)sh_setting('email_otp_ip_limit', '10'))) {
        return ['ok' => false, 'error' => 'Too many verification codes were requested from this connection. Please try again later.'];
    }

    $code = sh_email_otp_generate();
    $expires = date('Y-m-d H:i:s', time() + sh_email_otp_expiry_minutes() * 60);
    $resends = ($last !== null && (int)$last['resend_count'] < sh_email_otp_max_resends()) ? (int)$last['resend_count'] + 1 : 1;

    // Only one live code per address+purpose: the older ones can no longer succeed.
    sh_query(
        "UPDATE email_challenges SET status = 'revoked'
         WHERE email = ? AND purpose = ? AND status = 'pending'",
        [$email, $purpose]
    );

    $id = (int)sh_insert('email_challenges', [
        'user_id'      => $userId !== null && $userId > 0 ? $userId : null,
        'email'        => $email,
        'purpose'      => $purpose,
        'code_hash'    => password_hash($code, PASSWORD_DEFAULT),
        'status'       => 'pending',
        'max_attempts' => sh_email_otp_max_attempts(),
        'max_resends'  => sh_email_otp_max_resends(),
        'resend_count' => $resends,
        'ip_address'   => $ip !== '' ? $ip : null,
        'user_agent'   => mb_substr((string)($ctx['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255),
        'last_sent_at' => date('Y-m-d H:i:s'),
        'expires_at'   => $expires,
    ]);

    return ['ok' => true, 'code' => $code, 'challenge_id' => $id, 'expires_at' => $expires];
}

/** The newest challenge for an address+purpose, whatever its state. */
function sh_email_challenge_latest(string $email, string $purpose): ?array
{
    return sh_one(
        'SELECT * FROM email_challenges WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1',
        [mb_strtolower(trim($email)), $purpose]
    );
}

/** Note that a code was handed to the mail transport (successfully or not). */
function sh_email_challenge_sent(int $challengeId, array $result): void
{
    try {
        sh_update('email_challenges', ['last_sent_at' => date('Y-m-d H:i:s')], 'id = ?', [$challengeId]);
        if (empty($result['ok'])) {
            // The code exists but the customer never received it: revoke it so a
            // stale hash cannot be used later, and let the UI ask for a resend.
            sh_query(
                "UPDATE email_challenges SET status = 'revoked' WHERE id = ? AND status = 'pending'",
                [$challengeId]
            );
        }
    } catch (Throwable $e) {
        if (function_exists('sh_log_exception')) { sh_log_exception($e, 'email-challenge'); }
    }
}

/**
 * Verify a submitted code.
 *
 * Order of checks: an active challenge exists → it has not expired → the
 * attempt budget is not exhausted → the code matches. A wrong code spends one
 * attempt; exhausting the budget locks the challenge and the customer has to ask
 * for a new one. Success marks the row used with a conditional UPDATE, so two
 * concurrent submissions cannot both succeed.
 *
 * @return array{ok:bool,error?:string,state:string,user_id:?int,challenge_id?:int}
 */
function sh_email_challenge_verify(string $email, string $purpose, string $code, ?int $userId = null): array
{
    $email = mb_strtolower(trim($email));
    $code = trim($code);
    if ($email === '' || $code === '' || preg_match('~^[0-9]{' . sh_email_otp_code_length() . '}$~', $code) !== 1) {
        return ['ok' => false, 'state' => 'invalid', 'error' => 'Enter the ' . sh_email_otp_code_length() . '-digit code from the email.', 'user_id' => $userId];
    }

    $row = sh_email_challenge_latest($email, $purpose);
    if ($row === null) {
        return ['ok' => false, 'state' => 'none', 'error' => 'No code is waiting for this address. Request a new one.', 'user_id' => $userId];
    }
    $id = (int)$row['id'];
    if ((string)$row['status'] === 'used') {
        return ['ok' => false, 'state' => 'used', 'error' => 'That code has already been used. Request a new one.', 'user_id' => $userId];
    }
    if ((string)$row['status'] === 'revoked') {
        return ['ok' => false, 'state' => 'expired', 'error' => 'That code is no longer valid. Request a new one.', 'user_id' => $userId];
    }
    if ((string)$row['status'] === 'locked' || (int)$row['attempts'] >= (int)$row['max_attempts']) {
        sh_query("UPDATE email_challenges SET status = 'locked' WHERE id = ? AND status = 'pending'", [$id]);
        return ['ok' => false, 'state' => 'locked', 'error' => 'Too many incorrect attempts. Request a new code.', 'user_id' => $userId];
    }
    if (strtotime((string)$row['expires_at']) < time()) {
        sh_query("UPDATE email_challenges SET status = 'expired' WHERE id = ? AND status = 'pending'", [$id]);
        return ['ok' => false, 'state' => 'expired', 'error' => 'That code has expired. Request a new one.', 'user_id' => $userId];
    }
    if (!password_verify($code, (string)$row['code_hash'])) {
        sh_query('UPDATE email_challenges SET attempts = attempts + 1 WHERE id = ?', [$id]);
        $left = max(0, (int)$row['max_attempts'] - ((int)$row['attempts'] + 1));
        if ($left === 0) {
            sh_query("UPDATE email_challenges SET status = 'locked' WHERE id = ?", [$id]);
            return ['ok' => false, 'state' => 'locked', 'error' => 'Too many incorrect attempts. Request a new code.', 'user_id' => $userId];
        }
        return ['ok' => false, 'state' => 'wrong', 'error' => 'That code is not correct. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.', 'user_id' => $userId];
    }

    // Single use, enforced by the database rather than by a read-then-write race.
    $st = sh_query(
        "UPDATE email_challenges SET status = 'used', used_at = NOW() WHERE id = ? AND status = 'pending'",
        [$id]
    );
    if ($st->rowCount() !== 1) {
        return ['ok' => false, 'state' => 'used', 'error' => 'That code has already been used. Request a new one.', 'user_id' => $userId];
    }
    // Any other live code for this address loses its power at the same moment.
    sh_query(
        "UPDATE email_challenges SET status = 'revoked' WHERE email = ? AND purpose = ? AND status = 'pending'",
        [$email, $purpose]
    );
    return ['ok' => true, 'state' => 'ok', 'user_id' => $userId, 'challenge_id' => $id];
}

/** Invalidate every live challenge for one address (after a reset, or on request). */
function sh_email_challenge_revoke(string $email, string $purpose): int
{
    return (int)sh_query(
        "UPDATE email_challenges SET status = 'revoked' WHERE email = ? AND purpose = ? AND status = 'pending'",
        [mb_strtolower(trim($email)), $purpose]
    )->rowCount();
}

/**
 * Opportunistic cleanup: drop finished challenges older than a day and mark
 * anything past its expiry. Cheap, indexed, and safe to call on any request.
 */
function sh_email_challenge_cleanup(int $keepHours = 24): int
{
    try {
        sh_query(
            "UPDATE email_challenges SET status = 'expired' WHERE status = 'pending' AND expires_at < NOW() LIMIT 200"
        );
        $st = sh_query(
            'DELETE FROM email_challenges WHERE created_at < ? AND status <> ? LIMIT 500',
            [date('Y-m-d H:i:s', time() - max(1, $keepHours) * 3600), 'pending']
        );
        return (int)$st->rowCount();
    } catch (Throwable $e) {
        if (function_exists('sh_log_exception')) { sh_log_exception($e, 'email-cleanup'); }
        return 0;
    }
}

// ---------------------------------------------------------------------------
// Templates
// ---------------------------------------------------------------------------

function sh_email_brand(): array
{
    $site = (string)sh_setting('site_name', 'ShopHaat');
    $url = rtrim(sh_site_url(), '/');
    $support = trim((string)sh_setting('smtp_from_email', (string)sh_setting('contact_email', '')));
    return [
        'site'     => $site,
        'url'      => $url,
        'email'    => $support,
        'phone'    => trim((string)sh_setting('contact_phone', '')),
        'accent'   => '#0f766e',
        'ink'      => '#111827',
        'muted'    => '#6b7280',
        'surface'  => '#f3f4f6',
    ];
}

/** Escape a value for HTML email. */
function sh_email_e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Build the multipart message for one email.
 *
 * @param array{subject:string,heading:string,paragraphs?:array<int,string>,code?:string,lines?:array<string,string>,cta?:array{label:string,url:string},note?:string} $o
 * @return array{subject:string,text:string,html:string}
 */
function sh_email_layout(array $o): array
{
    $b = sh_email_brand();
    $heading = (string)$o['heading'];
    $paragraphs = array_values(array_filter(array_map('strval', $o['paragraphs'] ?? []), static fn(string $p): bool => $p !== ''));
    $code = (string)($o['code'] ?? '');
    $lines = $o['lines'] ?? [];
    $cta = $o['cta'] ?? null;
    $note = (string)($o['note'] ?? '');

    // ---- Plain text alternative -------------------------------------------
    $text = $heading . "\n" . str_repeat('=', mb_strlen($heading)) . "\n\n";
    foreach ($paragraphs as $p) { $text .= $p . "\n\n"; }
    if ($code !== '') {
        $text .= 'Code: ' . $code . "\n";
        $text .= '(Enter it on the ' . $b['site'] . ' website. It expires in ' . sh_email_otp_expiry_minutes() . " minutes.)\n\n";
    }
    foreach ($lines as $k => $v) { $text .= $k . ': ' . $v . "\n"; }
    if ($lines !== []) { $text .= "\n"; }
    if (is_array($cta) && !empty($cta['url'])) {
        $text .= (string)$cta['label'] . ': ' . (string)$cta['url'] . "\n\n";
    }
    if ($note !== '') { $text .= $note . "\n\n"; }
    $text .= '-- ' . "\n" . $b['site'] . ' · ' . $b['url'] . "\n";
    if ($b['email'] !== '') { $text .= 'Support: ' . $b['email'] . "\n"; }

    // ---- HTML (table layout + inline CSS for mobile clients) ---------------
    $rows = '';
    foreach ($paragraphs as $p) {
        $rows .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:' . $b['ink'] . '">' . sh_email_e($p) . '</p>';
    }
    if ($code !== '') {
        $rows .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px auto 16px;border-collapse:separate">'
            . '<tr><td style="background:' . $b['surface'] . ';border:1px solid #e5e7eb;border-radius:10px;padding:16px 26px;'
            . 'font-family:Menlo,Consolas,monospace;font-size:30px;letter-spacing:8px;font-weight:700;color:' . $b['ink'] . ';text-align:center">'
            . sh_email_e($code) . '</td></tr></table>'
            . '<p style="margin:0 0 14px;font-size:13px;color:' . $b['muted'] . ';text-align:center">Expires in '
            . sh_email_otp_expiry_minutes() . ' minutes. Never share this code — ' . sh_email_e($b['site'])
            . ' staff will not ask for it.</p>';
    }
    if ($lines !== []) {
        $rows .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 16px;border-collapse:collapse">';
        foreach ($lines as $k => $v) {
            $rows .= '<tr><td style="padding:7px 0;border-bottom:1px solid #eef0f2;font-size:13px;color:' . $b['muted'] . ';width:42%">'
                . sh_email_e((string)$k) . '</td><td style="padding:7px 0;border-bottom:1px solid #eef0f2;font-size:13px;color:' . $b['ink'] . '">'
                . sh_email_e((string)$v) . '</td></tr>';
        }
        $rows .= '</table>';
    }
    if (is_array($cta) && !empty($cta['url'])) {
        $rows .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:4px 0 16px"><tr>'
            . '<td style="border-radius:8px;background:' . $b['accent'] . '">'
            . '<a href="' . sh_email_e((string)$cta['url']) . '" style="display:inline-block;padding:12px 22px;font-size:15px;'
            . 'font-weight:600;color:#ffffff;text-decoration:none">' . sh_email_e((string)$cta['label']) . '</a></td></tr></table>';
    }
    if ($note !== '') {
        $rows .= '<p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:' . $b['muted'] . '">' . sh_email_e($note) . '</p>';
    }

    $footerBits = [];
    if ($b['email'] !== '') { $footerBits[] = 'Support: ' . $b['email']; }
    if ($b['phone'] !== '') { $footerBits[] = 'Phone: ' . $b['phone']; }

    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light">'
        . '<title>' . sh_email_e((string)$o['subject']) . '</title></head>'
        . '<body style="margin:0;padding:0;background:' . $b['surface'] . '">'
        . '<div style="display:none;font-size:1px;color:' . $b['surface'] . ';max-height:0;overflow:hidden">'
        . sh_email_e($paragraphs[0] ?? $heading) . '</div>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:' . $b['surface'] . ';padding:24px 12px">'
        . '<tr><td align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px;background:#ffffff;'
        . 'border-radius:14px;overflow:hidden;border:1px solid #e5e7eb">'
        . '<tr><td style="padding:20px 26px;border-bottom:1px solid #eef0f2">'
        . '<a href="' . sh_email_e($b['url']) . '" style="font-size:17px;font-weight:700;color:' . $b['accent'] . ';text-decoration:none">'
        . sh_email_e($b['site']) . '</a></td></tr>'
        . '<tr><td style="padding:26px">'
        . '<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;color:' . $b['ink'] . '">' . sh_email_e($heading) . '</h1>'
        . $rows
        . '</td></tr>'
        . '<tr><td style="padding:16px 26px;background:#fafafa;border-top:1px solid #eef0f2;font-size:12px;line-height:1.7;color:' . $b['muted'] . '">'
        . sh_email_e($b['site']) . ' · <a href="' . sh_email_e($b['url']) . '" style="color:' . $b['muted'] . '">'
        . sh_email_e(preg_replace('~^https?://~', '', $b['url'])) . '</a>'
        . ($footerBits === [] ? '' : '<br>' . sh_email_e(implode(' · ', $footerBits)))
        . '<br>This message was sent by the website itself. Do not reply with a password or a verification code.'
        . '</td></tr></table></td></tr></table></body></html>';

    return ['subject' => (string)$o['subject'], 'text' => $text, 'html' => $html];
}

// ---------------------------------------------------------------------------
// Senders — one per authentication email, all through sh_mail_send()
// ---------------------------------------------------------------------------

/**
 * Send one authentication email and report exactly what the transport said.
 *
 * @param array{purpose:string,user_id?:int,lines?:array<string,string>,cta?:array{label:string,url:string},note?:string} $o
 * @return array<string,mixed> the transport result plus subject/sent flags
 */
function sh_email_dispatch(string $to, string $purpose, array $message, int $userId = 0): array
{
    $result = sh_mail_send(
        $to,
        (string)$message['subject'],
        (string)$message['text'],
        (string)$message['html'],
        ['log_purpose' => $purpose, 'log_user_id' => $userId]
    );
    $result['subject'] = (string)$message['subject'];
    $result['purpose'] = $purpose;
    return $result;
}

/**
 * Issue and email a signup verification code.
 *
 * @return array{ok:bool,sent:bool,error?:string,state?:string,challenge_id?:int,wait?:int,transport?:string}
 */
function sh_email_send_verification_code(string $email, ?int $userId = null, array $ctx = []): array
{
    sh_email_schema_ensure();
    $issue = sh_email_challenge_issue($email, SH_EMAIL_PURPOSE_SIGNUP, $userId, $ctx);
    if (empty($issue['ok'])) {
        return ['ok' => false, 'sent' => false, 'error' => (string)($issue['error'] ?? 'A code could not be created.'), 'wait' => (int)($issue['wait'] ?? 0)];
    }
    $b = sh_email_brand();
    $message = sh_email_layout([
        'subject'    => 'Verify your ' . $b['site'] . ' email',
        'heading'    => 'Confirm your email address',
        'paragraphs' => [
            'Use this six-digit verification code to complete your ' . $b['site'] . ' account verification.',
            'This code expires in ' . (int)sh_email_otp_expiry_minutes() . ' minutes.',
        ],
        'code'       => (string)$issue['code'],
        'note'       => 'For your security, never share this verification code with anyone. ' . $b['site'] . ' staff will never ask for your code. If you did not create an account, you can safely ignore this email.',
    ]);
    $result = sh_email_dispatch($email, SH_EMAIL_PURPOSE_SIGNUP, $message, (int)($userId ?? 0));
    sh_email_challenge_sent((int)$issue['challenge_id'], $result);

    return [
        'ok'           => !empty($result['ok']),
        'sent'         => !empty($result['ok']),
        'error'        => empty($result['ok']) ? (string)($result['error'] ?? 'The verification email could not be sent.') : null,
        'state'        => empty($result['ok']) ? (string)($result['category'] ?? 'failed') : 'sent',
        'challenge_id' => (int)$issue['challenge_id'],
        'transport'    => (string)($result['transport'] ?? ''),
    ];
}

/**
 * Issue and email a password-reset code.
 *
 * @return array{ok:bool,sent:bool,error?:string,state?:string,challenge_id?:int}
 */
function sh_email_send_reset_code(string $email, ?int $userId): array
{
    sh_email_schema_ensure();
    $purpose = SH_EMAIL_PURPOSE_RESET;
    $issue = sh_email_challenge_issue($email, $purpose, $userId);
    if (empty($issue['ok'])) {
        return ['ok' => false, 'sent' => false, 'error' => (string)($issue['error'] ?? 'A code could not be created.'), 'wait' => (int)($issue['wait'] ?? 0)];
    }
    $b = sh_email_brand();
    $message = sh_email_layout([
        'subject'    => 'Your ' . $b['site'] . ' password reset code',
        'heading'    => 'Reset your password',
        'paragraphs' => [
            'Enter this code on the reset page to choose a new password.',
        ],
        'code'       => (string)$issue['code'],
        'note'       => 'If you did not ask to reset your password, ignore this email and consider changing your password — your current one still works and nobody has been given access.',
    ]);
    $result = sh_email_dispatch($email, $purpose, $message, (int)($userId ?? 0));
    sh_email_challenge_sent((int)$issue['challenge_id'], $result);

    return [
        'ok'           => !empty($result['ok']),
        'sent'         => !empty($result['ok']),
        'error'        => empty($result['ok']) ? (string)($result['error'] ?? 'The reset email could not be sent.') : null,
        'state'        => empty($result['ok']) ? (string)($result['category'] ?? 'failed') : 'sent',
        'challenge_id' => (int)$issue['challenge_id'],
    ];
}

/** Code for a sensitive change (password change) when the operator asks for it. */
function sh_email_send_change_code(string $email, ?int $userId): array
{
    sh_email_schema_ensure();
    $issue = sh_email_challenge_issue($email, SH_EMAIL_PURPOSE_CHANGE, $userId, []);
    if (empty($issue['ok'])) {
        return ['ok' => false, 'sent' => false, 'error' => (string)($issue['error'] ?? 'A code could not be created.')];
    }
    $b = sh_email_brand();
    $message = sh_email_layout([
        'subject'    => 'Confirm your ' . $b['site'] . ' password change',
        'heading'    => 'Confirm a security change',
        'paragraphs' => ['Enter this code to confirm the password change on your account.'],
        'code'       => (string)$issue['code'],
        'note'       => 'If this was not you, do not enter the code and contact support immediately.',
    ]);
    $result = sh_email_dispatch($email, SH_EMAIL_PURPOSE_CHANGE, $message, (int)($userId ?? 0));
    sh_email_challenge_sent((int)$issue['challenge_id'], $result);
    return ['ok' => !empty($result['ok']), 'sent' => !empty($result['ok']), 'error' => empty($result['ok']) ? (string)($result['error'] ?? '') : null];
}

/**
 * Real notification after a password change (reset or account page).
 *
 * The password has already changed when this runs, so a delivery failure must
 * not be reported as success: the result is returned, logged, and the admin
 * delivery log offers a retry that re-sends this same notification.
 */
function sh_email_notify_password_changed(array $user, string $via = 'account'): array
{
    sh_email_schema_ensure();
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '' || !sh_valid_email($email) || function_exists('sh_is_synthetic_email') && sh_is_synthetic_email($email)) {
        return ['ok' => false, 'skipped' => true, 'error' => 'No real email address on the account.'];
    }
    if (sh_setting('email_notify_password_change', '1') !== '1') {
        return ['ok' => false, 'skipped' => true, 'error' => 'Password-change notifications are switched off.'];
    }
    $b = sh_email_brand();
    $how = $via === 'reset' ? 'through the password reset page' : 'from your account settings';
    $message = sh_email_layout([
        'subject'    => 'Your ' . $b['site'] . ' password was changed',
        'heading'    => 'Your password was changed',
        'paragraphs' => [
            'The password for your ' . $b['site'] . ' account was changed ' . $how . '.',
        ],
        'lines'      => [
            'When' => date('d M Y, H:i') . ' (server time)',
            'Where' => (function_exists('sh_client_ip') ? mb_substr((string)sh_client_ip(), 0, 45) : 'unknown') . ' — IP address recorded in your security log',
        ],
        'cta'        => ['label' => 'Review your account', 'url' => rtrim(sh_site_url(), '/') . sh_url('account.php')],
        'note'       => 'If this was not you, reset your password immediately and contact ' . ($b['email'] !== '' ? $b['email'] : 'our support team')
            . '. Nothing tells us who made the change beyond the record above.',
    ]);
    return sh_email_dispatch($email, 'password_changed', $message, (int)($user['id'] ?? 0));
}

/** A generic, safe security notice (new device, admin action, etc.). */
function sh_email_notify_security(array $user, string $title, string $body): array
{
    sh_email_schema_ensure();
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '' || !sh_valid_email($email)) {
        return ['ok' => false, 'skipped' => true, 'error' => 'No real email address on the account.'];
    }
    $b = sh_email_brand();
    $message = sh_email_layout([
        'subject'    => $title,
        'heading'    => $title,
        'paragraphs' => [$body],
        'cta'        => ['label' => 'Open your account', 'url' => rtrim(sh_site_url(), '/') . sh_url('account.php')],
    ]);
    return sh_email_dispatch($email, 'security_notice', $message, (int)($user['id'] ?? 0));
}

/** Mark an account's email as verified. Idempotent; logs the method used. */
function sh_email_mark_verified(int $userId, string $via): void
{
    sh_email_schema_ensure();
    sh_query(
        'UPDATE users SET email_verified = 1, email_verified_at = COALESCE(email_verified_at, NOW())
         WHERE id = ? AND email_verified = 0',
        [$userId]
    );
    if (function_exists('sh_security_log')) { sh_security_log('email_verified', $userId, ['via' => $via]); }
}

function sh_email_user_verified(?array $user): bool
{
    return $user !== null && !empty($user['email_verified']);
}

/**
 * The one gate used by checkout and (optionally) sign-in.
 *
 * Returns a message when the visitor must verify first, null when they may
 * continue. The Firebase verifier keeps working, so a store that already uses
 * Firebase is not left without a gate.
 *
 * @param string $context 'checkout' (setting email_verification_required) or
 *                        'login' (setting email_verify_required_login)
 */
function sh_email_gate_blocked(?array $user, string $context = 'checkout'): ?string
{
    if ($user === null) { return null; }
    if (sh_auth_mode() !== 'email_password') { return null; }
    $key = $context === 'login' ? 'email_verify_required_login' : 'email_verification_required';
    if (sh_setting($key, '0') !== '1') { return null; }

    $email = trim((string)($user['email'] ?? ''));
    if ($email === '' || !sh_valid_email($email) || (function_exists('sh_is_synthetic_email') && sh_is_synthetic_email($email))) {
        // Phone-only accounts have no inbox to verify; the phone OTP is their gate.
        return null;
    }
    if (!empty($user['email_verified'])) { return null; }

    return sh_email_verifier() === 'none'
        ? 'Email verification is switched on, but this store cannot send email yet, so verification cannot be completed. Please contact support.'
        : 'Please confirm your email address before continuing.';
}

/** Where an unverified customer is sent to enter the code (the page validates first). */
function sh_email_verify_url(): string
{
    return sh_url('verify-email.php');
}

// ---------------------------------------------------------------------------
// Pending verification state (signup and the optional sign-in gate)
// ---------------------------------------------------------------------------

/**
 * The signup/login flow that is waiting for a code.
 *
 * Stored in the session only, like the phone OTP pending state: it holds the
 * account, where to continue, and why the customer was stopped. It is never a
 * credential — completing it still requires a code that was emailed to the
 * address, and it expires after 30 minutes.
 */
function sh_email_verify_pending(): ?array
{
    $state = $_SESSION['email_verify_pending'] ?? null;
    if (!is_array($state) || (int)($state['user_id'] ?? 0) <= 0 || empty($state['email'])) { return null; }
    if (time() - (int)($state['at'] ?? 0) > 1800) {
        unset($_SESSION['email_verify_pending']);
        return null;
    }
    return $state;
}

/** @param array<string,mixed> $user */
function sh_email_verify_pending_set(array $user, string $return = 'account.php', string $context = 'login'): void
{
    $_SESSION['email_verify_pending'] = [
        'user_id' => (int)($user['id'] ?? 0),
        'email'   => trim((string)($user['email'] ?? '')),
        'return'  => $return,
        'context' => in_array($context, ['signup', 'login', 'account'], true) ? $context : 'login',
        'at'      => time(),
    ];
}

function sh_email_verify_pending_clear(): void
{
    unset($_SESSION['email_verify_pending']);
}

// ---------------------------------------------------------------------------
// Delivery log + provider events
// ---------------------------------------------------------------------------

/** @return array<int,array<string,mixed>> */
function sh_email_log_recent(int $limit = 50, string $status = ''): array
{
    sh_email_schema_ensure();
    $limit = max(1, min(200, $limit));
    if ($status !== '' && in_array($status, ['accepted', 'failed', 'skipped', 'delivered', 'bounced', 'complaint', 'blocked', 'unsubscribed'], true)) {
        return sh_all('SELECT * FROM email_log WHERE status = ? ORDER BY id DESC LIMIT ' . (int)$limit, [$status]);
    }
    return sh_all('SELECT * FROM email_log ORDER BY id DESC LIMIT ' . (int)$limit);
}

/** @return array{total:int,accepted:int,failed:int,skipped:int,delivered:int,bounced:int,complaint:int} */
function sh_email_log_stats(int $days = 7): array
{
    sh_email_schema_ensure();
    $since = date('Y-m-d H:i:s', time() - max(1, $days) * 86400);
    $row = sh_one(
        'SELECT COUNT(*) total,
                SUM(status = "accepted") accepted,
                SUM(status = "failed") failed,
                SUM(status = "skipped") skipped,
                SUM(status = "delivered") delivered,
                SUM(status = "bounced") bounced,
                SUM(status = "complaint") complaint
         FROM email_log WHERE created_at > ?',
        [$since]
    );
    return [
        'total'     => (int)($row['total'] ?? 0),
        'accepted'  => (int)($row['accepted'] ?? 0),
        'failed'    => (int)($row['failed'] ?? 0),
        'skipped'   => (int)($row['skipped'] ?? 0),
        'delivered' => (int)($row['delivered'] ?? 0),
        'bounced'   => (int)($row['bounced'] ?? 0),
        'complaint' => (int)($row['complaint'] ?? 0),
    ];
}

function sh_email_webhook_secret(): string
{
    return trim((string)sh_setting('email_webhook_secret', ''));
}

/**
 * Verify an incoming provider webhook before anything is read from it.
 *
 * Two accepted proofs, because providers differ:
 *   - a shared secret in the URL (constant-time compare), and/or
 *   - an HMAC-SHA256 of the raw body in the signature header.
 * No secret configured means no webhook is accepted at all.
 *
 * @return array{ok:bool,error?:string}
 */
function sh_email_webhook_verify(string $rawBody, array $headers, array $query): array
{
    $secret = sh_email_webhook_secret();
    if ($secret === '' || strlen($secret) < 24) {
        return ['ok' => false, 'error' => 'No webhook secret is configured.'];
    }
    $offered = (string)($query['key'] ?? $query['secret'] ?? '');
    $sig = (string)($headers['x-shophaat-signature'] ?? $headers['x-mailin-signature'] ?? $headers['x-signature'] ?? '');
    $sig = trim((string)preg_replace('~^sha256=~i', '', $sig));

    $secretOk = $offered !== '' && hash_equals($secret, $offered);
    $sigOk = false;
    if ($sig !== '' && preg_match('~^[0-9a-f]{64}$~i', $sig) === 1) {
        $sigOk = hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($sig));
    }
    if ($secretOk || $sigOk) { return ['ok' => true]; }
    return ['ok' => false, 'error' => 'The webhook signature did not validate.'];
}

/**
 * Apply a provider event to the delivery log.
 *
 * Accepts Brevo-style webhook payloads (event + message-id + email) and the
 * generic shape used by other providers. Events are recorded against the
 * message id when one is present, otherwise against the newest accepted message
 * for that address. Idempotent: replaying the same event id changes nothing.
 *
 * @param array<string,mixed> $payload decoded JSON body
 * @return array{ok:bool,applied:int,status:string,error?:string}
 */
function sh_email_event_apply(array $payload, string $eventId = ''): array
{
    sh_email_schema_ensure();
    $map = [
        'delivered'     => 'delivered',
        'soft_bounce'   => 'bounced',
        'hard_bounce'   => 'bounced',
        'bounce'        => 'bounced',
        'blocked'       => 'blocked',
        'spam'          => 'complaint',
        'complaint'     => 'complaint',
        'unsubscribed'  => 'unsubscribed',
        'error'         => 'failed',
        'deferred'      => 'accepted',
        'request'       => 'accepted',
        'opened'        => 'delivered',
        'click'         => 'delivered',
    ];
    $raw = strtolower(trim((string)($payload['event'] ?? $payload['type'] ?? $payload['Event'] ?? '')));
    if ($raw === '' || !isset($map[$raw])) {
        return ['ok' => false, 'applied' => 0, 'status' => '', 'error' => 'Unsupported event type.'];
    }
    $status = $map[$raw];
    $messageId = trim((string)($payload['message-id'] ?? $payload['message_id'] ?? $payload['MessageID'] ?? ''));
    $email = mb_strtolower(trim((string)($payload['email'] ?? $payload['recipient'] ?? '')));
    $eventId = $eventId !== '' ? $eventId : trim((string)($payload['event_id'] ?? $payload['id'] ?? ''));

    $row = null;
    if ($messageId !== '') {
        $row = sh_one('SELECT id FROM email_log WHERE message_id = ? ORDER BY id DESC LIMIT 1', [mb_substr($messageId, 0, 190)]);
    }
    if ($row === null && $email !== '' && sh_valid_email($email)) {
        $row = sh_one('SELECT id FROM email_log WHERE recipient = ? ORDER BY id DESC LIMIT 1', [$email]);
    }
    if ($row === null) {
        return ['ok' => true, 'applied' => 0, 'status' => $status];
    }
    $id = (int)$row['id'];
    if ($eventId !== '') {
        $seen = (int)sh_val('SELECT COUNT(*) FROM email_log WHERE provider_event_id = ?', [mb_substr($eventId, 0, 120)], 0);
        if ($seen > 0) { return ['ok' => true, 'applied' => 0, 'status' => $status]; }
    }
    sh_update('email_log', [
        'status'           => $status,
        'category'         => mb_substr($raw, 0, 30),
        'provider_event_id'=> $eventId !== '' ? mb_substr($eventId, 0, 120) : null,
        'delivered_at'     => in_array($status, ['delivered', 'complaint'], true) ? date('Y-m-d H:i:s') : null,
        'error_text'       => $status === 'bounced' ? sh_mail_redact(mb_substr((string)($payload['reason'] ?? 'Bounced by the receiving server.'), 0, 400)) : null,
    ], 'id = ?', [$id]);

    return ['ok' => true, 'applied' => 1, 'status' => $status];
}

/**
 * Re-send a failed authentication notification.
 *
 * Only notifications whose text is derived from the account (never a stored
 * body, and never a code) can be retried: a verification or reset code is
 * retried by issuing a fresh one, which is what the customer-facing resend
 * button does.
 */
function sh_email_retry_notification(int $logId): array
{
    sh_email_schema_ensure();
    $row = sh_one('SELECT * FROM email_log WHERE id = ?', [$logId]);
    if ($row === null) { return ['ok' => false, 'error' => 'That log entry no longer exists.']; }
    if (!in_array((string)$row['purpose'], ['password_changed', 'security_notice'], true)) {
        return ['ok' => false, 'error' => 'Only password-change and security notifications can be re-sent. Issue a fresh code instead.'];
    }
    $email = (string)$row['recipient'];
    $user = sh_one('SELECT id, name, email FROM users WHERE email = ? LIMIT 1', [$email]);
    if ($user === null) { return ['ok' => false, 'error' => 'The account for that address no longer exists.']; }
    return (string)$row['purpose'] === 'password_changed'
        ? sh_email_notify_password_changed($user, 'retry')
        : sh_email_notify_security($user, 'Security notice re-sent', 'An administrator re-sent this security notice for your account.');
}
