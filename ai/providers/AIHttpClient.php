<?php
/**
 * AI Auto Work — shared HTTPS client for every provider adapter.
 *
 * One place for timeouts, TLS verification, error classification and the
 * "never throw" guarantee. Marks failures as retryable when a fallback provider
 * is a sensible next step (network error, timeout, 408/429/5xx).
 */
final class AIHttpClient
{
    /**
     * @param array<int,string> $headers  "Name: value" strings
     * @return array{ok:bool,status:int,json:?array,raw:string,error:string,retryable:bool}
     */
    public static function request(string $method, string $url, array $headers, ?array $payload = null, int $timeout = 90): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return self::fail(0, 'The provider base URL is invalid.', false);
        }
        $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload !== null && $body !== null) { $headers[] = 'Content-Type: application/json'; }
        $headers[] = 'Accept: application/json';
        $headers[] = 'User-Agent: ShopHaat-AI/1.0';

        $raw = ''; $status = 0; $netErr = ''; $timedOut = false;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
            $out = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $netErr = (string)curl_error($ch);
            $timedOut = $errno === CURLE_OPERATION_TIMEOUTED;
            curl_close($ch);
            $raw = $out === false ? '' : (string)$out;
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => $method, 'header' => implode("\r\n", $headers),
                'content' => $body ?? '', 'timeout' => $timeout, 'ignore_errors' => true,
            ]]);
            set_error_handler(static fn(): bool => true);
            try { $out = file_get_contents($url, false, $ctx); } finally { restore_error_handler(); }
            $raw = $out === false ? '' : (string)$out;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $status = (int)$m[1];
            }
            if ($out === false) { $netErr = 'network'; }
        }

        if ($status === 0) {
            return self::fail(0, $timedOut ? 'The AI provider timed out.' : 'Could not reach the AI provider.', true);
        }

        $json = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $msg = '';
            if (is_array($json)) {
                $msg = (string)($json['error']['message'] ?? $json['error']['error']['message'] ?? $json['message'] ?? $json['error'] ?? '');
                if (is_array($json['error'] ?? null) && $msg === '') { $msg = (string)($json['error']['type'] ?? ''); }
            }
            return self::fail($status, self::friendly($status, $msg), in_array($status, [408, 409, 425, 429], true) || $status >= 500, is_array($json) ? $json : null, $raw);
        }
        if (!is_array($json)) {
            return self::fail($status, 'The AI provider returned an unreadable response.', true, null, $raw);
        }
        return ['ok' => true, 'status' => $status, 'json' => $json, 'raw' => $raw, 'error' => '', 'retryable' => false];
    }

    public static function get(string $url, array $headers, int $timeout = 30): array
    {
        return self::request('GET', $url, $headers, null, $timeout);
    }

    public static function post(string $url, array $headers, array $payload, int $timeout = 90): array
    {
        return self::request('POST', $url, $headers, $payload, $timeout);
    }

    /** Download a generated asset over HTTPS only. */
    public static function download(string $url, int $timeout = 120): ?string
    {
        if (!preg_match('#^https://#i', $url)) { return null; }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $out = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($out !== false && $code === 200 && $out !== '') ? (string)$out : null;
        }
        set_error_handler(static fn(): bool => true);
        try { $out = file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout]])); }
        finally { restore_error_handler(); }
        return ($out === false || $out === '') ? null : (string)$out;
    }

    /** Decode a data: URL or base64 string into bytes + mime. */
    public static function decodeImageData(string $data): ?array
    {
        $mime = 'image/png';
        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', $data, $m)) { $mime = strtolower($m[1]); $data = $m[2]; }
        $bin = base64_decode(preg_replace('/\s+/', '', $data) ?? '', true);
        if ($bin === false || $bin === '') { return null; }
        return ['binary' => $bin, 'mime' => $mime];
    }

    private static function fail(int $status, string $error, bool $retryable, ?array $json = null, string $raw = ''): array
    {
        return ['ok' => false, 'status' => $status, 'json' => $json, 'raw' => $raw, 'error' => $error, 'retryable' => $retryable];
    }

    /** Turn a provider error into something an admin can act on. */
    public static function friendly(int $status, string $message): string
    {
        $message = trim(mb_substr($message, 0, 300));
        return match (true) {
            $status === 401 => 'The API key was rejected by the provider.' . ($message !== '' ? ' (' . $message . ')' : ''),
            $status === 402 => 'The provider reports insufficient credit or a billing problem.' . ($message !== '' ? ' (' . $message . ')' : ''),
            $status === 403 => 'This API key is not allowed to use that model.' . ($message !== '' ? ' (' . $message . ')' : ''),
            $status === 404 => 'That model or endpoint was not found.' . ($message !== '' ? ' (' . $message . ')' : ''),
            $status === 429 => 'Rate limit or quota reached at the provider.' . ($message !== '' ? ' (' . $message . ')' : ''),
            $status >= 500  => 'The AI provider is temporarily unavailable (HTTP ' . $status . ').',
            default         => $message !== '' ? $message : 'The AI request failed (HTTP ' . $status . ').',
        };
    }
}
