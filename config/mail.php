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
 * Settings used: mail_transport (smtp | brevo_api), smtp_enabled, smtp_host,
 * smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from_email,
 * smtp_from_name, brevo_api_enabled, brevo_api_key, dkim_domain,
 * dkim_selector, dkim_private_key_path.
 *
 * TLS policy: certificate-chain and hostname validation are always on for
 * every delivery connection. smtp_allow_self_signed is a retired setting and
 * is deliberately ignored; there is no supported way to turn verification off
 * for a send. The one place a handshake is opened without automatic validation
 * is sh_mail_probe_certificate(), a read-only inspection probe that sends no
 * credentials and no mail. It exists precisely so an operator can see the
 * certificate that a misconfigured endpoint presents, and it records the
 * chain/hostname verdict itself.
 */
declare(strict_types=1);

require_once SH_ROOT . '/lib/PHPMailer/autoload.php';

/**
 * Diagnostic-only subclass that exposes the socket metadata of the live SMTP
 * connection. PHPMailer keeps the stream protected; this lets the connection
 * test prove that STARTTLS actually produced an encrypted socket before any
 * credentials are sent. It is never used for delivery.
 */
if (!class_exists('ShMailDiagnosticSMTP', false)) {
    final class ShMailDiagnosticSMTP extends \PHPMailer\PHPMailer\SMTP
    {
        /** @return array<string,mixed> */
        public function socketMeta(): array
        {
            if (!is_resource($this->smtp_conn)) { return []; }
            return stream_get_meta_data($this->smtp_conn);
        }
    }
}

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
    // 0. Explicit deployment/test override. This is a trust anchor, not a way to
    //    skip validation: the bundle is still used with verify_peer=true.
    if (defined('SH_MAIL_CA_BUNDLE')) {
        $explicit = (string)constant('SH_MAIL_CA_BUNDLE');
        if ($explicit !== '' && is_file($explicit) && is_readable($explicit)) {
            return $explicit;
        }
    }

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

/** The delivery transport selected by the administrator. */
function sh_mail_selected_transport(): string
{
    $mode = strtolower(trim((string)sh_setting('mail_transport', 'smtp')));
    return in_array($mode, ['smtp', 'brevo_api'], true) ? $mode : 'smtp';
}

/**
 * Inspect the stored Brevo API credential without revealing it.
 *
 * A prefix check is a hint, never proof of validity. A key that does not look
 * like a v3 key is still offered to Brevo, which is the only authority on
 * whether it is valid. The one case refused outright is an SMTP key in the API
 * field: sending it could not authenticate anything and would leak a second
 * credential to the wrong endpoint.
 *
 * States: ok (looks like a v3 key), unverified (unexpected shape, still
 * tested), smtp_key (refused), missing, unreadable.
 *
 * @return array{state:string,key:string,prefix:string,message:string}
 */
function sh_brevo_api_key_info(): array
{
    $out = ['state' => 'missing', 'key' => '', 'prefix' => '', 'message' => 'No Brevo API key is stored.'];
    $stored = trim((string)sh_setting('brevo_api_key', ''));
    if ($stored === '') { return $out; }

    if (!function_exists('sh_verify_decrypt')) {
        $helper = SH_ROOT . '/includes/verification.php';
        if (is_file($helper)) { require_once $helper; }
    }
    if (!function_exists('sh_verify_decrypt')) {
        $out['state'] = 'unreadable';
        $out['message'] = 'The stored Brevo API key could not be decrypted on this server. Save the key again.';
        return $out;
    }
    $key = trim((string)sh_verify_decrypt($stored));
    if ($key === '') {
        $out['state'] = 'unreadable';
        $out['message'] = 'The stored Brevo API key is empty after decryption. Save the key again.';
        return $out;
    }

    $out['key'] = $key;
    $out['prefix'] = str_starts_with($key, 'xkeysib-') ? 'xkeysib-'
        : (str_starts_with($key, 'xsmtpsib-') ? 'xsmtpsib-' : 'other');

    if (str_starts_with($key, 'xsmtpsib-')) {
        // Never send an SMTP key to the API endpoint. It is the wrong
        // credential and would be a cross-transport credential leak.
        $out['state'] = 'smtp_key';
        $out['key'] = '';
        $out['message'] = 'The stored credential is a Brevo SMTP key (xsmtpsib-...). SMTP keys cannot authenticate API requests. '
            . 'Create a v3 API key in Brevo → SMTP & API → API Keys & MCP and store it here; the SMTP key stays with the SMTP transport.';
        return $out;
    }
    if (!str_starts_with($key, 'xkeysib-')) {
        $out['state'] = 'unverified';
        $out['message'] = 'The stored credential does not look like a Brevo v3 API key (they normally start with xkeysib-). '
            . 'It will still be tested against Brevo, because only Brevo can confirm whether a key is valid.';
        return $out;
    }
    $out['state'] = 'ok';
    return $out;
}

/** The usable API key, or an empty string when the stored credential must not be sent. */
function sh_brevo_api_key(): string
{
    $info = sh_brevo_api_key_info();
    return in_array($info['state'], ['ok', 'unverified'], true) ? (string)$info['key'] : '';
}

function sh_brevo_api_key_set(): bool
{
    return sh_brevo_api_key() !== '';
}

/** Base URL for the v3 API. A constant exists so the test suite can use a local HTTPS server. */
function sh_brevo_api_base(): string
{
    $base = defined('SH_BREVO_API_BASE') ? (string)constant('SH_BREVO_API_BASE') : 'https://api.brevo.com/v3';
    return rtrim($base, '/');
}

/**
 * Read the current transport configuration from settings.
 *
 * Falls back to sensible shared-hosting defaults: mail() enabled if SMTP
 * is not switched on, standard port 587 with STARTTLS, sender address
 * matching the store contact email.
 *
 * The Brevo API transport is only selected when the administrator has
 * explicitly saved mail_transport=brevo_api, switched it on and stored a key.
 * There is no automatic fallback between SMTP and the API.
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

    $mode = sh_mail_selected_transport();
    $apiEnabled = (string)sh_setting('brevo_api_enabled', '0') === '1';
    $apiKeySet = sh_brevo_api_key_set();
    if ($mode === 'brevo_api') {
        // Never fall back to mail()/SMTP silently: an incomplete API
        // configuration is reported as not configured, not delivered by a
        // different route the administrator did not choose.
        $driver = 'brevo_api';
    } else {
        $driver = ($enabled && $host !== '') ? 'smtp' : 'mail';
    }

    return [
        'driver'            => $driver,
        'transport'         => $mode,
        'host'              => $host,
        'raw_host'          => $rawHost,
        'port'              => $port,
        'encryption'        => $enc,
        'username'          => trim((string)sh_setting('smtp_username', '')),
        'password'          => (string)sh_setting('smtp_password', ''),
        'from_email'        => $fromEmail,
        'from_name'         => $fromName !== '' ? $fromName : 'ShopHaat',
        'reply_to'          => $replyTo,
        // Retired setting: verification is never optional. Kept in the array so
        // older callers that read the key do not break, but always false.
        'allow_self_signed' => false,
        'brevo_api_enabled' => $apiEnabled,
        'brevo_api_key_set' => $apiKeySet,
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
    $driver = (string)$t['driver'];

    if ($driver === 'smtp') {
        $ready = $fromOk && $t['host'] !== '' && $authOk;
    } elseif ($driver === 'brevo_api') {
        $ready = $fromOk && $t['brevo_api_enabled'] && $t['brevo_api_key_set'];
    } else {
        $ready = $fromOk;
    }

    $missing = [];
    if (!$fromOk) { $missing[] = 'A valid sender email address (Sender email)'; }
    if ($driver === 'smtp' && $t['host'] === '') { $missing[] = 'SMTP host'; }
    if ($driver === 'smtp' && $t['username'] !== '' && $t['password'] === '') { $missing[] = 'SMTP password'; }
    if ($t['transport'] === 'brevo_api') {
        if (!$t['brevo_api_enabled']) { $missing[] = 'Brevo API transport enabled'; }
        if (!$t['brevo_api_key_set']) { $missing[] = 'Brevo API key'; }
    }

    return [
        'ready'         => $ready,
        'driver'        => $driver,
        'transport'     => (string)$t['transport'],
        'library'       => sh_phpmailer_source(),
        'host'          => $driver === 'brevo_api' ? 'api.brevo.com' : (string)$t['host'],
        'port'          => $driver === 'brevo_api' ? 443 : (int)$t['port'],
        'encryption'    => $driver === 'brevo_api' ? 'HTTPS' : (string)$t['encryption'],
        'username_set'  => $t['username'] !== '',
        'password_set'  => $t['password'] !== '',
        'api_key_set'   => (bool)$t['brevo_api_key_set'],
        'from_email'    => (string)$t['from_email'],
        'from_name'     => (string)$t['from_name'],
        'reply_to'      => (string)($t['reply_to'] ?? ''),
        'timeout'       => (int)($t['timeout'] ?? 20),
        'dkim'          => sh_mail_dkim_status(),
        'missing'       => $missing,
        'ca_bundle'     => sh_mail_find_ca_bundle(),
        'openssl_ok'    => extension_loaded('openssl'),
        'curl_ok'       => function_exists('curl_init'),
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
    // Brevo v3 API keys (xkeysib-...) and SMTP keys (xsmtpsib-...) are secrets
    // even when they appear without a "key=" label.
    $text = preg_replace('~\b(?:xkeysib|xsmtpsib)-[A-Za-z0-9_-]{8,}~', '[redacted]', $text) ?? $text;
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
    // Hostname verification is a distinct failure from a chain/trust failure.
    // The OpenSSL wording differs across PHP versions, so match all of it
    // before the generic TLS bucket and never label it "cryptographic".
    foreach (['subjectaltname did not match', 'did not match expected name', 'peer certificate',
              'hostname mismatch', 'certificate is not valid for', 'certificate subject name',
              'does not match the expected name', 'ip address mismatch'] as $needle) {
        if (str_contains($r, $needle)) { return 'tls_hostname'; }
    }
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


// ---------------------------------------------------------------------------
// TLS evidence (read-only certificate inspection)
// ---------------------------------------------------------------------------

/**
 * Resolve a hostname to every address PHP can see, keeping the first IPv4 as
 * the preferred target. Used by the diagnostic report so an operator can see
 * which endpoint the hosting resolver actually selected.
 *
 * @return array{host:string,ipv4:array<int,string>,ipv6:array<int,string>,selected:string,error:string}
 */
function sh_mail_resolve_host(string $host): array
{
    $out = ['host' => $host, 'ipv4' => [], 'ipv6' => [], 'selected' => '', 'error' => ''];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { $out['ipv6'][] = $host; }
        else { $out['ipv4'][] = $host; }
        $out['selected'] = $host;
        return $out;
    }

    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $row) {
                if (!is_array($row)) { continue; }
                if (!empty($row['ip']) && filter_var($row['ip'], FILTER_VALIDATE_IP)) {
                    $out['ipv4'][] = (string)$row['ip'];
                } elseif (!empty($row['ipv6']) && filter_var($row['ipv6'], FILTER_VALIDATE_IP)) {
                    $out['ipv6'][] = (string)$row['ipv6'];
                }
            }
        }
    }
    if ($out['ipv4'] === [] && function_exists('gethostbynamel')) {
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) { if (filter_var($ip, FILTER_VALIDATE_IP)) { $out['ipv4'][] = (string)$ip; } }
        }
    }
    $out['ipv4'] = array_values(array_unique($out['ipv4']));
    $out['ipv6'] = array_values(array_unique($out['ipv6']));
    $out['selected'] = $out['ipv4'][0] ?? ($out['ipv6'][0] ?? '');
    if ($out['selected'] === '') {
        $out['error'] = "DNS resolution failed: no A or AAAA address was returned for '{$host}'.";
    }
    return $out;
}

/**
 * Read one (possibly multi-line) SMTP reply without authenticating or sending
 * anything. Used only by the certificate-inspection probe.
 *
 * @param resource $stream
 * @return array{code:int,lines:array<int,string>,text:string}
 */
function sh_mail_smtp_read_reply($stream, int $limit = 64): array
{
    $lines = [];
    $code = 0;
    // RFC 5321 limits an SMTP reply line to 1000 bytes. 2048 is generous and
    // still avoids allocating an oversized read buffer for every line.
    while (count($lines) < $limit && ($line = fgets($stream, 2048)) !== false) {
        $trimmed = rtrim($line, "\r\n");
        $lines[] = $trimmed;
        if (preg_match('~^(\d{3})([ -])~', $trimmed, $m) === 1) {
            if ($m[2] === ' ') { $code = (int)$m[1]; break; }
        } else {
            break;
        }
    }
    return ['code' => $code, 'lines' => $lines, 'text' => implode("\n", $lines)];
}

/** Split a certificate SAN extension into DNS and IP identities. */
function sh_mail_certificate_names(array $parsed): array
{
    $raw = (string)($parsed['extensions']['subjectAltName'] ?? '');
    $dns = [];
    $ips = [];
    foreach (preg_split('~,\s*~', $raw) ?: [] as $part) {
        $part = trim($part);
        if ($part === '') { continue; }
        if (preg_match('~^DNS:(.+)$~i', $part, $m) === 1) { $dns[] = strtolower(rtrim(trim($m[1]), '.')); }
        elseif (preg_match('~^(?:IP Address|IP):(.+)$~i', $part, $m) === 1) { $ips[] = trim($m[1]); }
    }
    return ['dns' => array_values(array_unique($dns)), 'ip' => array_values(array_unique($ips))];
}

/**
 * RFC 6125 name matching for the SAN list.
 *
 * A wildcard is accepted only as the complete left-most label and matches
 * exactly one label. It never matches a bare parent domain and never matches
 * a name containing a further dot.
 *
 * @param array<int,string> $dnsNames
 */
function sh_mail_tls_name_matches(string $host, array $dnsNames): bool
{
    $host = strtolower(rtrim(trim($host), '.'));
    if ($host === '') { return false; }
    foreach ($dnsNames as $name) {
        $name = strtolower(rtrim(trim($name), '.'));
        if ($name === '' || $name[0] === '.') { continue; }
        if ($name === $host) { return true; }
        if (str_starts_with($name, '*.')) {
            $suffix = substr($name, 1); // ".example.com"
            if (str_ends_with($host, $suffix)) {
                $label = substr($host, 0, -strlen($suffix));
                if ($label !== '' && !str_contains($label, '.')) { return true; }
            }
        }
    }
    return false;
}

/** Bracket an IPv6 literal for use in a stream URI. */
function sh_mail_uri_host(string $host): string
{
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return '[' . $host . ']';
    }
    return $host;
}

/**
 * Inspect the certificate a TLS endpoint presents.
 *
 * IMPORTANT: this is a read-only evidence probe. It opens a separate
 * connection, completes the handshake without automatic validation so that a
 * mismatched certificate can actually be read, then validates the chain with
 * openssl_x509_checkpurpose() and the hostname with sh_mail_tls_name_matches().
 * It never sends SMTP credentials, never issues AUTH/MAIL/RCPT/DATA and never
 * reports a message as sent. Delivery connections in sh_mail_send() and
 * sh_mail_test_connection() always use verify_peer + verify_peer_name.
 *
 * @param string $mode tls (STARTTLS), ssl (implicit TLS) or https
 * @return array<string,mixed>
 */
function sh_mail_probe_certificate(string $host, int $port, string $mode, int $timeout = 20): array
{
    $mode = strtolower($mode);
    if (!in_array($mode, ['tls', 'ssl', 'https'], true)) { $mode = 'tls'; }
    $timeout = max(5, min(60, $timeout));
    $out = [
        'attempted' => true, 'ok' => false, 'inspection_only' => true,
        'host' => $host, 'port' => $port, 'encryption' => strtoupper($mode),
        'peer_ip' => '', 'tls_protocol' => '', 'cipher' => '',
        'subject' => '', 'subject_cn' => '', 'issuer' => '',
        'valid_from' => '', 'valid_to' => '',
        'sans' => [], 'dns_sans' => [], 'ip_sans' => [],
        'fingerprint_sha256' => '', 'serial' => '',
        'hostname_valid' => null, 'chain_valid' => null, 'chain_error' => '',
        'error' => '', 'openssl_errors' => [],
    ];

    if (str_contains($host, "\0") || preg_match('~^[A-Za-z0-9._:\-\[\]]+$~', $host) !== 1) {
        $out['error'] = 'The hostname contains characters that are not valid in a TLS target.';
        return $out;
    }
    if (!function_exists('openssl_x509_parse')) {
        $out['error'] = 'The PHP OpenSSL extension is not available, so the certificate cannot be inspected.';
        return $out;
    }

    $ssl = [
        // Inspection only: the certificate is parsed and checked explicitly
        // below. This context is never used to authenticate or send mail.
        'verify_peer' => false,
        'verify_peer_name' => false,
        'SNI_enabled' => true,
        'peer_name' => $host,
        'capture_peer_cert' => true,
        'capture_peer_cert_chain' => true,
    ];
    $ctx = stream_context_create(['ssl' => $ssl]);
    $stream = null;
    $errors = [];
    set_error_handler(static function (int $no, string $msg) use (&$errors): bool {
        $errors[] = $msg;
        return true;
    });
    try {
        $uriHost = sh_mail_uri_host($host);
        if ($mode === 'tls') {
            $stream = @stream_socket_client('tcp://' . $uriHost . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
            if (!is_resource($stream)) {
                $out['error'] = 'TCP connection failed: ' . ($errstr !== '' ? $errstr : 'no response') . ($errno ? " (errno {$errno})" : '') . '.';
                return $out;
            }
            stream_set_timeout($stream, $timeout);
            $greeting = sh_mail_smtp_read_reply($stream);
            if ($greeting['code'] !== 220) {
                $out['error'] = 'The server did not send a 220 SMTP greeting: ' . ($greeting['text'] !== '' ? $greeting['text'] : 'no reply') . '.';
                return $out;
            }
            fwrite($stream, "EHLO shophaat-tls-probe\r\n");
            $ehlo = sh_mail_smtp_read_reply($stream);
            if ($ehlo['code'] !== 250) {
                $out['error'] = 'EHLO was not accepted before STARTTLS: ' . ($ehlo['text'] !== '' ? $ehlo['text'] : 'no reply') . '.';
                return $out;
            }
            $advertisesStartTls = false;
            foreach ($ehlo['lines'] as $line) {
                if (stripos($line, 'STARTTLS') !== false) { $advertisesStartTls = true; break; }
            }
            if (!$advertisesStartTls) {
                $out['error'] = 'The server did not advertise STARTTLS, so no certificate can be presented.';
                return $out;
            }
            fwrite($stream, "STARTTLS\r\n");
            $start = sh_mail_smtp_read_reply($stream);
            if ($start['code'] !== 220) {
                $out['error'] = 'The server rejected STARTTLS: ' . ($start['text'] !== '' ? $start['text'] : 'no reply') . '.';
                return $out;
            }
        } else {
            $scheme = 'tls://';
            $stream = @stream_socket_client($scheme . $uriHost . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
            if (!is_resource($stream)) {
                $out['error'] = 'TLS connection failed: ' . ($errstr !== '' ? $errstr : 'no response') . ($errno ? " (errno {$errno})" : '') . '.';
                return $out;
            }
        }

        if ($mode === 'tls') {
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) { $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT; }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) { $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT; }
            $ok = stream_socket_enable_crypto($stream, true, $crypto);
            if ($ok !== true) {
                $out['error'] = 'The TLS handshake did not complete, so the server presented no certificate: ' . implode('; ', array_slice($errors, 0, 4));
                return $out;
            }
        }

        $meta = stream_get_meta_data($stream);
        if (!empty($meta['crypto']) && is_array($meta['crypto'])) {
            $out['tls_protocol'] = (string)($meta['crypto']['protocol'] ?? '');
            $out['cipher'] = trim((string)($meta['crypto']['cipher_name'] ?? '') . ' ' . (string)($meta['crypto']['cipher_bits'] ?? ''));
        }
        $peer = stream_socket_get_name($stream, true);
        if (is_string($peer) && $peer !== '') {
            $pos = strrpos($peer, ':');
            $out['peer_ip'] = $pos === false ? $peer : trim(substr($peer, 0, $pos), '[]');
        }

        $params = stream_context_get_params($stream);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $chain = $params['options']['ssl']['peer_certificate_chain'] ?? null;
        if (!$cert) {
            $out['error'] = 'The TLS handshake completed but PHP did not expose a peer certificate.';
            return $out;
        }
        // short names keep subject[CN] and extensions[subjectAltName] stable.
        $parsed = openssl_x509_parse($cert, true);
        if (!is_array($parsed)) {
            $out['error'] = 'The peer certificate could not be parsed.';
            return $out;
        }

        $subjectParts = [];
        foreach (['CN', 'O', 'OU'] as $k) {
            if (!empty($parsed['subject'][$k])) { $subjectParts[] = $k . '=' . $parsed['subject'][$k]; }
        }
        $issuerParts = [];
        foreach (['CN', 'O', 'C'] as $k) {
            if (!empty($parsed['issuer'][$k])) { $issuerParts[] = $k . '=' . $parsed['issuer'][$k]; }
        }
        $names = sh_mail_certificate_names($parsed);
        $out['subject'] = implode(', ', $subjectParts);
        $out['subject_cn'] = (string)($parsed['subject']['CN'] ?? '');
        $out['issuer'] = implode(', ', $issuerParts);
        $out['valid_from'] = !empty($parsed['validFrom_time_t']) ? date('Y-m-d H:i:s T', (int)$parsed['validFrom_time_t']) : '';
        $out['valid_to'] = !empty($parsed['validTo_time_t']) ? date('Y-m-d H:i:s T', (int)$parsed['validTo_time_t']) : '';
        $out['dns_sans'] = $names['dns'];
        $out['ip_sans'] = $names['ip'];
        $out['sans'] = array_merge($names['dns'], $names['ip']);
        $out['serial'] = (string)($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? '');
        $fingerprint = openssl_x509_fingerprint($cert, 'sha256');
        $out['fingerprint_sha256'] = is_string($fingerprint) ? strtoupper($fingerprint) : '';

        // Hostname check: SAN is authoritative when present, CN only when the
        // certificate carries no SAN at all (RFC 6125 section 6.4.4).
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $out['hostname_valid'] = in_array($host, $names['ip'], true);
        } else {
            $candidates = $names['dns'];
            if ($candidates === [] && $out['subject_cn'] !== '') { $candidates = [$out['subject_cn']]; }
            $out['hostname_valid'] = sh_mail_tls_name_matches($host, $candidates);
        }

        // Chain validation. The leaf is checked against the configured trust
        // store with the server-supplied intermediates, independently of the
        // hostname result.
        $ca = sh_mail_find_ca_bundle();
        $untrusted = null;
        if (is_array($chain) && count($chain) > 1) {
            $pem = '';
            foreach (array_slice($chain, 1) as $chainCert) {
                if (is_string($chainCert) && str_contains($chainCert, 'BEGIN CERTIFICATE')) { $pem .= $chainCert; continue; }
                $one = '';
                if (@openssl_x509_export($chainCert, $one) && is_string($one)) { $pem .= $one; }
            }
            if ($pem !== '') {
                $tmp = tempnam(sys_get_temp_dir(), 'shchain');
                if ($tmp !== false && @file_put_contents($tmp, $pem) !== false) { $untrusted = $tmp; }
            }
        }
        if (function_exists('openssl_x509_checkpurpose') && defined('X509_PURPOSE_SSL_SERVER')) {
            while (openssl_error_string() !== false) { /* drain */ }
            $purposeOk = @openssl_x509_checkpurpose($cert, X509_PURPOSE_SSL_SERVER, $ca !== null ? [$ca] : [], $untrusted);
            $purposeErrors = [];
            while (($sslMsg = openssl_error_string()) !== false) { $purposeErrors[] = $sslMsg; }
            $out['chain_valid'] = ($purposeOk === true);
            $out['chain_error'] = $out['chain_valid'] ? '' : implode('; ', array_slice(array_unique($purposeErrors), 0, 4));
            if (!$out['chain_valid'] && $out['chain_error'] === '') {
                $out['chain_error'] = 'The certificate chain did not validate against ' . ($ca !== null ? $ca : 'the system trust store') . '.';
            }
        }
        if ($untrusted !== null) { @unlink($untrusted); }

        $out['ok'] = true;
        return $out;
    } catch (Throwable $e) {
        $out['error'] = sh_mail_redact($e->getMessage());
        $out['openssl_errors'] = array_slice($errors, 0, 6);
        return $out;
    } finally {
        restore_error_handler();
        if (is_resource($stream)) { @fclose($stream); }
    }
}

/**
 * Turn a certificate probe into the exact cause/action text an operator needs.
 * This is where a hostname mismatch stops being a generic "TLS failed".
 *
 * @param array<string,mixed> $cert
 * @return array{cause:string,action:string,alternate_host:string,is_brevo:bool}
 */
function sh_mail_certificate_diagnosis(array $cert, string $host): array
{
    $host = strtolower($host);
    $isBrevo = $host === 'smtp-relay.brevo.com' || str_ends_with($host, '.brevo.com') || str_ends_with($host, '.sendinblue.com');
    $sans = (array)($cert['dns_sans'] ?? []);
    $subject = trim((string)($cert['subject'] ?? ''));
    if ($subject === '') {
        $subject = 'CN=' . (trim((string)($cert['subject_cn'] ?? '')) !== '' ? (string)$cert['subject_cn'] : 'unknown subject');
    }
    $peer = trim((string)($cert['peer_ip'] ?? ''));
    if ($peer === '') { $peer = $host; }
    $sanText = $sans !== [] ? implode(', ', $sans) : 'none';
    $alternate = '';
    if ($isBrevo && in_array('smtp-relay.sendinblue.com', $sans, true) && $host !== 'smtp-relay.sendinblue.com') {
        $alternate = 'smtp-relay.sendinblue.com';
    }

    $cause = "The endpoint at {$peer} presented a certificate for {$subject} with Subject Alternative Names [{$sanText}]. "
        . "The configured name '{$host}' is not among them. DNS, TCP, EHLO and the TLS handshake all worked; "
        . 'this is hostname validation rejecting a real certificate, not a local SNI bug or a generic cryptographic failure.';
    if ($isBrevo) {
        $cause .= ' Brevo publishes smtp-relay.brevo.com, but some of its regional relay deployments serve a Sendinblue-era certificate instead.';
    }

    $action = 'Keep certificate and hostname validation enabled. Do not disable verification. ';
    if ($isBrevo) {
        $action .= 'Use the Brevo HTTPS API transport (Email settings → Brevo API) with a v3 API key, or ask Brevo support to deploy a certificate that includes smtp-relay.brevo.com on the regional relay.';
        if ($alternate !== '') {
            $action .= " The legacy hostname {$alternate} is present in this certificate and is still accepted by Brevo's relay, but Brevo has not documented it as the current endpoint: confirm it with Brevo support before choosing it explicitly, and test it with full validation.";
        }
    } else {
        $action .= "Use the hostname that appears in the certificate only after confirming it is the provider's supported endpoint, and retest with full validation.";
    }

    return ['cause' => $cause, 'action' => $action, 'alternate_host' => $alternate, 'is_brevo' => $isBrevo];
}

/** Pure classification helper for TLS errors, used by diagnostics and the log. */
function sh_mail_tls_error_kind(string $text): string
{
    $r = strtolower($text);
    foreach (['subjectaltname did not match', 'did not match expected name', 'peer certificate',
              'hostname mismatch', 'certificate is not valid for', 'certificate subject name',
              'does not match the expected name', 'ip address mismatch'] as $needle) {
        if (str_contains($r, $needle)) { return 'hostname'; }
    }
    foreach (['unable to get local issuer', 'certificate verify failed', 'self signed', 'self-signed',
              'unknown ca', 'certificate has expired', 'certificate is not yet valid'] as $needle) {
        if (str_contains($r, $needle)) { return 'chain'; }
    }
    if (str_contains($r, 'wrong version number') || str_contains($r, 'handshake failure')
        || str_contains($r, 'protocol version') || str_contains($r, 'tlsv1 alert')) {
        return 'handshake';
    }
    return 'unknown';
}

// ---------------------------------------------------------------------------
// Brevo HTTPS transactional API (explicit, separate from SMTP credentials)
// ---------------------------------------------------------------------------

/**
 * Reduce a Brevo error/success body to the fields an operator may see.
 *
 * The provider body can echo the request. Only the documented error fields are
 * kept, they are length-capped and passed through the same redaction as every
 * other transport message. The API key is never part of a response body, and
 * even if it were, sh_mail_redact() removes it.
 *
 * @param mixed $body
 * @return array{code:string,message:string,detail:string,fields:array<int,string>}
 */
function sh_brevo_api_provider($body): array
{
    $out = ['code' => '', 'message' => '', 'detail' => '', 'fields' => []];
    if (!is_array($body)) {
        $raw = is_string($body) ? $body : '';
        $out['detail'] = mb_substr(sh_mail_redact($raw), 0, 300);
        return $out;
    }
    $out['code'] = mb_substr(sh_mail_redact((string)($body['code'] ?? '')), 0, 80);
    $message = (string)($body['message'] ?? ($body['error'] ?? ''));
    if (is_array($body['error'] ?? null)) {
        $message = (string)($body['error']['message'] ?? $message);
    }
    $out['message'] = mb_substr(sh_mail_redact($message), 0, 300);
    // Validation detail is often nested; flatten the first level of names only
    // so the operator sees which field Brevo objected to without a body dump.
    foreach (['errors', 'details'] as $listKey) {
        if (!empty($body[$listKey]) && is_array($body[$listKey])) {
            foreach (array_slice($body[$listKey], 0, 5) as $item) {
                if (is_array($item)) {
                    $name = (string)($item['field'] ?? $item['parameter'] ?? $item['name'] ?? '');
                    $text = (string)($item['message'] ?? $item['description'] ?? '');
                    $line = trim($name . ($name !== '' && $text !== '' ? ': ' : '') . $text);
                    if ($line !== '') { $out['fields'][] = mb_substr(sh_mail_redact($line), 0, 160); }
                } elseif (is_string($item) && trim($item) !== '') {
                    $out['fields'][] = mb_substr(sh_mail_redact(trim($item)), 0, 160);
                }
            }
        }
    }
    if ($out['message'] === '' && $out['fields'] !== []) { $out['message'] = $out['fields'][0]; }
    $out['detail'] = trim(implode('; ', array_filter([$out['code'], $out['message']])));
    return $out;
}

/**
 * Give one Brevo HTTP status a precise meaning for the endpoint that produced it.
 *
 * 403 is deliberately not folded into 401: Brevo documents 401 as an invalid or
 * missing key and 403 as insufficient permission for the requested resource.
 * Converting a 403 into "authentication failed" is what made a restricted key
 * look unusable for email sending when it was not.
 *
 * @param array{code:string,message:string,detail:string,fields:array<int,string>} $provider
 * @return array{kind:string,label:string,cause:string,action:string,retry_after:int}
 */
function sh_brevo_api_status_meaning(int $status, array $provider, string $resource = '', int $retryAfter = 0): array
{
    $resource = $resource !== '' ? $resource : 'the requested resource';
    $providerText = trim((string)($provider['code'] ?? '') . (($provider['message'] ?? '') !== '' ? ' — ' . $provider['message'] : ''));
    $suffix = $providerText !== '' ? ' Brevo said: ' . $providerText : '';
    $retry = max(0, $retryAfter);

    if ($status >= 200 && $status < 300) {
        return ['kind' => 'ok', 'label' => 'Accepted', 'cause' => 'Brevo accepted the request.', 'action' => '', 'retry_after' => $retry];
    }
    if ($status === 0) {
        return [
            'kind' => 'network', 'label' => 'No HTTP response',
            'cause' => 'The HTTPS request did not produce an HTTP response. This is a network, DNS, TLS or timeout problem, not an API authorization result.',
            'action' => 'Check outbound HTTPS (port 443), DNS and the CA bundle on the hosting server, then retry. Do not disable certificate verification.',
            'retry_after' => $retry,
        ];
    }
    if ($status === 400 || $status === 422) {
        return [
            'kind' => 'invalid_request', 'label' => 'Request rejected (HTTP ' . $status . ')',
            'cause' => 'Brevo rejected the request payload as invalid.' . $suffix,
            'action' => 'Correct the highlighted field and retry. A rejected payload means the key authenticated.',
            'retry_after' => $retry,
        ];
    }
    if ($status === 401) {
        return [
            'kind' => 'invalid_credentials', 'label' => 'API key not accepted (HTTP 401)',
            'cause' => 'Brevo did not recognise the API key.' . $suffix,
            'action' => 'Create or copy a v3 API key in Brevo → SMTP & API → API Keys & MCP, paste it into the API key field and save. Never use the SMTP key here.',
            'retry_after' => $retry,
        ];
    }
    if ($status === 402) {
        return [
            'kind' => 'payment_required', 'label' => 'Account not activated or out of credits (HTTP 402)',
            'cause' => 'The key authenticated, but the Brevo account cannot process this request: activation, billing or sending credits are blocking it.' . $suffix,
            'action' => 'Check the Brevo plan, sending credits and transactional-email activation in the Brevo account, then retry. No API setting on this server can bypass it.',
            'retry_after' => $retry,
        ];
    }
    if ($status === 403) {
        return [
            'kind' => 'permission_denied', 'label' => 'Authenticated but not permitted for ' . $resource . ' (HTTP 403)',
            'cause' => 'The API key is valid, but it is not authorized for ' . $resource . '.' . $suffix
                . ' This is a permission result for that resource; it is not proof that transactional email is forbidden.',
            'action' => 'Use a key whose owner has access to transactional email (Brevo → SMTP & API → API Keys & MCP), or run the transactional-email test to confirm sending. Do not treat this as a broken key.',
            'retry_after' => $retry,
        ];
    }
    if ($status === 404) {
        return [
            'kind' => 'not_found', 'label' => 'Endpoint not found (HTTP 404)',
            'cause' => 'The API base URL does not expose ' . $resource . '.' . $suffix,
            'action' => 'Check the API endpoint configuration (it must be https://api.brevo.com/v3).',
            'retry_after' => $retry,
        ];
    }
    if ($status === 405) {
        return [
            'kind' => 'method_not_allowed', 'label' => 'Method not allowed (HTTP 405)',
            'cause' => 'Brevo rejected the HTTP method for ' . $resource . '.' . $suffix,
            'action' => 'This is a client defect; report the method shown in the request line.',
            'retry_after' => $retry,
        ];
    }
    if ($status === 429) {
        return [
            'kind' => 'rate_limited', 'label' => 'Rate limited (HTTP 429)',
            'cause' => 'Brevo is rate-limiting this account or endpoint.' . $suffix,
            'action' => 'Wait before retrying' . ($retry > 0 ? ' (Brevo asked for ' . $retry . ' seconds).' : '.') . ' The request was not retried automatically.',
            'retry_after' => $retry,
        ];
    }
    if ($status >= 500) {
        return [
            'kind' => 'provider_error', 'label' => 'Brevo server error (HTTP ' . $status . ')',
            'cause' => 'Brevo returned a server-side error.' . $suffix,
            'action' => 'Retry later and check the Brevo status page. This is not a credential problem.',
            'retry_after' => $retry,
        ];
    }
    return [
        'kind' => 'unexpected', 'label' => 'Unexpected response (HTTP ' . $status . ')',
        'cause' => 'Brevo returned an unexpected status for ' . $resource . '.' . $suffix,
        'action' => 'Compare the request line and body above with the Brevo API reference, then retry.',
        'retry_after' => $retry,
    ];
}

/**
 * Perform one HTTPS request against the Brevo v3 API.
 *
 * TLS verification is always enabled (peer + hostname + trust store). The API
 * key travels only in the server-side request header; it is never put in the
 * URL, a log line, an error string or an HTML response.
 *
 * @return array{ok:bool,status:int,body:mixed,error:string,certificate?:array<string,mixed>,url:string}
 */
function sh_brevo_api_request(string $method, string $path, ?array $payload = null, int $timeout = 20, bool $withCredentials = true): array
{
    $method = strtoupper($method) === 'POST' ? 'POST' : 'GET';
    $timeout = max(5, min(60, $timeout));
    $url = sh_brevo_api_base() . '/' . ltrim($path, '/');
    $out = [
        'ok' => false, 'status' => 0, 'body' => null, 'error' => '', 'url' => $url,
        'request' => [
            'method' => $method,
            'path' => '/' . ltrim($path, '/'),
            'endpoint' => sh_brevo_api_base(),
            'host' => (string)(parse_url($url, PHP_URL_HOST) ?: ''),
            'https' => str_starts_with(strtolower($url), 'https://'),
            'credential_field' => $withCredentials ? 'brevo_api_key' : '',
            'credential_sent' => false,
        ],
        'provider' => ['code' => '', 'message' => '', 'detail' => '', 'fields' => []],
        'retry_after' => 0,
        'headers' => [],
    ];
    if (!$out['request']['https']) {
        $out['error'] = 'The Brevo API endpoint must use HTTPS.';
        return $out;
    }
    $key = '';
    if ($withCredentials) {
        $cred = sh_brevo_api_key_info();
        if ($cred['state'] !== 'ok' && $cred['state'] !== 'unverified') {
            // The credential problem is reported before any request is made,
            // so we never post an SMTP key to the API endpoint.
            $out['error'] = $cred['message'];
            $out['credential_state'] = $cred['state'];
            return $out;
        }
        $key = (string)$cred['key'];
        $out['credential_state'] = $cred['state'];
        $out['request']['credential_sent'] = true;
    }

    $json = null;
    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $out['error'] = 'The Brevo API request could not be encoded.';
            return $out;
        }
    }
    $headers = ['accept: application/json', 'user-agent: ShopHaat mail transport'];
    if ($key !== '') { $headers[] = 'api-key: ' . $key; }
    if ($json !== null) { $headers[] = 'content-type: application/json'; }

    $ca = sh_mail_find_ca_bundle();
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_USERAGENT => 'ShopHaat mail transport',
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) { $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS; }
        if ($ca !== null) { $opts[CURLOPT_CAINFO] = $ca; }
        if (defined('CURLOPT_CERTINFO')) {
            $opts[CURLOPT_CERTINFO] = true;
        }
        if ($json !== null) { $opts[CURLOPT_POSTFIELDS] = $json; }
        $responseHeaders = [];
        $opts[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$responseHeaders): int {
            $trimmed = trim($line);
            if ($trimmed !== '' && str_contains($trimmed, ':')) {
                [$hk, $hv] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($hk))] = trim($hv);
            }
            return strlen($line);
        };
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $out['status'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $out['headers'] = $responseHeaders;
        if (isset($responseHeaders['retry-after']) && ctype_digit($responseHeaders['retry-after'])) {
            $out['retry_after'] = (int)$responseHeaders['retry-after'];
        }
        $err = (string)curl_error($ch);
        if (defined('CURLINFO_CERTINFO')) {
            $ci = @curl_getinfo($ch, CURLINFO_CERTINFO);
            if (is_array($ci) && isset($ci[0]) && is_array($ci[0])) {
                $c = $ci[0];
                // Different libcurl builds label the extension either
                // "Subject Alternative Name" or "X509v3 Subject Alternative Name".
                $sanRaw = '';
                foreach ($c as $certKey => $certValue) {
                    if (is_string($certValue) && stripos((string)$certKey, 'subject alternative name') !== false) {
                        $sanRaw = $certValue;
                        break;
                    }
                }
                $dnsSans = [];
                $ipSans = [];
                foreach (preg_split('~,\s*~', $sanRaw) ?: [] as $san) {
                    $san = trim($san);
                    if (preg_match('~^DNS:(.+)$~i', $san, $m) === 1) { $dnsSans[] = strtolower(trim($m[1])); }
                    elseif (preg_match('~^(?:IP Address|IP):(.+)$~i', $san, $m) === 1) { $ipSans[] = trim($m[1]); }
                }
                $subjectText = (string)($c['Subject'] ?? '');
                $out['certificate'] = [
                    'source' => 'curl',
                    'ok' => true,
                    'host' => (string)(parse_url($url, PHP_URL_HOST) ?: ''),
                    'port' => (int)(parse_url($url, PHP_URL_PORT) ?: 443),
                    'peer_ip' => '',
                    'subject' => $subjectText,
                    'subject_cn' => preg_match('~CN\s*=\s*([^,]+)~', $subjectText, $cn) === 1 ? trim($cn[1]) : '',
                    'issuer' => (string)($c['Issuer'] ?? ''),
                    'valid_from' => (string)($c['Start date'] ?? ''),
                    'valid_to' => (string)($c['Expire date'] ?? ''),
                    'dns_sans' => array_values(array_unique($dnsSans)),
                    'ip_sans' => array_values(array_unique($ipSans)),
                    'sans' => array_values(array_unique(array_merge($dnsSans, $ipSans))),
                    // cURL completed the request with VERIFYPEER and
                    // VERIFYHOST=2, so the chain and hostname are proven valid.
                    'hostname_valid' => true,
                    'chain_valid' => true,
                ];
            }
        }
        curl_close($ch);
        if ($raw === false) {
            $out['error'] = sh_mail_redact($err !== '' ? $err : 'The HTTPS request failed.');
            return $out;
        }
    } else {
        $hdr = implode("\r\n", $headers);
        $http = [
            'method' => $method,
            'header' => $hdr,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 1,
        ];
        if ($json !== null) { $http['content'] = $json; }
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => (string)(parse_url($url, PHP_URL_HOST) ?: '')];
        if ($ca !== null) { $ssl['cafile'] = $ca; }
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool { $warnings[] = $msg; return true; });
        try {
            $raw = file_get_contents($url, false, stream_context_create(['http' => $http, 'ssl' => $ssl]));
        } finally {
            restore_error_handler();
        }
        if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) {
            $out['status'] = (int)$m[1];
        }
        foreach ($http_response_header ?? [] as $headerLine) {
            if (!str_contains($headerLine, ':')) { continue; }
            [$hk, $hv] = explode(':', $headerLine, 2);
            $out['headers'][strtolower(trim($hk))] = trim($hv);
        }
        if (isset($out['headers']['retry-after']) && ctype_digit((string)$out['headers']['retry-after'])) {
            $out['retry_after'] = (int)$out['headers']['retry-after'];
        }
        if ($raw === false) {
            $out['error'] = sh_mail_redact($warnings ? implode('; ', array_slice($warnings, 0, 3)) : 'The HTTPS request failed.');
            return $out;
        }
    }

    $out['body'] = json_decode((string)$raw, true);
    if (!is_array($out['body'])) { $out['body'] = mb_substr((string)$raw, 0, 2000); }
    $out['provider'] = sh_brevo_api_provider($out['body']);
    $out['ok'] = $out['status'] >= 200 && $out['status'] < 300;
    $out['meaning'] = sh_brevo_api_status_meaning(
        (int)$out['status'],
        $out['provider'],
        $out['request']['method'] . ' ' . $out['request']['path'],
        (int)$out['retry_after']
    );
    if (!$out['ok']) {
        $out['error'] = sh_mail_redact('HTTP ' . $out['status'] . (($out['provider']['detail'] ?? '') !== '' ? ': ' . $out['provider']['detail'] : ''));
    }
    return $out;
}

/**
 * Send one message through the official Brevo transactional-email API.
 *
 * @return array{ok:bool,error?:string,skipped?:bool,recipient:string,transport:string,category:string,message_id?:string,response?:string,transcript?:string}
 */
function sh_brevo_api_send(string $to, string $subject, string $textBody, ?string $htmlBody, array $t, array $opts = []): array
{
    $out = ['ok' => false, 'recipient' => $to, 'transport' => 'brevo_api', 'category' => 'unknown'];
    $cred = sh_brevo_api_key_info();
    if (!in_array($cred['state'], ['ok', 'unverified'], true)) {
        // Do not send an SMTP key (or nothing) to the API endpoint.
        $out['category'] = 'credential';
        $out['skipped'] = true;
        $out['error'] = $cred['message'];
        return $out;
    }
    $fromEmail = trim((string)($t['from_email'] ?? ''));
    $fromName = trim((string)($t['from_name'] ?? 'ShopHaat'));
    $replyTo = trim((string)($t['reply_to'] ?? ''));
    $payload = [
        'sender' => ['email' => $fromEmail, 'name' => $fromName !== '' ? $fromName : 'ShopHaat'],
        'to' => [['email' => $to]],
        'subject' => mb_substr($subject, 0, 250),
    ];
    if ($htmlBody !== null && $htmlBody !== '') {
        $payload['htmlContent'] = $htmlBody;
        $payload['textContent'] = $textBody;
    } else {
        $payload['textContent'] = $textBody;
    }
    if ($replyTo !== '' && sh_valid_email($replyTo)) {
        $payload['replyTo'] = ['email' => $replyTo, 'name' => $fromName !== '' ? $fromName : 'ShopHaat'];
    }

    $res = sh_brevo_api_request('POST', '/smtp/email', $payload, (int)($t['timeout'] ?? 20));
    $body = is_array($res['body']) ? $res['body'] : [];
    $messageId = trim((string)($body['messageId'] ?? ''));
    $status = (int)$res['status'];
    $provider = is_array($res['provider'] ?? null) ? $res['provider'] : sh_brevo_api_provider($body);
    $meaning = is_array($res['meaning'] ?? null)
        ? $res['meaning']
        : sh_brevo_api_status_meaning($status, $provider, 'POST /v3/smtp/email');
    $out['request'] = $res['request'] ?? ['method' => 'POST', 'path' => '/smtp/email'];
    $out['http_status'] = $status;
    $out['provider'] = $provider;
    if ($res['ok']) {
        $out['ok'] = true;
        $out['category'] = 'accepted';
        $out['message_id'] = $messageId;
        $out['response'] = 'Brevo accepted the message for processing'
            . ($messageId !== '' ? ' (messageId ' . $messageId . ')' : '') . '.'
            . ' Acceptance is not inbox delivery; check the Brevo transactional log for the final status.';
        $out['acceptance_ok'] = true;
    } else {
        $kind = (string)($meaning['kind'] ?? 'unexpected');
        $out['category'] = match ($kind) {
            'invalid_credentials' => 'auth',
            'permission_denied' => 'permission',
            'payment_required' => 'billing',
            'rate_limited' => 'throttled',
            'invalid_request' => 'rejected',
            'provider_error' => 'temporary',
            'network' => sh_mail_tls_error_kind((string)$res['error']) !== 'unknown' ? 'tls' : 'connection',
            default => sh_mail_category((string)$res['error']),
        };
        $out['error'] = trim($meaning['label'] . '. ' . $meaning['cause']);
        $out['cause'] = $meaning['cause'];
        $out['action'] = $meaning['action'];
        $out['retry_after'] = (int)($meaning['retry_after'] ?? 0);
        if ($out['error'] === '.') { $out['error'] = (string)$res['error']; }
    }
    if (!empty($opts['debug'])) {
        $out['transcript'] = sh_mail_redact('POST ' . sh_brevo_api_base() . "/smtp/email\nHTTP " . $status
            . ($messageId !== '' ? "\nmessageId: " . $messageId : '')
            . (($provider['detail'] ?? '') !== '' ? "\nprovider: " . $provider['detail'] : ''));
    }
    return $out;
}

/**
 * Run the Brevo HTTPS API diagnostics as three separate, honest checks.
 *
 * A. HTTPS and TLS — an unauthenticated request proves DNS, TCP, TLS and HTTP
 *    work. No credential is sent, so this can never be confused with an
 *    authentication result.
 * B. Account access — GET /v3/account, informational only. A 403 here means
 *    the key cannot read account settings. It is not a delivery blocker.
 * C. Credential in the transactional scope — GET /v3/smtp/emails. Brevo
 *    answers 401 for a key it does not know and 403 for a key that is valid
 *    but not permitted for the resource; those are reported differently. This
 *    check never sends a message.
 *
 * Sending is never attempted here. Only a POST /v3/smtp/email that Brevo
 * accepts can mark transactional email as verified, and that happens from the
 * explicit Send Test Email action.
 *
 * @return array<string,mixed>
 */
function sh_brevo_api_test_connection(array $t = []): array
{
    $timeout = max(5, min(60, (int)($t['timeout'] ?? 20)));
    $host = (string)(parse_url(sh_brevo_api_base(), PHP_URL_HOST) ?: 'api.brevo.com');
    $port = (int)(parse_url(sh_brevo_api_base(), PHP_URL_PORT) ?: 443);
    $cred = sh_brevo_api_key_info();
    $base = [
        'ok' => false,
        'transport' => 'brevo_api',
        'host' => $host,
        'port' => $port,
        'encryption' => 'HTTPS',
        'certificate' => null,
        'resolved_ips' => [],
        'checks' => [],
        'credential' => ['state' => $cred['state'], 'prefix' => $cred['prefix'], 'field' => 'brevo_api_key'],
        'verdict' => 'incomplete',
        'step' => 'config',
        'transcript' => '',
    ];

    // ---- A. HTTPS and TLS -------------------------------------------------
    $tlsCheck = ['key' => 'tls', 'label' => 'Test A — HTTPS and TLS reachability', 'status' => 'pending', 'http_status' => 0];
    $dns = sh_mail_resolve_host($host);
    $base['resolved_ips'] = $dns['ipv4'];
    if ($dns['selected'] === '') {
        $tlsCheck['status'] = 'failed';
        $tlsCheck['detail'] = $dns['error'];
        $base['checks']['tls'] = $tlsCheck;
        return array_merge($base, [
            'step' => 'dns',
            'verdict' => 'unreachable',
            'error' => $dns['error'],
            'cause' => "The hosting server could not resolve '{$host}'.",
            'action' => 'Check the server resolver or hosting firewall. The API endpoint must resolve before HTTPS can start.',
            'message' => 'Test A failed at DNS.',
        ]);
    }

    // Deliberately unauthenticated: this measures reachability only.
    $probe = sh_brevo_api_request('GET', '/account', null, $timeout, false);
    $tlsCheck['http_status'] = (int)$probe['status'];
    $tlsCheck['endpoint'] = sh_brevo_api_base();
    $tlsCheck['request'] = $probe['request'] ?? ['method' => 'GET', 'path' => '/account'];
    $cert = $probe['certificate'] ?? null;
    if ($cert === null) {
        $certProbe = sh_mail_probe_certificate($host, $port, 'https', $timeout);
        if (!empty($certProbe['ok'])) { $cert = $certProbe; }
    }
    $base['certificate'] = $cert;

    if ((int)$probe['status'] === 0) {
        $kind = sh_mail_tls_error_kind((string)$probe['error']);
        $tlsCheck['status'] = 'failed';
        $tlsCheck['detail'] = (string)$probe['error'];
        $base['checks']['tls'] = $tlsCheck;
        return array_merge($base, [
            'step' => $kind === 'hostname' ? 'tls_hostname' : ($kind === 'chain' ? 'tls_cert' : 'connect'),
            'verdict' => 'unreachable',
            'error' => (string)$probe['error'],
            'cause' => $kind !== 'unknown'
                ? 'The HTTPS connection to the Brevo API failed TLS validation.'
                : 'The server could not open an HTTPS connection to the Brevo API.',
            'action' => 'Check outbound HTTPS (443) access and the CA bundle on the hosting server. Do not disable certificate verification.',
            'message' => 'Test A failed: the HTTPS endpoint is not reachable.',
        ]);
    }
    // Any HTTP status proves the HTTPS stack worked. 401 is the expected
    // answer for a request without a key.
    $tlsCheck['status'] = 'passed';
    $tlsCheck['detail'] = 'HTTPS response received (HTTP ' . (int)$probe['status'] . ')'
        . ((int)$probe['status'] === 401 ? ' — 401 is expected without a key.' : '.');
    $base['checks']['tls'] = $tlsCheck;
    $base['step'] = 'tls';

    // ---- Credential shape -------------------------------------------------
    if (!in_array($cred['state'], ['ok', 'unverified'], true)) {
        $base['checks']['credentials'] = [
            'key' => 'credentials', 'label' => 'Test C — Transactional-email API access', 'status' => 'not_configured',
            'http_status' => 0, 'endpoint' => 'GET /v3/smtp/emails', 'detail' => $cred['message'],
        ];
        $base['checks']['account'] = [
            'key' => 'account', 'label' => 'Test B — Account API access (informational)', 'status' => 'skipped',
            'http_status' => 0, 'endpoint' => 'GET /v3/account', 'detail' => 'No usable API key is stored.',
        ];
        $base['verdict'] = 'incomplete';
        $base['step'] = 'config';
        return array_merge($base, [
            'error' => $cred['message'],
            'cause' => $cred['state'] === 'smtp_key'
                ? 'An SMTP key was found in the API credential field. It is never sent to the API.'
                : 'The API credential is missing or cannot be read.',
            'action' => 'Open Brevo → SMTP & API → API Keys & MCP, create a v3 API key (xkeysib-...), paste it into the API key field and save. Do not paste the SMTP key.',
            'message' => 'Test A passed. Test B was not run: ' . $cred['message'],
        ]);
    }

    // ---- C. Credential, checked inside the transactional-email scope ------
    $credRes = sh_brevo_api_request('GET', '/smtp/emails?limit=1', null, $timeout, true);
    $credProvider = is_array($credRes['provider'] ?? null) ? $credRes['provider'] : sh_brevo_api_provider($credRes['body']);
    $credMeaning = is_array($credRes['meaning'] ?? null)
        ? $credRes['meaning']
        : sh_brevo_api_status_meaning((int)$credRes['status'], $credProvider, 'GET /v3/smtp/emails');
    $credStatus = 'failed';
    if ((int)$credRes['status'] >= 200 && (int)$credRes['status'] < 300) {
        $credStatus = 'passed';
    } elseif ((int)$credRes['status'] === 401) {
        $credStatus = 'invalid_key';
    } elseif ((int)$credRes['status'] === 403) {
        $credStatus = 'permission_denied';
    } elseif ((int)$credRes['status'] === 402) {
        $credStatus = 'payment_required';
    } elseif ((int)$credRes['status'] === 429) {
        $credStatus = 'rate_limited';
    } elseif ((int)$credRes['status'] === 0) {
        $credStatus = 'unreachable';
    }
    $base['checks']['credentials'] = [
        'key' => 'credentials',
        'label' => 'Test C — Transactional-email API access',
        'status' => $credStatus,
        'http_status' => (int)$credRes['status'],
        'endpoint' => 'GET /v3/smtp/emails?limit=1',
        'request' => $credRes['request'] ?? null,
        'provider' => $credProvider,
        'detail' => trim((string)($credMeaning['label'] ?? '') . (($credMeaning['cause'] ?? '') !== '' ? ': ' . $credMeaning['cause'] : '')),
        'cause' => (string)($credMeaning['cause'] ?? ''),
        'action' => (string)($credMeaning['action'] ?? ''),
        'retry_after' => (int)($credMeaning['retry_after'] ?? 0),
    ];
    $base['request'] = $credRes['request'] ?? null;
    $base['provider'] = $credProvider;
    $base['http_status'] = (int)$credRes['status'];

    // ---- B. Account access, informational only ---------------------------
    $acctRes = sh_brevo_api_request('GET', '/account', null, $timeout, true);
    $acctProvider = is_array($acctRes['provider'] ?? null) ? $acctRes['provider'] : sh_brevo_api_provider($acctRes['body']);
    $acctMeaning = is_array($acctRes['meaning'] ?? null)
        ? $acctRes['meaning']
        : sh_brevo_api_status_meaning((int)$acctRes['status'], $acctProvider, 'GET /v3/account');
    $acctStatus = 'failed';
    if ((int)$acctRes['status'] >= 200 && (int)$acctRes['status'] < 300) {
        $acctStatus = 'passed';
    } elseif ((int)$acctRes['status'] === 401) {
        $acctStatus = 'invalid_key';
    } elseif ((int)$acctRes['status'] === 403) {
        $acctStatus = 'permission_denied';
    } elseif ((int)$acctRes['status'] === 402) {
        $acctStatus = 'payment_required';
    } elseif ((int)$acctRes['status'] === 429) {
        $acctStatus = 'rate_limited';
    } elseif ((int)$acctRes['status'] === 0) {
        $acctStatus = 'unreachable';
    }
    $account = [
        'key' => 'account',
        'label' => 'Test B — Account API access (informational)',
        'status' => $acctStatus,
        'http_status' => (int)$acctRes['status'],
        'endpoint' => 'GET /v3/account',
        'request' => $acctRes['request'] ?? null,
        'provider' => $acctProvider,
        'detail' => trim((string)($acctMeaning['label'] ?? '') . (($acctMeaning['cause'] ?? '') !== '' ? ': ' . $acctMeaning['cause'] : '')),
        'cause' => (string)($acctMeaning['cause'] ?? ''),
        'action' => (string)($acctMeaning['action'] ?? ''),
        'retry_after' => (int)($acctMeaning['retry_after'] ?? 0),
    ];
    if ($acctStatus === 'permission_denied') {
        // The exact condition behind the reported failure. Say plainly that
        // the key is authentic and that this resource is simply out of scope.
        $account['cause'] = 'Brevo accepted the key but this key is not authorized for the account resource.'
            . (($acctProvider['message'] ?? '') !== '' ? ' Brevo said: ' . $acctProvider['message'] : '');
        $account['detail'] = 'HTTP 403 — the key is valid, but account information is not in its permissions. '
            . 'This does not by itself block transactional email; Test B and the Send Test Email result decide that.';
        $account['action'] = 'No action is required for email delivery if Test B passes. If you want account details here, use a key created by the account owner with account access.';
    }
    $base['checks']['account'] = $account;

    // ---- Verdict ----------------------------------------------------------
    $serverInfo = is_array($acctRes['body']) ? trim((string)($acctRes['body']['companyName'] ?? '')) : '';
    if ($acctStatus === 'passed' && $serverInfo !== '') {
        $base['company'] = $serverInfo;
        $relay = is_array($acctRes['body']['relay'] ?? null) ? $acctRes['body']['relay'] : null;
        if (is_array($relay)) {
            $base['relay_enabled'] = !empty($relay['enabled']);
        }
    }

    $base['transcript'] = 'A: GET ' . sh_brevo_api_base() . "/account (no key) -> HTTP " . (int)$probe['status'] . "\n"
        . 'B: GET ' . sh_brevo_api_base() . "/smtp/emails?limit=1 -> HTTP " . (int)$credRes['status'] . "\n"
        . 'C: GET ' . sh_brevo_api_base() . "/account -> HTTP " . (int)$acctRes['status'];

    if ($credStatus === 'passed') {
        $base['ok'] = true;
        $base['verdict'] = 'verified';
        $base['step'] = 'complete';
        $base['message'] = 'Test A passed (HTTPS/TLS) and Test B passed: the API key can use the transactional-email API. '
            . 'No message was sent by these tests — send a test email to prove delivery acceptance.';
        if ($acctStatus === 'permission_denied') {
            $base['message'] .= ' Test C returned 403: the key is valid but not authorized for account details. That does not affect email delivery.';
        }
        return $base;
    }

    // No 200 from the email scope. Decide how honest we can be.
    $anyUnauthorized = ($credStatus === 'invalid_key' || $acctStatus === 'invalid_key');
    $allPermission = in_array($credStatus, ['permission_denied', 'payment_required'], true)
        && in_array($acctStatus, ['permission_denied', 'payment_required', 'skipped'], true);
    if ($allPermission && !$anyUnauthorized) {
        $base['verdict'] = 'permission_limited';
        $base['step'] = 'permission';
        $base['message'] = 'Test A passed. The API key authenticated, but Brevo did not permit the resources checked here. '
            . 'That is a permission result, not necessarily a delivery blocker: run Send Test Email to find out whether '
            . 'this key may submit transactional email.';
        $base['error'] = 'API key authenticated but has no access to the checked resources (HTTP '
            . (int)$credRes['status'] . ').';
        $base['cause'] = (string)$credMeaning['cause'];
        $base['action'] = 'Send a test email to a controlled mailbox to determine whether this key can submit transactional email. '
            . 'If that is also refused, create a key for a user with transactional-email permission.';
        return $base;
    }
    if ($anyUnauthorized) {
        $meaning = $credStatus === 'invalid_key' ? $credMeaning : $acctMeaning;
        $base['verdict'] = 'invalid_credentials';
        $base['step'] = 'credentials';
        $base['error'] = 'Brevo did not accept the API key (HTTP ' . (int)$credRes['status'] . '): '
            . (string)($credProvider['detail'] ?? '');
        $base['cause'] = (string)($meaning['cause'] ?? 'The API key was not recognised.');
        $base['action'] = (string)($meaning['action'] ?? 'Create a new v3 API key in Brevo and save it in the API key field.');
        return $base;
    }
    $base['verdict'] = 'incomplete';
    $base['step'] = 'credentials';
    $base['error'] = 'Test B did not succeed (HTTP ' . (int)$credRes['status'] . ').';
    $base['cause'] = (string)($credMeaning['cause'] ?? 'The transactional-email API did not accept the request.');
    $base['action'] = (string)($credMeaning['action'] ?? 'Check the key, the account status and the endpoint.');
    return $base;
}

/**
 * True when the host belongs to Brevo's relay infrastructure.
 *
 * Used only to make credential-mixup messages precise; verification and
 * connection behaviour are identical for every provider.
 */
function sh_mail_host_is_brevo(string $host): bool
{
    $h = strtolower(rtrim(trim($host), '.'));
    if ($h === '') { return false; }
    return $h === 'brevo.com' || str_ends_with($h, '.brevo.com')
        || $h === 'sendinblue.com' || str_ends_with($h, '.sendinblue.com');
}

/**
 * Detect an API key stored where an SMTP credential belongs (or vice versa).
 *
 * A prefix test is a hint, not proof of validity, so it is only used to refuse
 * a credential that demonstrably belongs to the other transport. Nothing is
 * swapped automatically: guessing would send one provider credential to a
 * service it was not issued for.
 *
 * @param array<string,mixed> $t transport array
 * @return array{code:string,message:string}|null
 */
function sh_mail_credential_mismatch(array $t): ?array
{
    if ((string)($t['driver'] ?? '') !== 'smtp') { return null; }
    if (!sh_mail_host_is_brevo((string)($t['host'] ?? ''))) { return null; }
    $username = trim((string)($t['username'] ?? ''));
    $password = trim((string)($t['password'] ?? ''));
    if (str_starts_with($password, 'xkeysib-')) {
        return [
            'code' => 'api_key_in_smtp_password',
            'message' => 'The saved SMTP password is a Brevo API key (xkeysib-...). SMTP authentication needs the SMTP login and SMTP key '
                . 'from Brevo → SMTP & API → SMTP, not the API key. Nothing was sent. Use the Brevo API transport for the API key, '
                . 'or replace the SMTP password with the SMTP key.',
        ];
    }
    if (str_starts_with($username, 'xkeysib-') || str_starts_with($username, 'xsmtpsib-')) {
        return [
            'code' => 'key_in_smtp_username',
            'message' => 'The SMTP username looks like a Brevo key. The username must be the SMTP login shown in Brevo → SMTP & API → SMTP '
                . '(usually the account email or [ID]@smtp-brevo.com); the key belongs in the password field. Nothing was sent.',
        ];
    }
    // Any other password is left alone: providers use many key shapes, and a
    // prefix is a hint, never proof that a credential is wrong.
    return null;
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
    if (($t['driver'] ?? 'mail') === 'smtp'
        && strtolower((string)($t['encryption'] ?? 'tls')) === 'none'
        && trim((string)($t['username'] ?? '')) !== '') {
        // RFC 4954 credentials must not cross a plaintext channel. An
        // unauthenticated local relay is still allowed; credentials are not.
        $result['skipped'] = true;
        $result['error'] = 'Refusing to send SMTP credentials over an unencrypted connection. Use STARTTLS (port 587) or implicit TLS (port 465), or remove the username if the relay needs no authentication.';
        $result['category'] = 'not_configured';
        sh_email_delivery_log($result + ['subject' => $subject, 'opts' => $opts]);
        return $result;
    }
    if (($t['driver'] ?? 'mail') === 'brevo_api') {
        $apiCred = sh_brevo_api_key_info();
        if (!$t['brevo_api_enabled'] || !in_array($apiCred['state'], ['ok', 'unverified'], true)) {
            $result['skipped'] = true;
            $result['category'] = 'not_configured';
            if ($apiCred['state'] === 'smtp_key') {
                $result['category'] = 'credential';
                $result['error'] = $apiCred['message'];
            } elseif (!$t['brevo_api_enabled']) {
                $result['error'] = 'The Brevo API transport is selected but the switch is off. Enable it and store a v3 API key.';
            } else {
                $result['error'] = 'The Brevo API transport is selected but no usable v3 API key is stored. ' . $apiCred['message'];
            }
            sh_email_delivery_log($result + ['subject' => $subject, 'opts' => $opts]);
            return $result;
        }
        $apiResult = sh_brevo_api_send($to, $subject, $textBody, $htmlBody, $t, $opts);
        sh_email_delivery_log($apiResult + ['subject' => $subject, 'opts' => $opts]);
        return $apiResult;
    }

    if (($t['driver'] ?? 'mail') === 'smtp') {
        $mismatch = sh_mail_credential_mismatch($t);
        if ($mismatch !== null) {
            $result['skipped'] = true;
            $result['category'] = 'credential';
            $result['error'] = $mismatch['message'];
            sh_email_delivery_log($result + ['subject' => $subject, 'opts' => $opts]);
            return $result;
        }
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
            // Delivery always validates the chain and the hostname. The
            // retired smtp_allow_self_signed setting is intentionally not read:
            // there is no supported insecurity switch for outgoing mail.
            $sslOpts = [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
                'SNI_enabled'       => true,
                'peer_name'         => $cleanHost,
            ];
            $caBundle = sh_mail_find_ca_bundle();
            if ($caBundle !== null) {
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
        // PHPMailer wraps a failed STARTTLS in a generic "Could not connect to
        // SMTP host" exception. The real OpenSSL reason (for example a
        // subjectAltName mismatch) only lives on the SMTP instance, so append it
        // before classifying the failure. Without this a certificate mismatch
        // is misreported as a connection error in the delivery log.
        $raw = $e->getMessage();
        if (isset($mail)) {
            try {
                $smtpErr = $mail->getSMTPInstance()->getError();
                if (is_array($smtpErr)) {
                    $raw .= ' ' . (string)($smtpErr['error'] ?? '') . ' ' . (string)($smtpErr['detail'] ?? '');
                }
            } catch (Throwable $ignore) { /* the original exception is the report */ }
        }
        $raw = sh_mail_redact($raw);
        $result['error'] = $raw !== '' ? $raw : 'The mail transport raised an error.';
        $result['category'] = sh_mail_category($raw);
    }

    if (!empty($result['ok'])) { unset($result['action'], $result['alternate_host']); }
    if (empty($result['ok'])) { $result = sh_mail_failure_enrich($result, $t); }
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
    $viaApi = (string)($transport['driver'] ?? '') === 'brevo_api';
    $route = $viaApi
        ? 'Brevo HTTPS API (' . sh_brevo_api_base() . '/smtp/email)'
        : 'SMTP ' . (string)($transport['host'] ?? '') . ':' . (string)($transport['port'] ?? '')
            . ' (' . (string)($transport['encryption'] ?? '') . ')';
    $text = "This is a real test message from your ShopHaat installation.\n\n"
        . "If you are reading it, the selected transport accepted the message for delivery.\n"
        . "Inbox placement still depends on your SPF, DKIM and DMARC records, which a submission\n"
        . "cannot prove — check the provider's delivery report for that.\n\n"
        . 'Transport: ' . $route . "\n";
    $html = '<p>This is a <strong>real test message</strong> from your ShopHaat installation.</p>'
        . '<p>If you are reading it, the selected transport accepted the message for delivery. '
        . 'Inbox placement still depends on SPF, DKIM and DMARC, which a submission cannot prove — '
        . "check your provider's delivery report for that.</p>"
        . '<p style="font-size:12px;color:#64748b">Transport: ' . htmlspecialchars($route, ENT_QUOTES, 'UTF-8') . '</p>';

    return sh_mail_send($to, $subject, $text, $html, [
        'transport'   => $transport,
        'debug'       => true,
        'log_purpose' => $viaApi ? 'brevo_api_test' : 'smtp_test',
        'log_user_id' => (int)($opts['admin_id'] ?? 0),
    ]);
}

/**
 * Test the selected mail transport without sending a message.
 *
 * SMTP path: DNS -> TCP -> greeting -> EHLO -> STARTTLS advertised ->
 * STARTTLS -> certificate and hostname validation -> encrypted-socket check ->
 * EHLO again -> AUTH. The Brevo API path runs three separate checks: HTTPS/TLS
 * (unauthenticated), the credential in the transactional-email scope
 * (GET /v3/smtp/emails), and account access (GET /v3/account, informational).
 * A 403 on the account resource is reported as a resource permission, never as
 * a failed connection. No message is sent by this function.
 *
 * @param array<string,mixed> $t
 * @return array<string,mixed>
 */
function sh_mail_test_connection(array $t): array
{
    $mode = (string)($t['transport'] ?? ($t['driver'] ?? 'smtp'));
    if ($mode === 'brevo_api') {
        return sh_brevo_api_test_connection($t);
    }
    return sh_mail_test_connection_smtp($t);
}

/** Pull every available error text out of PHPMailer and the PHP warning stream. */
function sh_mail_smtp_error_detail($smtp, array $warnings = [], array $extra = []): string
{
    $parts = [];
    try {
        $err = $smtp->getError();
        if (is_array($err)) {
            $code = trim((string)($err['smtp_code'] ?? ''));
            $error = trim((string)($err['error'] ?? ''));
            $detail = trim((string)($err['detail'] ?? ''));
            if ($code !== '') { $parts[] = 'SMTP ' . $code; }
            if ($error !== '' && $error !== 'Connection failed.') { $parts[] = $error; }
            if ($detail !== '') { $parts[] = $detail; }
        }
    } catch (Throwable $e) {
        // Reporting must never hide the original failure.
    }
    foreach (array_merge($warnings, $extra) as $line) {
        $line = trim((string)$line);
        if ($line !== '') { $parts[] = $line; }
    }
    return sh_mail_redact(implode(' | ', array_values(array_unique($parts))));
}

/**
 * Build the operator-facing cause/action for a TLS stage failure. A hostname
 * mismatch is named as a hostname mismatch; it is never downgraded to a
 * generic "cryptographic negotiation failed".
 *
 * @param array<string,mixed>|null $cert
 * @return array{cause:string,action:string,alternate_host:string}
 */
function sh_mail_tls_failure_text(string $kind, ?array $cert, string $host, string $detail): array
{
    if ($kind === 'hostname') {
        if (is_array($cert) && !empty($cert['ok']) && ($cert['hostname_valid'] ?? null) === false) {
            $diag = sh_mail_certificate_diagnosis($cert, $host);
            return ['cause' => $diag['cause'], 'action' => $diag['action'], 'alternate_host' => $diag['alternate_host']];
        }
        return [
            'cause' => "TLS hostname validation failed: the certificate presented for '{$host}' does not contain that name in its Subject Alternative Names. The underlying OpenSSL/TLS error was: " . ($detail !== '' ? $detail : 'no detail was returned') . '.',
            'action' => 'Keep hostname verification enabled. Use the certificate inspector below to read the actual subject and SANs, then configure a certificate-valid endpoint that the provider supports. Do not disable verification.',
            'alternate_host' => '',
        ];
    }
    if ($kind === 'chain') {
        return [
            'cause' => 'TLS chain validation failed: the certificate could not be verified against the configured CA bundle. ' . ($detail !== '' ? $detail : ''),
            'action' => 'Update the hosting server CA bundle or install the provider-issued intermediate certificate. Do not disable certificate verification.',
            'alternate_host' => '',
        ];
    }
    if ($kind === 'handshake') {
        return [
            'cause' => 'The TLS handshake could not be completed: ' . ($detail !== '' ? $detail : 'the peer closed the connection during negotiation') . '.',
            'action' => 'Confirm the port/encryption pair (587+STARTTLS or 465+implicit TLS) and check whether a hosting firewall is intercepting the connection.',
            'alternate_host' => '',
        ];
    }
    return [
        'cause' => 'The server did not complete STARTTLS: ' . ($detail !== '' ? $detail : 'no SMTP or OpenSSL detail was returned') . '.',
        'action' => 'Check the provider capabilities and the port/encryption pair, then retry. No credentials were sent.',
        'alternate_host' => '',
    ];
}

/**
 * Refine a failed SMTP delivery with a read-only certificate inspection.
 *
 * PHPMailer closes the socket after a failed STARTTLS, which can overwrite the
 * OpenSSL detail with a generic "could not connect" error. When that happens
 * the delivery log would call a hostname mismatch a connection failure. This
 * helper opens one extra inspection connection (no AUTH, no MAIL, no DATA) and
 * reclassifies the result using the certificate that was actually presented.
 *
 * @param array<string,mixed> $result
 * @param array<string,mixed> $t
 * @return array<string,mixed>
 */
function sh_mail_failure_enrich(array $result, array $t): array
{
    if (!empty($result['ok']) || (string)($t['driver'] ?? '') !== 'smtp') { return $result; }
    $category = (string)($result['category'] ?? 'unknown');
    // Precise outcomes need no extra evidence. Only ambiguous transport/TLS
    // failures are inspected.
    if (!in_array($category, ['connection', 'tls', 'unknown'], true)) { return $result; }
    $enc = strtolower((string)($t['encryption'] ?? 'tls'));
    if ($enc === 'none' || !function_exists('openssl_x509_parse')) { return $result; }
    $parsed = sh_mail_parse_host((string)($t['host'] ?? ''), (int)($t['port'] ?? 587));
    if ($parsed['host'] === '') { return $result; }
    $port = (int)($t['port'] ?? 587);
    if ($port < 1 || $port > 65535) { $port = 587; }
    $cert = sh_mail_probe_certificate($parsed['host'], $port, $enc, 5);
    if (empty($cert['ok'])) { return $result; }

    if (($cert['hostname_valid'] ?? null) === false) {
        $diag = sh_mail_certificate_diagnosis($cert, $parsed['host']);
        $result['category'] = 'tls_hostname';
        $result['error'] = 'TLS hostname mismatch: the certificate presented by ' . $parsed['host']
            . ' is not valid for that name. ' . $diag['cause'];
        $result['action'] = $diag['action'];
        $result['alternate_host'] = $diag['alternate_host'];
    } elseif (($cert['chain_valid'] ?? null) === false) {
        $result['category'] = 'tls';
        $result['error'] = 'TLS chain validation failed: ' . ($cert['chain_error'] !== ''
            ? (string)$cert['chain_error'] : 'the certificate is not trusted by the configured CA bundle.');
        $result['action'] = 'Update the CA bundle or install the provider intermediate. Do not disable verification.';
    }
    return $result;
}

/** @param array<string,mixed> $t @return array<string,mixed> */
function sh_mail_test_connection_smtp(array $t): array
{
    $base = [
        'ok' => false, 'step' => 'config', 'transport' => 'smtp',
        'host' => '', 'port' => 0, 'encryption' => '',
        'resolved_ips' => [], 'certificate' => null, 'transcript' => '',
    ];

    $rawHost = trim((string)($t['host'] ?? ''));
    if ($rawHost === '') {
        return array_merge($base, [
            'step' => 'config',
            'error' => 'SMTP host is not configured.',
            'cause' => 'The SMTP host field is empty.',
            'action' => 'Enter the SMTP hostname provided by your email service (for example smtp-relay.brevo.com).',
        ]);
    }
    if (!class_exists(\PHPMailer\PHPMailer\SMTP::class)) {
        return array_merge($base, [
            'step' => 'library',
            'error' => 'PHPMailer SMTP library is not loadable.',
            'cause' => 'The PHPMailer files could not be located in lib/PHPMailer.',
            'action' => 'Ensure the application files under lib/PHPMailer are present and readable.',
        ]);
    }

    $port = (int)($t['port'] ?? 587);
    if ($port < 1 || $port > 65535) { $port = 587; }
    $enc = strtolower((string)($t['encryption'] ?? 'tls'));
    if (!in_array($enc, ['tls', 'ssl', 'none'], true)) { $enc = 'tls'; }
    $mismatch = sh_mail_credential_mismatch(array_merge($t, ['driver' => 'smtp', 'host' => trim((string)($t['host'] ?? ''))]));
    if ($mismatch !== null) {
        return array_merge($base, [
            'step' => 'credentials',
            'category' => 'credential',
            'error' => $mismatch['message'],
            'cause' => 'The stored credential belongs to the other Brevo transport. It was not sent anywhere.',
            'action' => 'Put the API key only in the Brevo API key field, and the SMTP key only in the SMTP password field, then retest. Nothing is switched automatically.',
        ]);
    }
    if ($enc === 'none' && trim((string)($t['username'] ?? '')) !== '') {
        return array_merge($base, [
            'step' => 'config',
            'error' => 'SMTP authentication cannot run without TLS.',
            'cause' => 'A username is configured together with Encryption = None. Sending the password would expose it in plaintext, so the test was stopped before connecting.',
            'action' => 'Choose STARTTLS (port 587) or SSL/TLS (port 465). If the relay genuinely needs no authentication, clear the SMTP username and password instead.',
        ]);
    }
    $parsed = sh_mail_parse_host($rawHost, $port);
    $cleanHost = $parsed['host'];
    if ($cleanHost === '' || str_contains($cleanHost, "\0") || preg_match('~^[A-Za-z0-9._:\-\[\]]+$~', $cleanHost) !== 1) {
        return array_merge($base, [
            'step' => 'config',
            'error' => 'The configured SMTP host is not a valid hostname or IP address.',
            'cause' => 'The host contains characters that cannot be used in a TLS connection.',
            'action' => 'Enter a plain hostname such as smtp-relay.brevo.com, without a path or scheme.',
        ]);
    }
    $base['host'] = $cleanHost;
    $base['port'] = $port;
    $base['encryption'] = strtoupper($enc);

    if ($enc !== 'none' && !extension_loaded('openssl')) {
        return array_merge($base, [
            'step' => 'environment',
            'error' => 'OpenSSL extension is missing in PHP.',
            'cause' => 'Encrypted SMTP (' . strtoupper($enc) . ') requires the PHP OpenSSL extension, but it is not loaded on this server.',
            'action' => 'Enable the openssl PHP extension in your hosting cPanel/php.ini or contact your hosting provider.',
        ]);
    }

    $dns = sh_mail_resolve_host($cleanHost);
    $base['resolved_ips'] = array_merge($dns['ipv4'], $dns['ipv6']);
    $base['peer_ip_resolved'] = $dns['selected'];
    if ($dns['selected'] === '') {
        return array_merge($base, [
            'step' => 'dns',
            'error' => $dns['error'],
            'cause' => "The server could not look up an IP address for '{$cleanHost}'. The hostname may be misspelled or the hosting resolver may be unreachable.",
            'action' => 'Check the spelling of the SMTP host and the hosting DNS resolver. A TLS error cannot be diagnosed until the name resolves.',
        ]);
    }

    $timeout = max(5, min(60, (int)($t['timeout'] ?? 20)));
    $cert = null;
    if ($enc !== 'none') {
        $cert = sh_mail_probe_certificate($cleanHost, $port, $enc, $timeout);
        $base['certificate'] = $cert;
    }

    $transcript = [];
    $warnings = [];
    $smtp = new ShMailDiagnosticSMTP();
    $smtp->Debugoutput = static function ($str, $level) use (&$transcript): void {
        if (count($transcript) < 120) { $transcript[] = (string)$str; }
    };
    $smtp->do_debug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;

    $sslOpts = [
        // Delivery-path authority: chain and name are both verified. There is
        // no configuration switch that relaxes this.
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
        'SNI_enabled' => true,
        'peer_name' => $cleanHost,
    ];
    $caBundle = sh_mail_find_ca_bundle();
    if ($caBundle !== null) { $sslOpts['cafile'] = $caBundle; }
    $options = ['ssl' => $sslOpts];

    $capture = static function (callable $fn) use (&$warnings) {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    };

    try {
        $helloHost = gethostname() ?: 'localhost';
        $uriHost = sh_mail_uri_host($cleanHost);
        $connectHost = ($enc === 'ssl') ? ('ssl://' . $uriHost) : $uriHost;
        while (openssl_error_string() !== false) { /* drain */ }
        $connected = $capture(static fn() => $smtp->connect($connectHost, $port, $timeout, $options));
        if (!$connected) {
            $detail = sh_mail_smtp_error_detail($smtp, $warnings);
            $kind = sh_mail_tls_error_kind($detail . ' ' . (string)($cert['error'] ?? ''));
            $step = 'connect';
            if (stripos($detail, 'getaddrinfo') !== false || stripos($detail, 'name or service not known') !== false
                || stripos($detail, 'nodename nor servname') !== false) {
                $step = 'dns';
            } elseif ($kind === 'hostname') {
                $step = 'tls_hostname';
            } elseif ($kind === 'chain') {
                $step = 'tls_cert';
            } elseif ($kind === 'handshake' || $enc === 'ssl') {
                $step = 'tls_handshake';
            }
            $tls = sh_mail_tls_failure_text($kind, $cert, $cleanHost, $detail);
            return array_merge($base, [
                'step' => $step,
                'error' => 'Could not establish ' . strtoupper($enc) . " connection to {$cleanHost}:{$port}" . ($detail !== '' ? ': ' . $detail : '.'),
                'cause' => $step === 'connect'
                    ? "The TCP connection failed or timed out after {$timeout} seconds. Outbound port {$port} may be blocked by the hosting firewall, or the endpoint is unavailable."
                    : $tls['cause'],
                'action' => $step === 'connect'
                    ? 'Check whether the hosting provider blocks outbound SMTP ports (25, 465, 587). If it does, use the Brevo HTTPS API transport, which only needs outbound HTTPS on port 443.'
                    : $tls['action'],
                'alternate_host' => $tls['alternate_host'],
                'transcript' => sh_mail_redact(implode("\n", $transcript)),
            ]);
        }

        $helloOk = $capture(static fn() => $smtp->hello($helloHost));
        if (!$helloOk) {
            $detail = sh_mail_smtp_error_detail($smtp, $warnings);
            $smtp->quit();
            return array_merge($base, [
                'step' => 'ehlo',
                'error' => "The SMTP server connected but did not accept EHLO" . ($detail !== '' ? ": {$detail}" : '.'),
                'cause' => 'The server connected but rejected the initial EHLO/HELO greeting.',
                'action' => 'Check whether the server requires a fully qualified HELO name or has blocked the hosting IP.',
                'transcript' => sh_mail_redact(implode("\n", $transcript)),
            ]);
        }

        $caps = $smtp->getServerExtList() ?? [];
        if ($enc === 'tls') {
            if (!is_array($caps) || !array_key_exists('STARTTLS', $caps)) {
                $smtp->quit();
                return array_merge($base, [
                    'step' => 'starttls',
                    'error' => 'The server answered EHLO but did not advertise STARTTLS.',
                    'cause' => "STARTTLS is not in the capability list of {$cleanHost}:{$port}.",
                    'action' => $port === 465
                        ? "Port 465 is implicit TLS. Change Encryption to 'SSL/TLS (secure SMTPS, port 465)'."
                        : 'Use the port/encryption pair the provider documents (587+STARTTLS or 465+implicit TLS).',
                    'transcript' => sh_mail_redact(implode("\n", $transcript)),
                ]);
            }

            while (openssl_error_string() !== false) { /* drain */ }
            $tlsOk = $capture(static fn() => $smtp->startTLS());
            if (!$tlsOk) {
                $detail = sh_mail_smtp_error_detail($smtp, $warnings);
                $kind = sh_mail_tls_error_kind($detail);
                // A correctly configured inspector is authoritative when the
                // OpenSSL wording is ambiguous on a particular PHP build.
                if ($kind === 'unknown' && is_array($cert) && !empty($cert['ok']) && ($cert['hostname_valid'] ?? null) === false) {
                    $kind = 'hostname';
                }
                $step = match ($kind) {
                    'hostname' => 'tls_hostname',
                    'chain' => 'tls_cert',
                    'handshake' => 'tls_handshake',
                    default => 'starttls',
                };
                $tls = sh_mail_tls_failure_text($kind, $cert, $cleanHost, $detail);
                $smtp->quit();
                return array_merge($base, [
                    'step' => $step,
                    'category' => $kind === 'hostname' ? 'tls_hostname' : 'tls',
                    'error' => 'STARTTLS failed for ' . $cleanHost . ':' . $port . ': ' . ($detail !== '' ? $detail : 'no OpenSSL detail was returned') . '.',
                    'cause' => $tls['cause'],
                    'action' => $tls['action'],
                    'alternate_host' => $tls['alternate_host'],
                    'transcript' => sh_mail_redact(implode("\n", $transcript)),
                ]);
            }

            // Prove the socket is actually encrypted before sending credentials.
            $meta = $smtp->socketMeta();
            if (empty($meta['crypto']) || !is_array($meta['crypto'])) {
                $smtp->quit();
                return array_merge($base, [
                    'step' => 'tls',
                    'error' => 'STARTTLS returned success but the socket is not encrypted. Authentication was not attempted.',
                    'cause' => 'The TLS state could not be confirmed on the live socket.',
                    'action' => 'Retry the test. If it repeats, check the PHP/OpenSSL build on the hosting server. Do not authenticate over an unverified channel.',
                    'transcript' => sh_mail_redact(implode("\n", $transcript)),
                ]);
            }
            $base['tls_protocol'] = (string)($meta['crypto']['protocol'] ?? '');
            $base['cipher'] = trim((string)($meta['crypto']['cipher_name'] ?? '') . ' ' . (string)($meta['crypto']['cipher_bits'] ?? ''));

            // RFC 3207: re-issue EHLO after the TLS layer starts.
            $ehloTls = $capture(static fn() => $smtp->hello($helloHost));
            if (!$ehloTls) {
                $detail = sh_mail_smtp_error_detail($smtp, $warnings);
                $smtp->quit();
                return array_merge($base, [
                    'step' => 'ehlo_tls',
                    'error' => 'TLS was established but the post-STARTTLS EHLO failed' . ($detail !== '' ? ': ' . $detail : '.'),
                    'cause' => 'The encrypted session started but the server did not complete the second EHLO.',
                    'action' => 'Retry; if it persists, check the provider status and the hosting firewall for SMTP inspection.',
                    'transcript' => sh_mail_redact(implode("\n", $transcript)),
                ]);
            }
            $caps = $smtp->getServerExtList() ?? [];
        }

        $user = trim((string)($t['username'] ?? ''));
        $pass = (string)($t['password'] ?? '');
        if ($user !== '') {
            if ($pass === '') {
                $smtp->quit();
                return array_merge($base, [
                    'step' => 'auth',
                    'error' => 'SMTP username is set but the password is empty.',
                    'cause' => 'A username was configured without a password.',
                    'action' => 'Enter the SMTP password or SMTP key, save it, and retry.',
                    'transcript' => sh_mail_redact(implode("\n", $transcript)),
                ]);
            }
            $authOk = $capture(static fn() => $smtp->authenticate($user, $pass));
            if (!$authOk) {
                $detail = sh_mail_smtp_error_detail($smtp, $warnings);
                $smtp->quit();
                return array_merge($base, [
                    'step' => 'auth',
                    'category' => 'auth',
                    'error' => 'SMTP authentication failed' . ($detail !== '' ? ': ' . $detail : '.'),
                    'cause' => "The encrypted channel and certificate validation succeeded, but {$cleanHost} rejected the credentials.",
                    'action' => 'Use the SMTP login and SMTP key from Brevo → SMTP & API → SMTP (not the account password and not the v3 API key). For Gmail/Microsoft, use an app password.',
                    'transcript' => sh_mail_redact(implode("\n", $transcript)),
                ]);
            }
        }

        $smtp->quit();
        $transportText = strtoupper($enc);
        if (($base['tls_protocol'] ?? '') !== '') {
            $transportText .= ' / ' . $base['tls_protocol'] . ' (' . $base['cipher'] . ')';
        }
        return array_merge($base, [
            'ok' => true,
            'step' => 'complete',
            'category' => 'ok',
            'message' => "Connection successful: {$cleanHost}:{$port} over {$transportText}"
                . ($user !== '' ? " and authenticated as {$user}" : ' without authentication') . '. No message was sent.',
            'auth' => $user !== '' ? "Authenticated as {$user}" : 'No authentication configured',
            'certificate_valid' => is_array($cert) ? (($cert['hostname_valid'] ?? null) === true && ($cert['chain_valid'] ?? null) !== false) : null,
            'transcript' => sh_mail_redact(implode("\n", $transcript)),
        ]);
    } catch (Throwable $e) {
        return array_merge($base, [
            'step' => 'exception',
            'error' => sh_mail_redact($e->getMessage()),
            'cause' => 'Unexpected exception during the SMTP connection test.',
            'action' => 'Check the application log on the hosting server and retry. The exception text above is redacted.',
            'transcript' => sh_mail_redact(implode("\n", $transcript)),
        ]);
    }
}
