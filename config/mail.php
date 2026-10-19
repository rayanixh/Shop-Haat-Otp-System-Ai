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
/**
 * Safely parse and sanitize an SMTP host string.
 * Strips leading schemes like ssl://, tls://, tcp://, and trailing ports like :587,
 * returning the normalized hostname, any scheme specified, and the extracted port.
 *
 * @return array{host:string,scheme:string,port:int}
 */
function sh_mail_parse_host(string $rawHost, int $defaultPort = 587): array
{
    $host = trim($rawHost);
    $scheme = '';
    $port = $defaultPort;

    // Check for explicit transport prefix
    if (preg_match('~^([a-z0-9+]+)://(.*)$~i', $host, $m)) {
        $scheme = strtolower($m[1]);
        $host = $m[2];
    }

    // Strip wrapping brackets from IPv6 or host, e.g. [smtp.example.com]
    if (preg_match('~^\[([a-f0-9:]+)\](?::([0-9]+))?$~i', $host, $m)) {
        $host = $m[1];
        if (!empty($m[2])) {
            $port = (int)$m[2];
        }
    } elseif (preg_match('~^([^:/]+):([0-9]+)$~', $host, $m)) {
        // host:port pattern
        $host = $m[1];
        $port = (int)$m[2];
    }

    $host = trim($host, " \t\n\r\0\x0B/");
    return [
        'host'   => $host,
        'scheme' => $scheme,
        'port'   => ($port > 0 && $port <= 65535) ? $port : $defaultPort,
    ];
}

/**
 * Locate the system's trusted CA certificate bundle.
 *
 * Essential on shared hosting environments where openssl.cafile in php.ini
 * is not configured or points to an obsolete compile-time path.
 */
function sh_mail_find_ca_bundle(): ?string
{
    // 1. Configured php.ini settings
    $iniCa = ini_get('openssl.cafile');
    if (is_string($iniCa) && $iniCa !== '' && is_file($iniCa) && is_readable($iniCa)) {
        return $iniCa;
    }
    $iniCurl = ini_get('curl.cainfo');
    if (is_string($iniCurl) && $iniCurl !== '' && is_file($iniCurl) && is_readable($iniCurl)) {
        return $iniCurl;
    }

    // 2. OpenSSL extension locations
    if (function_exists('openssl_get_cert_locations')) {
        $loc = openssl_get_cert_locations();
        if (!empty($loc['default_cert_file']) && is_file($loc['default_cert_file']) && is_readable($loc['default_cert_file'])) {
            return $loc['default_cert_file'];
        }
        if (!empty($loc['ini_cafile']) && is_file($loc['ini_cafile']) && is_readable($loc['ini_cafile'])) {
            return $loc['ini_cafile'];
        }
    }

    // 3. Known system locations on Linux distributions and shared hosting
    $paths = [
        '/etc/ssl/certs/ca-certificates.crt', // Debian, Ubuntu, Gentoo
        '/etc/pki/tls/certs/ca-bundle.crt',   // RHEL, CentOS, Rocky, AlmaLinux, CloudLinux, cPanel
        '/etc/ssl/ca-bundle.pem',             // OpenSUSE, SUSE
        '/etc/pki/tls/cacert.pem',            // OpenELEC
        '/etc/ssl/cert.pem',                  // Alpine, FreeBSD, macOS
        '/usr/local/share/certs/ca-root-nss.crt', // FreeBSD
        '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem', // Modern RHEL/CentOS
        '/etc/ssl/certs/ca-bundle.crt',
    ];
    foreach ($paths as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * Read the current transport configuration from settings.
 *
 * Falls back to sensible shared-hosting defaults: mail() enabled if SMTP
 * is not switched on, standard port 587 with STARTTLS, sender address
 * matching the store contact email.
 *
 * @return array<string,mixed>
 */
function sh_mail_transport(): array
{
    $fromEmail = trim((string)sh_setting('smtp_from_email', (string)sh_setting('contact_email', '')));
    $fromName  = trim((string)sh_setting('smtp_from_name', (string)sh_setting('site_name', 'ShopHaat')));
    $replyTo   = trim((string)sh_setting('smtp_reply_to', ''));
    $enabled   = (string)sh_setting('smtp_enabled', '0') === '1';
    $rawHost   = trim((string)sh_setting('smtp_host', ''));
    $rawPort   = (int)sh_setting('smtp_port', '587');
    $enc       = strtolower((string)sh_setting('smtp_encryption', 'tls'));
    if (!in_array($enc, ['tls', 'ssl', 'none'], true)) { $enc = 'tls'; }

    $defaultPort = ($enc === 'ssl') ? 465 : 587;
    $parsed = sh_mail_parse_host($rawHost, $rawPort > 0 ? $rawPort : $defaultPort);
    $host = $parsed['host'];
    $port = ($parsed['port'] > 0 && $parsed['port'] <= 65535) ? $parsed['port'] : $defaultPort;

    if ($parsed['scheme'] === 'ssl' && $enc !== 'ssl') {
        $enc = 'ssl';
    }

    $timeout = (int)sh_setting('smtp_timeout', '20');
    if ($timeout < 5 || $timeout > 60) { $timeout = 20; }

    return [
        'driver'            => ($enabled && $host !== '') ? 'smtp' : 'mail',
        'host'              => $host,
        'raw_host'          => $rawHost,
        'port'              => $port,
        'encryption'        => $enc,
        'username'          => trim((string)sh_setting('smtp_username', '')),
        'password'          => (string)sh_setting('smtp_password', ''),
        'from_email'        => $fromEmail,
        'from_name'         => $fromName !== '' ? $fromName : 'ShopHaat',
        'reply_to'          => $replyTo,
        'allow_self_signed' => (string)sh_setting('smtp_allow_self_signed', '0') === '1',
        'dkim_enabled'      => (string)sh_setting('dkim_enabled', '0') === '1',
        'timeout'           => $timeout,
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
        'reply_to'      => (string)($t['reply_to'] ?? ''),
        'timeout'       => (int)($t['timeout'] ?? 20),
        'dkim'          => sh_mail_dkim_status(),
        'missing'       => $missing,
        'ca_bundle'     => sh_mail_find_ca_bundle(),
        'openssl_ok'    => extension_loaded('openssl'),
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
            $parsedHost = sh_mail_parse_host((string)$t['host'], (int)($t['port'] ?? 587));
            $cleanHost = $parsedHost['host'];
            $mail->Host = $cleanHost;
            $mail->Port = (int)($t['port'] ?? 587);
            $mail->Timeout = max(5, min(60, (int)($t['timeout'] ?? 20)));
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
            $sslOpts = [
                'verify_peer'       => !$allowSelfSigned,
                'verify_peer_name'  => !$allowSelfSigned,
                'allow_self_signed' => $allowSelfSigned,
                'SNI_enabled'       => true,
                'peer_name'         => $cleanHost,
            ];
            $caBundle = sh_mail_find_ca_bundle();
            if ($caBundle !== null && !$allowSelfSigned) {
                $sslOpts['cafile'] = $caBundle;
            }
            $mail->SMTPOptions = ['ssl' => $sslOpts];
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
            try {
                $errObj = $mail->getSMTPInstance()->getError();
                if (is_array($errObj)) {
                    $smtpErr = trim((string)($errObj['detail'] ?? ($errObj['error'] ?? '')));
                }
            } catch (Throwable $e) { $smtpErr = ''; }
            $raw = sh_mail_redact($raw . ($smtpErr !== '' ? ' (' . $smtpErr . ')' : ''));
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

/**
 * Test SMTP socket reachability, TLS handshake and authentication credentials
 * without sending an email.
 *
 * @param array<string,mixed> $t
 * @return array{ok:bool,step:string,message?:string,error?:string,cause?:string,action?:string,host?:string,port?:int,encryption?:string,transcript:string}
 */
function sh_mail_test_connection(array $t): array
{
    $rawHost = trim((string)($t['host'] ?? ''));
    if ($rawHost === '') {
        return [
            'ok'         => false,
            'step'       => 'config',
            'error'      => 'SMTP host is not configured.',
            'cause'      => 'The SMTP host field is empty.',
            'action'     => 'Enter the SMTP hostname provided by your email service (e.g. smtp.gmail.com).',
            'transcript' => '',
        ];
    }
    if (!class_exists(\PHPMailer\PHPMailer\SMTP::class)) {
        return [
            'ok'         => false,
            'step'       => 'library',
            'error'      => 'PHPMailer SMTP library is not loadable.',
            'cause'      => 'The PHPMailer files could not be located in lib/PHPMailer.',
            'action'     => 'Ensure the application files under lib/PHPMailer are present and readable.',
            'transcript' => '',
        ];
    }

    $port = (int)($t['port'] ?? 587);
    if ($port < 1 || $port > 65535) { $port = 587; }
    $enc = strtolower((string)($t['encryption'] ?? 'tls'));
    if (!in_array($enc, ['tls', 'ssl', 'none'], true)) { $enc = 'tls'; }

    $parsed = sh_mail_parse_host($rawHost, $port);
    $cleanHost = $parsed['host'];
    if (!empty($parsed['port']) && empty($t['port'])) {
        $port = $parsed['port'];
    }

    $timeout = max(5, min(60, (int)($t['timeout'] ?? 20)));
    $allowSelfSigned = !empty($t['allow_self_signed']);

    // Check OpenSSL requirement
    if ($enc !== 'none' && !extension_loaded('openssl')) {
        return [
            'ok'         => false,
            'step'       => 'environment',
            'error'      => 'OpenSSL extension is missing in PHP.',
            'cause'      => 'Encrypted SMTP (' . strtoupper($enc) . ') requires the PHP OpenSSL extension, but it is not loaded on this server.',
            'action'     => 'Enable the openssl PHP extension in your hosting cPanel/php.ini or contact your hosting provider.',
            'host'       => $cleanHost,
            'port'       => $port,
            'encryption' => strtoupper($enc),
            'transcript' => '',
        ];
    }

    // Stage 1: DNS Resolution
    $dnsIp = @gethostbyname($cleanHost);
    if ($dnsIp === $cleanHost && !filter_var($cleanHost, FILTER_VALIDATE_IP)) {
        return [
            'ok'         => false,
            'step'       => 'dns',
            'error'      => "DNS resolution failed: could not resolve host '{$cleanHost}'.",
            'cause'      => "The server could not look up an IP address for '{$cleanHost}'. The hostname may be misspelled or your server's DNS resolver may be unreachable.",
            'action'     => "Check the spelling of the SMTP host. Common examples: smtp.gmail.com, smtp.office365.com, smtp.mailgun.org, smtp-relay.brevo.com.",
            'host'       => $cleanHost,
            'port'       => $port,
            'encryption' => strtoupper($enc),
            'transcript' => '',
        ];
    }

    // Build SSL Stream context options
    $sslOpts = [
        'verify_peer'       => !$allowSelfSigned,
        'verify_peer_name'  => !$allowSelfSigned,
        'allow_self_signed' => $allowSelfSigned,
        'SNI_enabled'       => true,
        'peer_name'         => $cleanHost,
    ];
    $caBundle = sh_mail_find_ca_bundle();
    if ($caBundle !== null && !$allowSelfSigned) {
        $sslOpts['cafile'] = $caBundle;
    }
    $options = ['ssl' => $sslOpts];

    $smtp = new \PHPMailer\PHPMailer\SMTP();
    $transcript = [];
    $smtp->Debugoutput = static function ($str, $level) use (&$transcript): void {
        if (count($transcript) < 80) { $transcript[] = (string)$str; }
    };
    $smtp->do_debug = \PHPMailer\PHPMailer\SMTP::DEBUG_CONNECTION;

    try {
        // Stage 2: TCP Connection
        $connectHost = ($enc === 'ssl') ? ('ssl://' . $cleanHost) : $cleanHost;
        $connected = @$smtp->connect($connectHost, $port, $timeout, $options);
        if (!$connected) {
            $errInfo = $smtp->getError();
            $errText = trim((string)($errInfo['detail'] ?? ($errInfo['error'] ?? '')));
            $sslErrors = [];
            while ($sslErr = openssl_error_string()) { $sslErrors[] = $sslErr; }
            $fullErr = trim($errText . ($sslErrors ? ' (' . implode('; ', $sslErrors) . ')' : ''));

            // Check if connection failure was actually a DNS lookup failure
            if (stripos($fullErr, 'getaddrinfo') !== false || stripos($fullErr, 'name or service not known') !== false || stripos($fullErr, 'nodename nor servname') !== false) {
                return [
                    'ok'         => false,
                    'step'       => 'dns',
                    'error'      => "DNS resolution failed: could not resolve host '{$cleanHost}'.",
                    'cause'      => "The server could not look up an IP address for '{$cleanHost}'. The hostname may be misspelled or your server's DNS resolver is unreachable.",
                    'action'     => "Check the spelling of the SMTP host. Common examples: smtp.gmail.com, smtp.office365.com, smtp.mailgun.org, smtp-relay.brevo.com.",
                    'host'       => $cleanHost,
                    'port'       => $port,
                    'encryption' => strtoupper($enc),
                    'transcript' => sh_mail_redact(implode("\n", $transcript)),
                ];
            }

            if ($enc === 'ssl' && (stripos($fullErr, 'certificate') !== false || stripos($fullErr, 'ssl') !== false || stripos($fullErr, 'handshake') !== false)) {
                return [
                    'ok'         => false,
                    'step'       => 'tls_cert',
                    'error'      => 'Implicit TLS / SSL connection failed: ' . ($fullErr !== '' ? $fullErr : 'Handshake failed.'),
                    'cause'      => 'The server at ' . $cleanHost . ':' . $port . ' failed the SSL/TLS handshake. The host certificate could not be validated or the port does not support SMTPS.',
                    'action'     => 'Verify that port ' . $port . ' is an SSL/TLS port (typically 465). If your provider uses port 587, switch encryption to STARTTLS.',
                    'host'       => $cleanHost,
                    'port'       => $port,
                    'encryption' => strtoupper($enc),
                    'transcript' => sh_mail_redact(implode("\n", $transcript)),
                ];
            }

            return [
                'ok'         => false,
                'step'       => 'connect',
                'error'      => "Could not connect to SMTP server at {$cleanHost}:{$port}" . ($fullErr !== '' ? " ({$fullErr})" : "") . '.',
                'cause'      => "Connection refused or timed out after {$timeout} seconds. Outbound network traffic to port {$port} may be blocked by your hosting provider's firewall, or the server is down.",
                'action'     => "Check if your hosting provider restricts outbound SMTP ports (shared hosting frequently blocks port 25, 465, or 587). Contact support or try an alternative port.",
                'host'       => $cleanHost,
                'port'       => $port,
                'encryption' => strtoupper($enc),
                'transcript' => sh_mail_redact(implode("\n", $transcript)),
            ];
        }

        // Stage 3 & 4: Greeting and EHLO Handshake
        $helloHost = gethostname() ?: 'localhost';
        if (!$smtp->hello($helloHost)) {
            $errInfo = $smtp->getError();
            $errText = trim((string)($errInfo['detail'] ?? ($errInfo['error'] ?? '')));
            $smtp->quit();
            return [
                'ok'         => false,
                'step'       => 'ehlo',
                'error'      => "SMTP server connected but did not answer EHLO handshake" . ($errText !== '' ? " ({$errText})" : "") . ".",
                'cause'      => "The server connected but rejected the initial EHLO/HELO client greeting.",
                'action'     => "Check if the server requires a fully qualified domain name for HELO/EHLO or has blacklisted your server IP.",
                'host'       => $cleanHost,
                'port'       => $port,
                'encryption' => strtoupper($enc),
                'transcript' => sh_mail_redact(implode("\n", $transcript)),
            ];
        }

        $serverCaps = $smtp->getServerExtList() ?? [];

        // Stage 5: STARTTLS Negotiation
        if ($enc === 'tls') {
            if (!is_array($serverCaps) || !array_key_exists('STARTTLS', $serverCaps)) {
                $smtp->quit();
                return [
                    'ok'         => false,
                    'step'       => 'starttls',
                    'error'      => "STARTTLS negotiation failed: server does not advertise STARTTLS.",
                    'cause'      => "The server at {$cleanHost}:{$port} answered EHLO, but did not list STARTTLS in its capabilities. STARTTLS cannot be negotiated.",
                    'action'     => ($port === 465)
                        ? "Port 465 uses SSL/TLS (Implicit TLS). Change Encryption to 'SSL/TLS (secure SMTPS, port 465)'."
                        : "Verify your email provider's required port and encryption settings. If the server does not support TLS, switch to 'None' (unencrypted) or port 465.",
                    'host'       => $cleanHost,
                    'port'       => $port,
                    'encryption' => 'STARTTLS',
                    'transcript' => sh_mail_redact(implode("\n", $transcript)),
                ];
            }

            if (!$smtp->startTLS()) {
                $errInfo = $smtp->getError();
                $errSummary = trim((string)($errInfo['error'] ?? ''));
                $errDetail  = trim((string)($errInfo['detail'] ?? ''));
                $smtpCode   = trim((string)($errInfo['smtp_code'] ?? ''));
                $sslErrors  = [];
                while ($sslMsg = openssl_error_string()) { $sslErrors[] = $sslMsg; }
                $combined = trim($errDetail . ($sslErrors ? ' ' . implode('; ', $sslErrors) : ''));
                $smtp->quit();

                // Detailed classification of the STARTTLS failure
                $failStep = 'starttls';
                $cause = "TLS cryptographic negotiation failed.";
                $action = "Check encryption setting or host certificate.";

                if (stripos($combined, 'certificate verify failed') !== false || stripos($combined, 'unable to get local issuer') !== false || stripos($combined, 'certificate_unknown') !== false) {
                    $failStep = 'tls_cert';
                    $cause = "TLS certificate validation failed: the mail server's certificate could not be verified against the CA certificate bundle.";
                    $action = "Ensure your server's CA certificates are up to date. If connecting to a private or test server, you can enable 'Accept self-signed certificate'.";
                } elseif (stripos($combined, 'peer name') !== false || stripos($combined, 'does not match') !== false || stripos($combined, 'hostname mismatch') !== false) {
                    $failStep = 'tls_cert';
                    $cause = "TLS hostname mismatch: the server's certificate does not match the configured hostname '{$cleanHost}'.";
                    $action = "Verify that '{$cleanHost}' is the official SMTP hostname for your provider (e.g. smtp.gmail.com rather than a custom alias without a matching certificate).";
                } elseif (stripos($combined, 'self signed') !== false || stripos($combined, 'self-signed') !== false) {
                    $failStep = 'tls_cert';
                    $cause = "The mail server presented a self-signed certificate that is not trusted by public CAs.";
                    $action = "Install a valid CA certificate on the mail server or enable 'Accept self-signed certificate' for internal testing.";
                } elseif ($smtpCode !== '' && ($smtpCode[0] === '4' || $smtpCode[0] === '5')) {
                    $cause = "The server rejected the STARTTLS command with code {$smtpCode}: {$errDetail}.";
                    $action = "Check server logs or provider status. The server may be temporarily unable to establish TLS.";
                } elseif (stripos($combined, 'handshake') !== false || stripos($combined, 'timed out') !== false) {
                    $failStep = 'tls_handshake';
                    $cause = "TLS handshake timed out or was terminated during negotiation.";
                    $action = "Verify network stability and timeout settings. Some firewalls inspect and disrupt STARTTLS traffic.";
                }

                $errorDisplay = "STARTTLS negotiation failed" . ($combined !== '' ? " ({$combined})" : ($errSummary !== '' ? " ({$errSummary})" : "")) . ".";
                return [
                    'ok'         => false,
                    'step'       => $failStep,
                    'error'      => $errorDisplay,
                    'cause'      => $cause,
                    'action'     => $action,
                    'host'       => $cleanHost,
                    'port'       => $port,
                    'encryption' => 'STARTTLS',
                    'transcript' => sh_mail_redact(implode("\n", $transcript)),
                ];
            }

            // Re-identify following TLS handshake (RFC 3207 section 4.2)
            $smtp->hello($helloHost);
            $serverCaps = $smtp->getServerExtList() ?? [];
        }

        // Stage 6: Authentication (if credentials provided)
        $user = trim((string)($t['username'] ?? ''));
        $pass = (string)($t['password'] ?? '');
        if ($user !== '') {
            if ($pass === '') {
                $smtp->quit();
                return [
                    'ok'         => false,
                    'step'       => 'auth',
                    'error'      => "SMTP username is set, but password is empty.",
                    'cause'      => "A username ('{$user}') was configured without a password.",
                    'action'     => "Enter an SMTP password or application-specific password.",
                    'host'       => $cleanHost,
                    'port'       => $port,
                    'encryption' => strtoupper($enc),
                    'transcript' => sh_mail_redact(implode("\n", $transcript)),
                ];
            }

            if (!$smtp->authenticate($user, $pass)) {
                $errInfo = $smtp->getError();
                $errText = trim((string)($errInfo['detail'] ?? ($errInfo['error'] ?? '')));
                $smtp->quit();
                return [
                    'ok'         => false,
                    'step'       => 'auth',
                    'error'      => "SMTP authentication failed (535)" . ($errText !== '' ? ": {$errText}" : "."),
                    'cause'      => "Credentials rejected by {$cleanHost}. The username, password, or security settings (e.g. 2FA, App Password requirements) did not match.",
                    'action'     => "Check your username and password. For services with Two-Factor Authentication (e.g. Gmail, Microsoft 365), you must generate an App Password.",
                    'host'       => $cleanHost,
                    'port'       => $port,
                    'encryption' => strtoupper($enc),
                    'transcript' => sh_mail_redact(implode("\n", $transcript)),
                ];
            }
        }

        $smtp->quit();
        return [
            'ok'         => true,
            'step'       => 'complete',
            'message'    => "Connection test successful: reached {$cleanHost}:{$port} over " . strtoupper($enc) . ($user !== '' ? " and authenticated as {$user}" : " (open relay / no auth)") . ".",
            'host'       => $cleanHost,
            'port'       => $port,
            'encryption' => strtoupper($enc),
            'auth'       => $user !== '' ? "Authenticated as {$user}" : "No authentication required",
            'transcript' => sh_mail_redact(implode("\n", $transcript)),
        ];
    } catch (Throwable $e) {
        return [
            'ok'         => false,
            'step'       => 'exception',
            'error'      => sh_mail_redact($e->getMessage()),
            'cause'      => 'Unexpected exception during SMTP connection: ' . sh_mail_redact($e->getMessage()),
            'action'     => 'Check error logs and verify server connectivity.',
            'host'       => $cleanHost,
            'port'       => $port,
            'encryption' => strtoupper($enc),
            'transcript' => sh_mail_redact(implode("\n", $transcript)),
        ];
    }
}
