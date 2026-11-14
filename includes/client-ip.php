<?php
/**
 * Client IP resolution with an explicit trusted-proxy model.
 *
 * Why this file exists
 * --------------------
 * The security of every rate limiter in this application — OTP per-IP limits,
 * customer/admin login throttling, support-chat limits, the installer unlock and
 * the CSP report collector — depends on knowing the real client address. The
 * forwarding headers a request carries (`CF-Connecting-IP`, `X-Forwarded-For`)
 * are supplied by the caller and are therefore attacker-controlled unless the
 * request provably arrived through a proxy the owner configured.
 *
 * The rule implemented here is deliberately narrow:
 *
 *   1. The address that opened the TCP connection (`REMOTE_ADDR`) is the only
 *      value the web server itself guarantees.
 *   2. A forwarding header is consulted ONLY when `REMOTE_ADDR` belongs to the
 *      configured trusted-proxy list (single IPs or CIDR ranges).
 *   3. Every address taken from a header must be a syntactically valid, public
 *      IP address; anything else is ignored rather than guessed at.
 *   4. An X-Forwarded-For chain is walked from right to left (closest hop to
 *      this server first) and the first address that is neither trusted nor
 *      malformed wins — the standard, spoof-resistant interpretation.
 *   5. The `Forwarded` header is intentionally not supported: it is rare on
 *      shared hosting, and a second, less-tested parser is an extra attack
 *      surface for no benefit.
 *   6. With no trusted list configured, the policy is `REMOTE_ADDR` only. That
 *      is the safe default; a Cloudflare or load-balanced deployment must
 *      configure its ranges (see sh_cloudflare_ranges() for a live-fetched,
 *      cached list — nothing is hardcoded).
 *
 * Settings (all optional, read from the database when available):
 *   verify_proxy_mode       auto | none | cloudflare | xff   (default auto)
 *   verify_trusted_proxies  one IP or CIDR per line/space/comma
 *
 * Constants or environment variables can seed the same values before the
 * database is reachable: SH_PROXY_MODE, SH_TRUSTED_PROXIES.
 *
 * This file intentionally has no dependency on the database, sessions or any
 * other include, so it can be unit-tested on its own and loaded first.
 */

if (!function_exists('sh_ip_normalize')) {
    /**
     * Canonicalise one address, or '' when it is not a valid IP.
     *
     * Accepts the forms that actually appear in headers: bare addresses,
     * "[2001:db8::1]:443" and "203.0.113.7:51234". Never returns a hostname:
     * a name would mean a DNS lookup on every request and could be attacker
     * influenced.
     */
    function sh_ip_normalize(?string $ip): string
    {
        $ip = trim((string)$ip);
        if ($ip === '') { return ''; }
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d{1,5})?$/', $ip, $m) === 1) {
            $ip = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}$/', $ip, $m) === 1) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) { return ''; }
        return strtolower($ip);
    }
}

if (!function_exists('sh_ip_in_cidr')) {
    /** Is $ip inside $cidr (or exactly equal to a plain address)? */
    function sh_ip_in_cidr(string $ip, string $cidr): bool
    {
        $ip = sh_ip_normalize($ip);
        $cidr = trim($cidr);
        if ($ip === '' || $cidr === '') { return false; }
        if (strpos($cidr, '/') === false) {
            return $ip === sh_ip_normalize($cidr);
        }
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '');
        $subnet = sh_ip_normalize($subnet);
        if ($subnet === '' || !ctype_digit((string)$bits)) { return false; }
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            // An IPv4 address can never be inside an IPv6 range and vice versa.
            return false;
        }
        $bits = (int)$bits;
        $total = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $total) { return false; }
        $wholeBytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($wholeBytes > 0 && substr($ipBin, 0, $wholeBytes) !== substr($subBin, 0, $wholeBytes)) {
            return false;
        }
        if ($remainder === 0) { return true; }
        $mask = 0xFF << (8 - $remainder) & 0xFF;
        return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subBin[$wholeBytes]) & $mask);
    }
}

if (!function_exists('sh_ip_is_public')) {
    /**
     * Is this a publicly routable address?
     *
     * Applied to every address that came from a header: a forwarder must not be
     * able to claim 127.0.0.1, 10.0.0.0/8 or a CGNAT address and land in a
     * bucket that a firewall, a geo check or a host allowlist treats specially.
     */
    function sh_ip_is_public(string $ip): bool
    {
        $ip = sh_ip_normalize($ip);
        if ($ip === '') { return false; }
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}

if (!function_exists('sh_proxy_setting')) {
    /** Read a proxy setting, tolerating a database that is not reachable yet. */
    function sh_proxy_setting(string $key, string $default = ''): string
    {
        if (!function_exists('sh_setting')) { return $default; }
        try {
            $v = sh_setting($key, $default);
            return is_string($v) ? $v : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('sh_proxy_mode')) {
    /** auto | none | cloudflare | xff — unknown values fall back to the safe 'auto'. */
    function sh_proxy_mode(): string
    {
        $mode = '';
        if (defined('SH_PROXY_MODE')) { $mode = (string)SH_PROXY_MODE; }
        if ($mode === '') {
            $env = getenv('SH_PROXY_MODE');
            if (is_string($env) && $env !== '') { $mode = $env; }
        }
        if ($mode === '') { $mode = sh_proxy_setting('verify_proxy_mode', 'auto'); }
        $mode = strtolower(trim($mode));
        return in_array($mode, ['auto', 'none', 'cloudflare', 'xff'], true) ? $mode : 'auto';
    }
}

if (!function_exists('sh_trusted_proxy_entries')) {
    /**
     * The configured trusted-proxy list, validated.
     *
     * Entries that are not an IP address or a CIDR range are dropped silently:
     * a typo must never widen the trust boundary, and it must never be echoed
     * back to a visitor.
     *
     * @return array<int,string>
     */
    function sh_trusted_proxy_entries(): array
    {
        $raw = '';
        if (defined('SH_TRUSTED_PROXIES') && SH_TRUSTED_PROXIES !== '') {
            $raw = (string)SH_TRUSTED_PROXIES;
        } elseif (($env = getenv('SH_TRUSTED_PROXIES')) !== false && $env !== '') {
            $raw = (string)$env;
        } else {
            $raw = sh_proxy_setting('verify_trusted_proxies', '');
        }
        if (trim($raw) === '') { return []; }
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') { continue; }
            if (strpos($entry, '/') !== false) {
                [$net, $bits] = array_pad(explode('/', $entry, 2), 2, '');
                $net = sh_ip_normalize($net);
                if ($net === '' || !ctype_digit((string)$bits)) { continue; }
                $max = strpos($net, ':') !== false ? 128 : 32;
                if ((int)$bits < 0 || (int)$bits > $max) { continue; }
                $out[] = $net . '/' . (int)$bits;
                continue;
            }
            $ip = sh_ip_normalize($entry);
            if ($ip !== '') { $out[] = $ip; }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('sh_ip_is_trusted_proxy')) {
    function sh_ip_is_trusted_proxy(string $ip, ?array $entries = null): bool
    {
        $ip = sh_ip_normalize($ip);
        if ($ip === '') { return false; }
        foreach ($entries ?? sh_trusted_proxy_entries() as $entry) {
            if (sh_ip_in_cidr($ip, $entry)) { return true; }
        }
        return false;
    }
}

if (!function_exists('sh_cloudflare_ranges')) {
    /**
     * Cloudflare's published edge ranges, fetched from Cloudflare and cached.
     *
     * Deliberately NOT a hardcoded list: Cloudflare adds ranges over time and a
     * stale copy would silently stop trusting (or start trusting) the wrong
     * addresses. The result is cached for a week under logs/cache/, which the
     * web server denies, and a failed refresh reuses the previous copy. When
     * neither exists an empty list is returned, which means "trust nothing",
     * the safe outcome.
     *
     * @return array<int,string>
     */
    function sh_cloudflare_ranges(bool $refresh = false): array
    {
        static $memory = null;
        if ($memory !== null && !$refresh) { return $memory; }

        $dir = defined('SH_LOG_DIR') ? SH_LOG_DIR . '/cache' : '';
        $file = $dir !== '' ? $dir . '/cloudflare-ranges.txt' : '';
        $ttl = 604800; // 7 days

        if ($file !== '' && !$refresh && is_file($file) && (time() - (int)@filemtime($file)) < $ttl) {
            $cached = sh_cloudflare_parse_ranges((string)@file_get_contents($file));
            if ($cached !== []) { return $memory = $cached; }
        }

        $fetched = [];
        foreach (['https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6'] as $url) {
            $body = sh_cloudflare_fetch($url);
            if ($body !== '') {
                $fetched = array_merge($fetched, sh_cloudflare_parse_ranges($body));
            }
        }
        $fetched = array_values(array_unique($fetched));
        if ($fetched !== []) {
            if ($file !== '') {
                if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                $tmp = $file . '.' . bin2hex(random_bytes(4));
                if (@file_put_contents($tmp, implode("\n", $fetched) . "\n") !== false) {
                    @rename($tmp, $file);
                } else {
                    @unlink($tmp);
                }
            }
            return $memory = $fetched;
        }

        // Refresh failed: a previously cached copy is still better than nothing.
        if ($file !== '' && is_file($file)) {
            $cached = sh_cloudflare_parse_ranges((string)@file_get_contents($file));
            if ($cached !== []) { return $memory = $cached; }
        }
        return $memory = [];
    }
}

if (!function_exists('sh_cloudflare_parse_ranges')) {
    /** Keep only valid CIDR lines from a Cloudflare range file. @return array<int,string> */
    function sh_cloudflare_parse_ranges(string $body): array
    {
        $out = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '/') === false) { continue; }
            [$net, $bits] = array_pad(explode('/', $line, 2), 2, '');
            $net = sh_ip_normalize($net);
            if ($net === '' || !ctype_digit((string)$bits)) { continue; }
            $max = strpos($net, ':') !== false ? 128 : 32;
            if ((int)$bits > $max) { continue; }
            $out[] = $net . '/' . (int)$bits;
        }
        return $out;
    }
}

if (!function_exists('sh_cloudflare_fetch')) {
    /** Small, bounded HTTPS GET used only for the range list. */
    function sh_cloudflare_fetch(string $url): string
    {
        if (!preg_match('~^https://~i', $url)) { return ''; }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) { return ''; }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS      => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status === 200 && is_string($body) && strlen($body) < 20000) { return $body; }
            return '';
        }
        $ctx = stream_context_create(['http' => [
            'timeout' => 5, 'follow_location' => 0, 'ignore_errors' => false,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp = @fopen($url, 'rb', false, $ctx);
        if ($fp === false) { return ''; }
        $meta = stream_get_meta_data($fp);
        $body = (string)stream_get_contents($fp, 20000);
        fclose($fp);
        $headers = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
        if (!isset($headers[0]) || preg_match('~\s200\s~', (string)$headers[0]) !== 1) { return ''; }
        return $body;
    }
}

if (!function_exists('sh_ip_chain_pick')) {
    /**
     * Pick the client address out of an X-Forwarded-For chain.
     *
     * Right-to-left: the entry nearest this server is the only one a single
     * proxy appended, so it is the first value that can be believed. Entries
     * that are trusted proxies are skipped, as are malformed and non-public
     * values. Everything to the left of the first believable entry is ignored.
     *
     * @param array<int,string> $chain
     * @param array<int,string> $trusted
     */
    function sh_ip_chain_pick(array $chain, array $trusted): string
    {
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $ip = sh_ip_normalize($chain[$i]);
            if ($ip === '' || !sh_ip_is_public($ip)) { continue; }
            if (sh_ip_is_trusted_proxy($ip, $trusted)) { continue; }
            return $ip;
        }
        return '';
    }
}

if (!function_exists('sh_client_ip')) {
    /**
     * The real client address for this request.
     *
     * Never throws, never returns a hostname, and never trusts a forwarding
     * header unless the immediate peer is a configured proxy.
     */
    function sh_client_ip(): string
    {
        $remote = sh_ip_normalize((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote === '') { return '0.0.0.0'; }

        $mode = sh_proxy_mode();
        if ($mode === 'none') { return $remote; }

        $trusted = sh_trusted_proxy_entries();
        if ($trusted === [] && $mode === 'cloudflare') {
            // Only this mode performs a network fetch, and only when the owner
            // asked for Cloudflare handling explicitly.
            $trusted = sh_cloudflare_ranges();
        }
        if ($trusted === [] || !sh_ip_is_trusted_proxy($remote, $trusted)) {
            // No policy, or the request did not arrive through a trusted hop:
            // the peer address is the only trustworthy value.
            return $remote;
        }

        if ($mode === 'auto' || $mode === 'cloudflare') {
            $cf = sh_ip_normalize((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
            if ($cf !== '' && sh_ip_is_public($cf)) { return $cf; }
        }

        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $pick = sh_ip_chain_pick(array_map('trim', explode(',', $xff)), $trusted);
            if ($pick !== '') { return $pick; }
        }

        return $remote;
    }
}

if (!function_exists('sh_forwarded_proto_trusted')) {
    /**
     * May `X-Forwarded-Proto` be believed for this request?
     *
     * Only from a configured trusted proxy. Hosts that never configured a proxy
     * keep the historical behaviour (the header is believed) so an existing
     * cPanel/Cloudflare deployment cannot be broken by this change; configuring
     * a proxy list makes it strict. Documented in the deployment checklist.
     */
    function sh_forwarded_proto_trusted(): bool
    {
        $trusted = sh_trusted_proxy_entries();
        if ($trusted === []) { return true; }
        return sh_ip_is_trusted_proxy((string)($_SERVER['REMOTE_ADDR'] ?? ''), $trusted);
    }
}
