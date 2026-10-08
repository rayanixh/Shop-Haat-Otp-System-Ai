<?php
/**
 * SMTP mailer written from scratch (no Composer / PHPMailer dependency, so it
 * works on any shared host). Falls back to PHP mail() when SMTP is disabled.
 * Supports plain, TLS (STARTTLS) and SSL connections with AUTH LOGIN/PLAIN.
 */

/** @return array{ok:bool,error?:string,skipped?:bool,recipient?:string} */
function sh_mail_send(string $to, string $subject, string $textBody, ?string $htmlBody = null): array
{
    if (!sh_valid_email($to)) {
        return ['ok' => false, 'error' => 'Invalid recipient address.', 'recipient' => $to];
    }
    $fromEmail = trim((string)sh_setting('smtp_from_email', (string)sh_setting('contact_email', '')));
    $fromName  = trim((string)sh_setting('smtp_from_name', (string)sh_setting('site_name', 'ShopHaat')));
    if ($fromEmail === '' || !sh_valid_email($fromEmail)) {
        return ['skipped' => true, 'error' => 'No valid sender email configured.', 'recipient' => $to];
    }

    if ((string)sh_setting('smtp_enabled', '0') === '1') {
        $host = trim((string)sh_setting('smtp_host', ''));
        if ($host === '') {
            return ['skipped' => true, 'error' => 'SMTP is enabled but no host is configured.', 'recipient' => $to];
        }
        return sh_smtp_send([
            'host'       => $host,
            'port'       => (int)sh_setting('smtp_port', '587'),
            'encryption' => (string)sh_setting('smtp_encryption', 'tls'),
            'username'   => (string)sh_setting('smtp_username', ''),
            'password'   => (string)sh_setting('smtp_password', ''),
            'from_email' => $fromEmail,
            'from_name'  => $fromName,
        ], $to, $subject, $textBody, $htmlBody);
    }

    // Fallback: PHP mail() — available on most cPanel accounts.
    if (!function_exists('mail')) {
        return ['skipped' => true, 'error' => 'Email is not configured and mail() is unavailable.', 'recipient' => $to];
    }
    $boundary = 'sh' . bin2hex(random_bytes(10));
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . sh_mail_encode_name($fromName) . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'X-Mailer: ShopHaat',
    ];
    if ($htmlBody !== null) {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = sh_mail_multipart($boundary, $textBody, $htmlBody);
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $body = $textBody;
    }
    // mail() emits warnings on misconfigured hosts; capture them instead of suppressing.
    $mailWarning = '';
    set_error_handler(static function (int $no, string $msg) use (&$mailWarning): bool {
        $mailWarning = $msg;
        return true;
    });
    try {
        $ok = mail($to, sh_mail_encode_name($subject), $body, implode("\r\n", $headers));
    } finally {
        restore_error_handler();
    }
    if (!$ok && $mailWarning !== '') { sh_log_line('mail', 'mail() failed: ' . $mailWarning); }
    return $ok
        ? ['ok' => true, 'recipient' => $to]
        : ['ok' => false, 'error' => 'PHP mail() returned failure. Configure SMTP for reliable delivery.', 'recipient' => $to];
}

function sh_mail_encode_name(string $v): string
{
    return preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
}

function sh_mail_multipart(string $boundary, string $text, string $html): string
{
    return "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
        . $text . "\r\n\r\n--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
        . $html . "\r\n\r\n--$boundary--\r\n";
}

/** Minimal, strict SMTP client. */
function sh_smtp_send(array $cfg, string $to, string $subject, string $textBody, ?string $htmlBody = null): array
{
    $host = $cfg['host'];
    $port = $cfg['port'] > 0 ? $cfg['port'] : 587;
    $enc = strtolower((string)$cfg['encryption']);
    $timeout = 20;

    $transport = ($enc === 'ssl') ? 'ssl://' . $host : $host;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $errno = 0; $errstr = '';
    // A refused/timed-out socket is an expected runtime condition, not a bug: handle it explicitly.
    set_error_handler(static fn(): bool => true);
    try {
        $conn = stream_socket_client($transport . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    } finally {
        restore_error_handler();
    }
    if (!$conn) {
        return ['ok' => false, 'error' => 'SMTP connection failed: ' . ($errstr ?: 'unreachable host'), 'recipient' => $to];
    }
    stream_set_timeout($conn, $timeout);

    $read = function () use ($conn): array {
        $data = '';
        while (($line = fgets($conn, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') { break; }
        }
        $code = (int)substr(ltrim($data), 0, 3);
        return [$code, trim($data)];
    };
    $write = function (string $cmd) use ($conn): void { fwrite($conn, $cmd . "\r\n"); };
    $fail = function (string $msg) use ($conn, $to): array {
        if (is_resource($conn)) { fclose($conn); }
        return ['ok' => false, 'error' => sh_scrub_secrets($msg), 'recipient' => $to];
    };

    [$code] = $read();
    if ($code !== 220) { return $fail('SMTP server did not greet the connection (code ' . $code . ').'); }

    $ehloHost = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $write('EHLO ' . $ehloHost);
    [$code, $caps] = $read();
    if ($code !== 250) { return $fail('SMTP EHLO rejected (code ' . $code . ').'); }

    if ($enc === 'tls') {
        $write('STARTTLS');
        [$code] = $read();
        if ($code !== 220) { return $fail('SMTP server refused STARTTLS (code ' . $code . ').'); }
        set_error_handler(static fn(): bool => true);
        try {
            $cryptoOk = stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        } finally {
            restore_error_handler();
        }
        if (!$cryptoOk) {
            return $fail('TLS negotiation failed.');
        }
        $write('EHLO ' . $ehloHost);
        [$code, $caps] = $read();
        if ($code !== 250) { return $fail('SMTP EHLO after STARTTLS rejected (code ' . $code . ').'); }
    }

    if ($cfg['username'] !== '') {
        if (stripos($caps, 'AUTH') !== false && stripos($caps, 'LOGIN') !== false) {
            $write('AUTH LOGIN');
            [$code] = $read();
            if ($code !== 334) { return $fail('SMTP AUTH LOGIN not accepted (code ' . $code . ').'); }
            $write(base64_encode($cfg['username']));
            [$code] = $read();
            if ($code !== 334) { return $fail('SMTP rejected the username.'); }
            $write(base64_encode($cfg['password']));
            [$code] = $read();
            if ($code !== 235) { return $fail('SMTP authentication failed. Check the username and password.'); }
        } else {
            $write('AUTH PLAIN ' . base64_encode("\0" . $cfg['username'] . "\0" . $cfg['password']));
            [$code] = $read();
            if ($code !== 235) { return $fail('SMTP authentication failed. Check the username and password.'); }
        }
    }

    $write('MAIL FROM:<' . $cfg['from_email'] . '>');
    [$code] = $read();
    if ($code !== 250) { return $fail('SMTP rejected the sender address (code ' . $code . ').'); }

    $write('RCPT TO:<' . $to . '>');
    [$code] = $read();
    if ($code !== 250 && $code !== 251) { return $fail('SMTP rejected the recipient address (code ' . $code . ').'); }

    $write('DATA');
    [$code] = $read();
    if ($code !== 354) { return $fail('SMTP refused the DATA command (code ' . $code . ').'); }

    $boundary = 'sh' . bin2hex(random_bytes(10));
    $headers = [
        'Date: ' . date('r'),
        'From: ' . sh_mail_encode_name($cfg['from_name']) . ' <' . $cfg['from_email'] . '>',
        'To: <' . $to . '>',
        'Subject: ' . sh_mail_encode_name($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehloHost . '>',
        'MIME-Version: 1.0',
    ];
    if ($htmlBody !== null) {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = sh_mail_multipart($boundary, $textBody, $htmlBody);
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $body = $textBody;
    }
    // Dot-stuffing per RFC 5321
    $data = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", str_replace("\r\n", "\n", $body));
    $data = str_replace("\n", "\r\n", $data);
    fwrite($conn, $data . "\r\n.\r\n");
    [$code, $resp] = $read();
    if ($code !== 250) { return $fail('SMTP did not accept the message (code ' . $code . ').'); }

    $write('QUIT');
    if (is_resource($conn)) { fclose($conn); }
    return ['ok' => true, 'recipient' => $to];
}
