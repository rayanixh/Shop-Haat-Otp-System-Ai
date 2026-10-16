<?php
/**
 * ShopHaat mail transport — one reusable sender for every email the site sends.
 *
 * Delivery runs through PHPMailer (Composer's copy when the host has one, the
 * vendored copy under lib/PHPMailer otherwise) over authenticated SMTP. PHP's
 * mail() is used only when an operator has deliberately left SMTP switched off,
 * which is what most shared cPanel accounts do.
 *
 * Rules this file follows, because getting them wrong makes the UI lie:
 *  - A message is only reported as ok when the transport accepted the
 *    submission. Missing configuration is "skipped", a rejection is "failed" —
 *    never a fake success.
 *  - A successful submission is *not* proof of inbox delivery. Delivery,
 *    bounce and complaint states only arrive through the provider webhook
 *    (api/email-events.php) and are recorded there.
 *  - Credentials are read from the settings table server-side and are never
 *    rendered, logged, returned in an API response or written into a transcript.
 *  - Every attempt is logged to email_log with its error category, without the
 *    message body, without a verification code and without any secret.
 *
 * Settings used: smtp_enabled, smtp_host, smtp_port, smtp_encryption,
 * smtp_username, smtp_password, smtp_from_email, smtp_from_name,
 * smtp_allow_self_signed, dkim_domain, dkim_selector, dkim_private_key_path.
 */
declare(strict_types=1);

require_once SH_ROOT . '/lib/PHPMailer/autoload.php';

/** Whether the mail library is actually loadable on this server. */
function sh_phpmailer_available(): bool
{
    try {
        return class_exists(\PHPMailer\PHPMailer\PHPMailer::class);
    } catch (Throwable $e) {
        return false;
    }
}

/** Where the loaded library came from, for the admin status panel. */
function sh_phpmailer_source(): string
{
    if (!sh_phpmailer_available()) { return 'missing'; }
    return is_file(SH_ROOT . '/vendor/phpmailer/phpmailer/src/PHPMailer.php') ? 'composer' : 'vendored';
}

/**
 * Raw transport configuration. Contains the SMTP password: never render, log or
 * return this array — use sh_mail_service_status() for anything user-facing.
 *
 * @return array<string,mixed>
 */
function sh_mail_transport(): array
{
    $fromEmail = trim((string)sh_setting('smtp_from_email', (string)sh_setting('contact_email', '')));
    $fromName  = trim((string)sh_setting('smtp_from_name', (string)sh_setting('site_name', 'ShopHaat')));
    $enabled   = (string)sh_setting('smtp_enabled', '0') === '1';
    $host      = trim((string)sh_setting('smtp_host', ''));
    $port      = (int)sh_setting('smtp_port', '587');
    $enc       = strtolower((string)sh_setting('smtp_encryption', 'tls'));

    return [
        'driver'      => ($enabled && $host !== '') ? 'smtp' : 'mail',
        'host'        => $host,
        'port'        => ($port > 0 && $port <= 65535) ? $port : (($enc === 'ssl') ? 465 : 587),
        'encryption'  => in_array($enc, ['tls', 'ssl', 'none'], true) ? $enc : 'tls',
        'username'    => trim((string)sh_setting('smtp_username', '')),
        'password'    => (string)sh_setting('smtp_password', ''),
        'from_email'  => $fromEmail,
        'from_name'   => $fromName !== '' ? $fromName : 'ShopHaat',
        'allow_self_signed' => (string)sh_setting('smtp_allow_self_signed', '0') === '1',
        'dkim_enabled' => (string)sh_setting('dkim_enabled', '0') === '1',
        'timeout'     => 20,
    ];
}

/**
 * Transport settings safe to show an operator: no password, no username, only
 * the pieces that help diagnose a configuration problem.
 *
 * @return array<string,mixed>
 */
function sh_mail_service_status(): array
{
    $t = sh_mail_transport();
    $fromOk = $t['from_email'] !== '' && sh_valid_email((string)$t['from_email']);
    // A password is only required when the host authenticates with a username:
    // a local relay that accepts unauthenticated mail is a real setup, and
    // blocking it would be a lie about what the store can do.
    $authOk = $t['username'] === '' || $t['password'] !== '';
    $ready = $fromOk && ($t['driver'] === 'smtp' ? ($t['host'] !== '' && $authOk) : true);
    $missing = [];
    if (!$fromOk) { $missing[] = 'A valid sender email address (Sender email)'; }
    if ($t['driver'] === 'smtp' && $t['host'] === '') { $missing[] = 'SMTP host'; }
    if ($t['driver'] === 'smtp' && $t['username'] !== '' && $t['password'] === '') { $missing[] = 'SMTP password'; }

    return [
        'ready'         => $ready,
        'driver'        => (string)$t['driver'],
        'library'       => sh_phpmailer_source(),
        'host'          => (string)$t['host'],
        'port'          => (int)$t['port'],
        'encryption'    => (string)$t['encryption'],
        'username_set'  => $t['username'] !== '',
        'password_set'  => $t['password'] !== '',
        'from_email'    => (string)$t['from_email'],
        'from_name'     => (string)$t['from_name'],
        'dkim'          => sh_mail_dkim_status(),
        'missing'       => $missing,
    ];
}

/**
 * DKIM signing configuration, when the operator has supplied a private key.
 *
 * DNS is not something PHP can publish: this only signs the outgoing message so
 * the DNS record the provider issued can validate it. The key file has to be a
 * real readable file that is not inside the public upload tree.
 *
 * @return array{ready:bool,domain:string,selector:string,path:string,reason:string}
 */
function sh_mail_dkim_status(): array
{
    $domain   = trim((string)sh_setting('dkim_domain', ''));
    $selector = trim((string)sh_setting('dkim_selector', ''));
    $path     = trim((string)sh_setting('dkim_private_key_path', ''));
    $out = ['ready' => false, 'domain' => $domain, 'selector' => $selector, 'path' => $path, 'reason' => ''];

    if ($domain === '' || $selector === '' || $path === '') {
        $out['reason'] = 'No DKIM key configured. The provider issues the record and the key; paste both here to sign outgoing mail.';
        return $out;
    }
    if (preg_match('~^[A-Za-z0-9.\-]{3,190}$~', $domain) !== 1) { $out['reason'] = 'The DKIM domain is not a valid domain name.'; return $out; }
    if (preg_match('~^[A-Za-z0-9._\-]{1,63}$~', $selector) !== 1) { $out['reason'] = 'The DKIM selector is not valid.'; return $out; }
    if (str_contains($path, "\0") || preg_match('~^[A-Za-z]:[\\\\/]|^/~', $path) !== 1) {
        $out['reason'] = 'Use the absolute server path of the private key file.';
        return $out;
    }
    // A key inside the public upload folders would be downloadable. Refuse it.
    if (str_contains(str_replace('\\', '/', $path), '/uploads/')) {
        $out['reason'] = 'The key file must live outside the public uploads folder.';
        return $out;
    }
    if (!is_file($path) || !is_readable($path)) { $out['reason'] = 'The private key file is not readable by PHP.'; return $out; }
    $size = (int)@filesize($path);
    if ($size < 200 || $size > 20000) { $out['reason'] = 'The private key file does not look like a DKIM key (size ' . $size . ' bytes).'; return $out; }

    $out['ready'] = true;
    $out['reason'] = 'Outgoing mail is signed with selector "' . $selector . '". The matching DNS record must be published by your provider.';
    return $out;
}

/** Redact a raw transport error/transcript before it is stored or displayed. */
function sh_mail_redact(string $text): string
{
    $text = function_exists('sh_scrub_secrets') ? sh_scrub_secrets($text) : $text;
    // SMTP AUTH exchanges are base64: redact the whole exchange, not the words.
    $text = preg_replace('~(?<=AUTH LOGIN\r\n)[A-Za-z0-9+/=]+~', '[redacted]', $text) ?? $text;
    $text = preg_replace('~\b(?:[A-Za-z0-9+/=]{24,})~', '[redacted]', $text) ?? $text;
    return trim($text);
}

/** Map a raw provider/SMTP error onto a stable category for the log and the UI. */
function sh_mail_category(string $raw): string
{
    $r = strtolower($raw);
    if ($r === '') { return 'ok'; }
    $code = 0;
    if (preg_match('~\b([245]\d\d)\b~', $r, $m) === 1) { $code = (int)$m[1]; }
    if (str_contains($r, 'could not authenticate') || str_contains($r, 'auth') && $code === 535) { return 'auth'; }
    if ($code === 535 || $code === 534 || $code === 530) { return 'auth'; }
    if ($code === 550 || $code === 551 || $code === 553 || $code === 554 || str_contains($r, 'reject')) { return 'rejected'; }
    if ($code === 421 || $code === 450 || $code === 451 || $code === 452 || str_contains($r, 'throttl') || str_contains($r, 'rate limit')) { return 'throttled'; }
    if ($code >= 400 && $code < 500) { return 'temporary'; }
    if ($code >= 500) { return 'permanent'; }
    // Transport-level failures read very differently between PHP versions and
    // providers, so every wording that means "we never reached the server" maps
    // to the same category instead of falling through to "unknown".
    foreach (['could not connect', 'failed to connect', 'connection refused', 'connection reset',
              'timeout', 'timed out', 'unreachable', 'network is unreachable', 'getaddrinfo',
              'name or service not known', 'no route to host', 'broken pipe', 'stream_socket_client'] as $needle) {
        if (str_contains($r, $needle)) { return 'connection'; }
    }
    if (str_contains($r, 'tls') || str_contains($r, 'ssl') || str_contains($r, 'certificate')) { return 'tls'; }
    if (str_contains($r, 'not configured') || str_contains($r, 'missing')) { return 'not_configured'; }
    if (str_contains($r, 'invalid recipient')) { return 'invalid_recipient'; }
    return 'unknown';
}

/**
 * Send one message.
 *
 * @param array<string,mixed> $opts log_user_id, log_purpose, transport (test
 *                                  injection), reply_to, debug (capture a
 *                                  transcript for the admin test screen), tags
 * @return array{ok:bool,error?:string,skipped?:bool,recipient:string,transport?:string,category?:string,message_id?:string,response?:string,transcript?:string}
 */
function sh_mail_send(string $to, string $subject, string $textBody, ?string $htmlBody = null, array $opts = []): array
{
    $to = trim($to);
    $result = ['ok' => false, 'recipient' => $to, 'transport' => 'none', 'category' => 'unknown'];

    if (!sh_valid_email($to)) {
        $result['error'] = 'Invalid recipient address.';
        $result['category'] = 'invalid_recipient';
        sh_email_delivery_log($result + ['subject' => $subject, 'opts' => $opts]);
        return $result;
    }

    /** @var array<string,mixed> $t */
    $t = isset($opts['transport']) && is_array($opts['transport'])
        ? array_merge(sh_mail_transport(), $opts['transport'])
        : sh_mail_transport();
    $result['transport'] = (string)($t['driver'] ?? 'mail');

    $fromEmail = trim((string)($t['from_email'] ?? ''));
    $fromName  = trim((string)($t['from_name'] ?? 'ShopHaat'));
    if ($fromEmail === '' || !sh_valid_email($fromEmail)) {
        $result['skipped'] = true;
        $result['error'] = 'No valid sender email address is configured.';
        $result['category'] = 'not_configured';
        sh_email_delivery_log($result + ['subject' => $subject, 'opts' => $opts]);
        return $result;
    }
    if (($t['driver'] ?? 'mail') === 'smtp' && trim((string)($t['host'] ?? '')) === '') {
        $result['skipped'] = true;
        $result['error'] = 'SMTP is enabled but no SMTP host is configured.';
        $result['category'] = 'not_configured';
        sh_email_delivery_log($result + ['subject' => $subject, 'opts' => $opts]);
        return $result;
    }

    $transcript = [];
    // The library is shipped with the application; if it is genuinely missing we
    // fall back to PHP mail() rather than pretending the message was sent.
    if (!sh_phpmailer_available()) {
        $legacy = sh_mail_send_legacy($to, $subject, $textBody, $htmlBody, $fromEmail, $fromName);
        sh_email_delivery_log($legacy + ['subject' => $subject, 'opts' => $opts]);
        return $legacy;
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->XMailer = 'ShopHaat'; // do not advertise the library version
        $mail->Timeout = (int)($t['timeout'] ?? 20);

        if (($t['driver'] ?? 'mail') === 'smtp') {
            $mail->isSMTP();
            $mail->Host = (string)$t['host'];
            $mail->Port = (int)($t['port'] ?? 587);
            $username = (string)($t['username'] ?? '');
            $mail->SMTPAuth = $username !== '';
            $mail->Username = $username;
            $mail->Password = (string)($t['password'] ?? '');
            $enc = strtolower((string)($t['encryption'] ?? 'tls'));
            if ($enc === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                $mail->SMTPAutoTLS = false;
            } elseif ($enc === 'none') {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            } else {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->SMTPAutoTLS = true;
            }
            $allowSelfSigned = !empty($t['allow_self_signed']);
            $mail->SMTPOptions = ['ssl' => [
                'verify_peer'       => !$allowSelfSigned,
                'verify_peer_name'  => !$allowSelfSigned,
                'allow_self_signed' => $allowSelfSigned,
                'SNI_enabled'       => true,
            ]];
            $mail->SMTPDebug = !empty($opts['debug']) ? \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER : \PHPMailer\PHPMailer\SMTP::DEBUG_OFF;
            $mail->Debugoutput = function ($str, $level) use (&$transcript): void {
                if (count($transcript) < 120) { $transcript[] = (string)$str; }
            };
        } else {
            $mail->isMail();
        }

        $mail->setFrom($fromEmail, $fromName);
        $replyTo = trim((string)($opts['reply_to'] ?? ''));
        $mail->addReplyTo($replyTo !== '' && sh_valid_email($replyTo) ? $replyTo : $fromEmail, $fromName);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        if ($htmlBody !== null && $htmlBody !== '') {
            $mail->isHTML(true);
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;
        } else {
            $mail->isHTML(false);
            $mail->Body = $textBody;
        }

        $dkim = sh_mail_dkim_status();
        if ($dkim['ready'] && !empty($t['dkim_enabled'])) {
            $mail->DKIM_domain = $dkim['domain'];
            $mail->DKIM_selector = $dkim['selector'];
            $mail->DKIM_private = $dkim['path'];
            $mail->DKIM_identity = $fromEmail;
            $mail->DKIM_passphrase = '';
            $mail->DKIM_copyHeaderFields = true;
        }

        $ok = $mail->send();
        $result['ok'] = (bool)$ok;
        $result['message_id'] = (string)$mail->getLastMessageID();
        if ($ok) {
            $result['category'] = 'accepted';
            $result['response'] = 'Accepted by ' . ($result['transport'] === 'smtp' ? 'the SMTP server' : 'the local mail transport') . '.';
        } else {
            $raw = trim((string)$mail->ErrorInfo);
            $smtpErr = '';
            try { $smtpErr = trim((string)$mail->getSMTPInstance()->getError()); } catch (Throwable $e) { $smtpErr = ''; }
            $raw = sh_mail_redact($raw . ' ' . $smtpErr);
            $result['error'] = $raw !== '' ? $raw : 'The mail transport rejected the message.';
            $result['category'] = sh_mail_category($raw !== '' ? $raw : 'unknown');
        }
    } catch (Throwable $e) {
        $raw = sh_mail_redact($e->getMessage());
        $result['error'] = $raw !== '' ? $raw : 'The mail transport raised an error.';
        $result['category'] = sh_mail_category($raw);
    }

    if (!empty($opts['debug'])) {
        $result['transcript'] = sh_mail_redact(implode("\n", $transcript));
    }
    sh_email_delivery_log($result + ['subject' => $subject, 'opts' => $opts]);
    return $result;
}

/**
 * Fallback used only when the PHPMailer files are missing from the deployment.
 * Keeps a shared host able to email at all instead of failing every send.
 *
 * @return array{ok:bool,error?:string,skipped?:bool,recipient:string,transport:string,category:string}
 */
function sh_mail_send_legacy(string $to, string $subject, string $textBody, ?string $htmlBody, string $fromEmail, string $fromName): array
{
    $out = ['ok' => false, 'recipient' => $to, 'transport' => 'mail', 'category' => 'unknown'];
    if (!function_exists('mail')) {
        $out['skipped'] = true;
        $out['error'] = 'The mail library (lib/PHPMailer) is missing and PHP mail() is unavailable. Re-upload lib/PHPMailer or install phpmailer/phpmailer with Composer.';
        $out['category'] = 'not_configured';
        return $out;
    }
    $boundary = 'sh' . bin2hex(random_bytes(10));
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . sh_mail_encode_name($fromName) . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'X-Mailer: ShopHaat',
    ];
    if ($htmlBody !== null && $htmlBody !== '') {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = sh_mail_multipart($boundary, $textBody, $htmlBody);
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $body = $textBody;
    }
    $warning = '';
    set_error_handler(static function (int $no, string $msg) use (&$warning): bool { $warning = $msg; return true; });
    try {
        $ok = mail($to, sh_mail_encode_name($subject), $body, implode("\r\n", $headers));
    } finally {
        restore_error_handler();
    }
    if ($ok) {
        $out['ok'] = true;
        $out['category'] = 'accepted';
        $out['response'] = 'Accepted by the local mail transport.';
    } else {
        $out['error'] = sh_mail_redact($warning !== '' ? $warning : 'PHP mail() returned failure.');
        $out['category'] = 'unknown';
    }
    return $out;
}

function sh_mail_encode_name(string $v): string
{
    return preg_match('/[^\x20-\x7E]/', $v) === 1 ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
}

function sh_mail_multipart(string $boundary, string $text, string $html): string
{
    return "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
        . $text . "\r\n\r\n--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
        . $html . "\r\n\r\n--$boundary--\r\n";
}

/**
 * Record one delivery attempt.
 *
 * Stores the recipient, the transport outcome, the error category and the
 * provider message id. It never stores the body, a verification code, or a
 * credential: the redaction happens before this call and the columns do not
 * exist to hold them.
 *
 * @param array<string,mixed> $r result array from sh_mail_send() plus subject/opts
 */
function sh_email_delivery_log(array $r): void
{
    try {
        static $tableReady = null;
        if ($tableReady === false) { return; }
        if (!function_exists('sh_query') || !function_exists('sh_val')) { return; }
        if ($tableReady === null) {
            // The table is created by the installer and by the migration on
            // existing stores; until then a send must still work.
            $tableReady = (int)sh_val('SELECT COUNT(*) FROM information_schema.tables
                                       WHERE table_schema = DATABASE() AND table_name = ?', ['email_log'], 0) === 1;
            if ($tableReady === false) { return; }
        }
        $to = (string)($r['recipient'] ?? '');
        $opts = is_array($r['opts'] ?? null) ? $r['opts'] : [];
        $status = !empty($r['ok']) ? 'accepted' : (!empty($r['skipped']) ? 'skipped' : 'failed');
        $error = (string)($r['error'] ?? '');
        sh_query(
            'INSERT INTO email_log
                (user_id, recipient, recipient_domain, purpose, subject, transport, status, category, error_text, message_id, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
            [
                ($uid = (int)($opts['log_user_id'] ?? 0)) > 0 ? $uid : null,
                mb_substr($to, 0, 190),
                mb_substr((string)(str_contains($to, '@') ? substr($to, strrpos($to, '@') + 1) : ''), 0, 120),
                mb_substr((string)($opts['log_purpose'] ?? $opts['purpose'] ?? 'generic'), 0, 40),
                mb_substr((string)($r['subject'] ?? ''), 0, 180),
                mb_substr((string)($r['transport'] ?? 'none'), 0, 20),
                $status,
                mb_substr((string)($r['category'] ?? 'unknown'), 0, 30),
                mb_substr(sh_mail_redact($error), 0, 500),
                mb_substr((string)($r['message_id'] ?? ''), 0, 190),
            ]
        );
    } catch (Throwable $e) {
        // Logging must never break the delivery path or leak the message.
        if (function_exists('sh_log_line')) { sh_log_line('mail', 'Delivery log write failed: ' . $e->getMessage()); }
    }
}

/**
 * Send a real test message and return the redacted transcript, for the admin
 * "Test connection" and "Send test email" buttons.
 *
 * @param array<string,mixed> $transport values from the submitted form
 * @param array<string,mixed> $opts      debug/home
 * @return array<string,mixed>
 */
function sh_mail_send_test(string $to, array $transport, array $opts = []): array
{
    $subject = 'ShopHaat test email (' . date('d M Y H:i') . ')';
    $text = "This is a real test message from your ShopHaat installation.\n\n"
        . "If you are reading it, SMTP authentication and submission worked for this server.\n"
        . "Inbox placement still depends on your SPF, DKIM and DMARC records, which a submission\n"
        . "cannot prove — check the provider's delivery report for that.\n\n"
        . 'Host: ' . (string)($transport['host'] ?? '') . ':' . (string)($transport['port'] ?? '') . "\n"
        . 'Encryption: ' . (string)($transport['encryption'] ?? '') . "\n";
    $html = '<p>This is a <strong>real test message</strong> from your ShopHaat installation.</p>'
        . '<p>If you are reading it, SMTP authentication and submission worked for this server. '
        . 'Inbox placement still depends on SPF, DKIM and DMARC, which a submission cannot prove — '
        . "check your provider's delivery report for that.</p>";

    return sh_mail_send($to, $subject, $text, $html, [
        'transport'   => $transport,
        'debug'       => true,
        'log_purpose' => 'smtp_test',
        'log_user_id' => (int)($opts['admin_id'] ?? 0),
    ]);
}
