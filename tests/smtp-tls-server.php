<?php
/**
 * Test-only SMTP server with a real STARTTLS handshake.
 *
 * Usage: php tests/smtp-tls-server.php <port> <cert.pem> <key.pem> <normal|auth_fail> <output-dir>
 *
 * It speaks just enough SMTP for PHPMailer to complete EHLO -> STARTTLS ->
 * EHLO -> AUTH -> DATA. It is never included by the application and must not
 * be started on a production host.
 */
declare(strict_types=1);

$port = (int)($argv[1] ?? 0);
$cert = (string)($argv[2] ?? '');
$key = (string)($argv[3] ?? '');
$mode = (string)($argv[4] ?? 'normal');
$dir = (string)($argv[5] ?? sys_get_temp_dir());
if ($port < 1 || !is_file($cert) || !is_file($key)) {
    fwrite(STDERR, "bad arguments\n");
    exit(1);
}

// Server context: the certificate/key the stub presents. It does not request
// or verify a client certificate, and it never relaxes client-side checks.
$ctx = stream_context_create(['ssl' => [
    'local_cert' => $cert,
    'local_pk' => $key,
]]);
$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
    fwrite(STDERR, 'listen failed: ' . $errstr . "\n");
    exit(1);
}
@file_put_contents($dir . '/ready', '1');

$log = '';
$stage = 'pre-tls';
$say = static function ($conn, string $line) use (&$log): void {
    $log .= 'S: ' . $line . "\n";
    fwrite($conn, $line . "\r\n");
};

$deadline = time() + 180;
$handled = 0;
while (time() < $deadline && $handled < 20) {
    $conn = @stream_socket_accept($server, 5);
    if ($conn === false) { continue; }
    $handled++;
    $log = '';
    $stage = 'pre-tls';
    $inData = false;
    $message = '';
    $say($conn, '220 tls-test.local ESMTP ready');
    stream_set_timeout($conn, 10);

    while (($line = fgets($conn, 8192)) !== false) {
        $trimmed = rtrim($line, "\r\n");
        $log .= '[' . $stage . '] C: ' . $trimmed . "\n";
        if ($inData) {
            if ($trimmed === '.') {
                $inData = false;
                // Keep the transcript in front of the message, like the older
                // capture server, so tests can assert on the SMTP conversation
                // and decode the message from the same file.
                @file_put_contents($dir . '/message.txt', $log . "\n--- message ---\n" . $message);
                $say($conn, '250 2.0.0 Ok: queued as TLS-TEST');
                continue;
            }
            $message .= $trimmed . "\n";
            continue;
        }
        $upper = strtoupper($trimmed);
        if (str_starts_with($upper, 'EHLO') || str_starts_with($upper, 'HELO')) {
            if ($stage === 'pre-tls') {
                $say($conn, '250-tls-test.local');
                $say($conn, '250-SIZE 10485760');
                $say($conn, '250-STARTTLS');
                $say($conn, '250 HELP');
            } else {
                $say($conn, '250-tls-test.local');
                $say($conn, '250-SIZE 10485760');
                $say($conn, '250-AUTH LOGIN PLAIN');
                $say($conn, '250 HELP');
            }
        } elseif ($upper === 'STARTTLS') {
            if ($stage !== 'pre-tls') {
                $say($conn, '503 5.5.1 Already encrypted');
                continue;
            }
            $say($conn, '220 2.0.0 Ready to start TLS');
            $ok = stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            if ($ok !== true) {
                $log .= "[tls] handshake failed\n";
                break;
            }
            $stage = 'tls';
        } elseif (str_starts_with($upper, 'AUTH LOGIN') || str_starts_with($upper, 'AUTH PLAIN')) {
            if ($stage !== 'tls') {
                $log .= "[fatal] AUTH before TLS\n";
                $say($conn, '530 5.7.0 Must issue STARTTLS first');
                continue;
            }
            if ($mode === 'auth_fail') {
                $say($conn, '535 5.7.8 Authentication credentials invalid');
                continue;
            }
            if (str_starts_with($upper, 'AUTH PLAIN')) {
                $say($conn, '235 2.7.0 Authentication successful');
            } else {
                $say($conn, '334 VXNlcm5hbWU6');
                fgets($conn, 8192);
                $say($conn, '334 UGFzc3dvcmQ6');
                fgets($conn, 8192);
                $say($conn, '235 2.7.0 Authentication successful');
            }
        } elseif (str_starts_with($upper, 'MAIL FROM')) {
            $say($conn, '250 2.1.0 Ok');
        } elseif (str_starts_with($upper, 'RCPT TO')) {
            $say($conn, '250 2.1.5 Ok');
        } elseif ($upper === 'DATA') {
            $inData = true;
            $say($conn, '354 End data with <CR><LF>.<CR><LF>');
        } elseif ($upper === 'QUIT') {
            $say($conn, '221 2.0.0 Bye');
            break;
        } elseif ($upper === 'RSET') {
            $say($conn, '250 2.0.0 Ok');
        } else {
            $say($conn, '250 2.0.0 Ok');
        }
    }
    @file_put_contents($dir . '/conversation-' . $handled . '.txt', $log);
    @fclose($conn);
}
@fclose($server);
@file_put_contents($dir . '/done', '1');
exit(0);
