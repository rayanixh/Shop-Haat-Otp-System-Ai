<?php
/**
 * Capture SMTP server for tests/email-system.php.
 *
 * Listens on one loopback port, speaks just enough SMTP for PHPMailer to
 * complete a real submission, and writes the conversation and the message to
 * files in the directory given on the command line.
 *
 * Modes:
 *   normal     — EHLO advertises AUTH LOGIN PLAIN + STARTTLS, everything accepted
 *   auth_fail  — answers 535 to any AUTH attempt
 *
 * This is test scaffolding only: nothing in the application includes it, and it
 * is never started on a production host.
 *
 * Usage: php tests/smtp-capture-server.php <port> <mode> <output-dir>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

$port = (int)($argv[1] ?? 2525);
$mode = (string)($argv[2] ?? 'normal');
$dir  = (string)($argv[3] ?? sys_get_temp_dir());

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, 'listen failed: ' . $errstr . "\n");
    exit(1);
}
echo "listening on 127.0.0.1:$port ($mode)\n";
@file_put_contents($dir . '/ready', '1');

$deadline = time() + 120;
$handled = 0;
while (time() < $deadline && $handled < 4) {
    $conn = @stream_socket_accept($server, 5);
    if ($conn === false) { continue; }
    $handled++;
    $log = '';
    $inData = false;
    $message = '';

    $say = static function ($conn, string $line) use (&$log): void {
        $log .= 'S: ' . $line . "\n";
        fwrite($conn, $line . "\r\n");
    };
    $say($conn, '220 capture.shophaat.test ESMTP ready');
    stream_set_timeout($conn, 10);

    while (($line = fgets($conn, 4096)) !== false) {
        $trimmed = rtrim($line, "\r\n");
        $log .= 'C: ' . $trimmed . "\n";

        if ($inData) {
            if ($trimmed === '.') {
                $inData = false;
                @file_put_contents($dir . '/message.txt', $log . "\n--- message ---\n" . $message);
                $say($conn, '250 2.0.0 Ok: queued as CAPTURE' . $handled);
                continue;
            }
            $message .= $trimmed . "\n";
            continue;
        }

        $upper = strtoupper($trimmed);
        if (str_starts_with($upper, 'EHLO') || str_starts_with($upper, 'HELO')) {
            $log .= "S: 250-capture.shophaat.test\r\n";
            fwrite($conn, "250-capture.shophaat.test\r\n250-SIZE 10485760\r\n250-8BITMIME\r\n250-AUTH LOGIN PLAIN\r\n250 HELP\r\n");
        } elseif ($upper === 'STARTTLS') {
            // The test drives the plaintext path; declining keeps the transcript
            // readable and exercises the client's fallback logic.
            $say($conn, '454 TLS not available on this capture server');
        } elseif (str_starts_with($upper, 'AUTH LOGIN') || str_starts_with($upper, 'AUTH PLAIN')) {
            if ($mode === 'auth_fail') {
                $say($conn, '535 5.7.8 Authentication credentials invalid');
                continue;
            }
            // Accept whatever credentials arrive: this server exists to capture.
            if (str_starts_with($upper, 'AUTH PLAIN')) {
                $say($conn, '235 2.7.0 Authentication successful');
            } else {
                $say($conn, '334 VXNlcm5hbWU6');
                fgets($conn, 4096);
                $say($conn, '334 UGFzc3dvcmQ6');
                fgets($conn, 4096);
                $say($conn, '235 2.7.0 Authentication successful');
            }
        } elseif (str_starts_with($upper, 'MAIL FROM')) {
            $say($conn, '250 2.1.0 Ok');
        } elseif (str_starts_with($upper, 'RCPT TO')) {
            $say($conn, '250 2.1.5 Ok');
        } elseif ($upper === 'DATA') {
            $inData = true;
            $say($conn, '354 End data with <CR><LF>.<CR><LF>');
        } elseif ($upper === 'RSET') {
            $say($conn, '250 2.0.0 Ok');
        } elseif ($upper === 'QUIT') {
            $say($conn, '221 2.0.0 Bye');
            break;
        } else {
            $say($conn, '250 2.0.0 Ok');
        }
    }
    @file_put_contents($dir . '/conversation.txt', $log);
    fclose($conn);
}

fclose($server);
exit(0);
