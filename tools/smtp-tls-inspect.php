<?php
/**
 * ShopHaat — read-only SMTP/HTTPS certificate inspector.
 *
 * Run this on the actual hosting account (cPanel Terminal, SSH, or a one-off
 * cron job) to see the certificate the hosting network really receives:
 *
 *     php tools/smtp-tls-inspect.php smtp-relay.brevo.com 587 tls
 *     php tools/smtp-tls-inspect.php smtp-relay.brevo.com 465 ssl
 *     php tools/smtp-tls-inspect.php api.brevo.com 443 https
 *
 * It opens its own connection, performs the TLS handshake, prints the subject,
 * SANs, issuer, validity, chain verdict and hostname verdict, and exits. It
 * does not read the database, does not load SMTP credentials, does not issue
 * AUTH/MAIL/RCPT/DATA and does not send mail. The connection is opened without
 * automatic validation only so a mismatched certificate can be read; the
 * script then verifies the chain and hostname itself and reports both.
 *
 * Exit codes: 0 = certificate valid for the host, 2 = hostname mismatch,
 * 1 = inspection failed. No secret is printed or accepted as an argument.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('SH_ROOT', dirname(__DIR__));
require_once SH_ROOT . '/config/mail.php';

$host = trim((string)($argv[1] ?? ''));
$port = (int)($argv[2] ?? 587);
$mode = strtolower(trim((string)($argv[3] ?? 'tls')));
if ($host === '' || !in_array($mode, ['tls', 'ssl', 'https'], true) || $port < 1 || $port > 65535) {
    fwrite(STDERR, "Usage: php tools/smtp-tls-inspect.php <host> <port> [tls|ssl|https]\n");
    fwrite(STDERR, "Example: php tools/smtp-tls-inspect.php smtp-relay.brevo.com 587 tls\n");
    exit(1);
}

$dns = sh_mail_resolve_host($host);
$probe = sh_mail_probe_certificate($host, $port, $mode, 20);

echo "ShopHaat TLS certificate inspection\n";
echo "===================================\n";
echo 'Host:          ' . $host . "\n";
echo 'Port:          ' . $port . "\n";
echo 'Mode:          ' . strtoupper($mode) . "\n";
echo 'Resolved IPs:  ' . ($dns['ipv4'] !== [] ? implode(', ', $dns['ipv4']) : '(none)')
    . ($dns['ipv6'] !== [] ? ' / ' . implode(', ', $dns['ipv6']) : '') . "\n";
if ($dns['error'] !== '') { echo 'DNS error:     ' . $dns['error'] . "\n"; }
echo "\n";

if (empty($probe['ok'])) {
    echo "RESULT: CERTIFICATE COULD NOT BE READ\n";
    echo 'Reason: ' . (string)($probe['error'] ?? 'unknown') . "\n";
    if (!empty($probe['openssl_errors'])) {
        echo "OpenSSL detail:\n  " . implode("\n  ", (array)$probe['openssl_errors']) . "\n";
    }
    exit(1);
}

echo 'Peer endpoint: ' . (string)$probe['peer_ip'] . ':' . $port . "\n";
echo 'Subject:       ' . (string)$probe['subject'] . "\n";
echo 'Issuer:        ' . (string)$probe['issuer'] . "\n";
echo 'Valid from:    ' . (string)$probe['valid_from'] . "\n";
echo 'Valid to:      ' . (string)$probe['valid_to'] . "\n";
echo 'SANs:          ' . (!empty($probe['sans']) ? implode(', ', (array)$probe['sans']) : '(none)') . "\n";
echo 'TLS:           ' . trim((string)$probe['tls_protocol'] . ' ' . (string)$probe['cipher']) . "\n";
echo 'SHA-256:       ' . (string)$probe['fingerprint_sha256'] . "\n";
echo 'Chain valid:   ' . (($probe['chain_valid'] ?? null) === true ? 'yes' : (($probe['chain_valid'] ?? null) === false ? 'NO' : 'not checked')) . "\n";
if (($probe['chain_valid'] ?? null) === false && (string)$probe['chain_error'] !== '') {
    echo 'Chain error:   ' . (string)$probe['chain_error'] . "\n";
}
echo 'Hostname valid:' . (($probe['hostname_valid'] ?? null) === true ? ' yes' : ' NO') . "\n";

if (($probe['hostname_valid'] ?? null) === true && ($probe['chain_valid'] ?? null) !== false) {
    echo "\nRESULT: CERTIFICATE IS VALID FOR THIS HOSTNAME\n";
    exit(0);
}

if (($probe['hostname_valid'] ?? null) === false) {
    $diag = sh_mail_certificate_diagnosis($probe, $host);
    echo "\nRESULT: CERTIFICATE HOSTNAME MISMATCH\n";
    echo 'Cause:  ' . $diag['cause'] . "\n";
    echo 'Action: ' . $diag['action'] . "\n";
    if ($diag['alternate_host'] !== '') {
        echo 'Alternative in SAN: ' . $diag['alternate_host'] . " (confirm with the provider before using; this tool does not switch)\n";
    }
    exit(2);
}

echo "\nRESULT: CERTIFICATE CHAIN IS NOT TRUSTED\n";
echo 'Error: ' . (string)$probe['chain_error'] . "\n";
echo "Action: update the hosting CA bundle or install the provider intermediate. Do not disable verification.\n";
exit(1);
