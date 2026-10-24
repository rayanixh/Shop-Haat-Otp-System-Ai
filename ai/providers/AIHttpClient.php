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
    /** Optional per-request caps used by the support chat. */
    private static ?array $requestLimits = null;

    /** Temporarily bound a provider call without changing ordinary AI requests. */
    public static function configureLimits(int $timeoutSeconds = 15, int $responseBytes = 65536): ?array
    {
        $previous = self::$requestLimits;
        self::$requestLimits = [
            'timeout' => max(1, min(60, $timeoutSeconds)),
            'response_bytes' => max(1024, min(1048576, $responseBytes)),
        ];
        return $previous;
    }

    public static function restoreLimits(?array $previous): void
    {
        self::$requestLimits = $previous;
    }

    /**
     * @param array<int,string> $headers  "Name: value" strings
     * @return array{ok:bool,status:int,json:?array,raw:string,error:string,retryable:bool}
     */
    public static function request(string $method, string $url, array $headers, ?array $payload = null, int $timeout = 90): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return self::fail(0, 'The provider base URL is invalid.', false, null, '', 'invalid_endpoint');
        }
        $limits = self::$requestLimits;
        if ($limits !== null) { $timeout = min($timeout, (int)$limits['timeout']); }
        $maxResponseBytes = $limits['response_bytes'] ?? null;
        $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload !== null && $body === false) {
            return self::fail(0, 'The AI request could not be encoded.', false);
        }
        if ($payload !== null && $body !== null) { $headers[] = 'Content-Type: application/json'; }
        $headers[] = 'Accept: application/json';
        $headers[] = 'User-Agent: ShopHaat-AI/1.0';

        $raw = ''; $status = 0; $timedOut = false; $tooLarge = false; $streamError = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => $maxResponseBytes === null,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => max(1, $timeout),
                CURLOPT_CONNECTTIMEOUT => max(1, min(15, $timeout)),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ];
            if ($maxResponseBytes !== null) {
                $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$raw, &$tooLarge, $maxResponseBytes): int {
                    $length = strlen($chunk);
                    if (strlen($raw) + $length > $maxResponseBytes) { $tooLarge = true; return 0; }
                    $raw .= $chunk;
                    return $length;
                };
            }
            curl_setopt_array($ch, $options);
            if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
            $out = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $timeoutErrnos = [];
            foreach (['CURLE_OPERATION_TIMEDOUT', 'CURLE_OPERATION_TIMEOUTED'] as $constant) {
                if (defined($constant)) { $timeoutErrnos[] = (int)constant($constant); }
            }
            $timedOut = $timeoutErrnos !== [] && in_array($errno, $timeoutErrnos, true);
            curl_close($ch);
            if ($maxResponseBytes === null) { $raw = $out === false ? '' : (string)$out; }
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => $method, 'header' => implode("\r\n", $headers),
                'content' => $body ?? '', 'timeout' => max(1, $timeout), 'ignore_errors' => true,
                'follow_location' => 0, 'max_redirects' => 0,
            ]]);
            if (function_exists('error_clear_last')) { error_clear_last(); }
            set_error_handler(static fn(): bool => true);
            try {
                if ($maxResponseBytes === null) {
                    $out = file_get_contents($url, false, $ctx);
                    $raw = $out === false ? '' : (string)$out;
                } else {
                    $stream = fopen($url, 'rb', false, $ctx);
                    $out = $stream === false ? false : stream_get_contents($stream, $maxResponseBytes + 1);
                    if (is_resource($stream)) { fclose($stream); }
                    $raw = $out === false ? '' : (string)$out;
                    if (strlen($raw) > $maxResponseBytes) { $tooLarge = true; $raw = ''; }
                }
                $lastError = error_get_last();
                $streamError = is_array($lastError) ? (string)($lastError['message'] ?? '') : '';
                $timedOut = preg_match('/timed?\s*out|timeout/i', $streamError) === 1;
            } finally { restore_error_handler(); }
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $status = (int)$m[1];
            }
        }

        if ($tooLarge) {
            return self::fail($status, 'The AI provider response exceeded the configured safety limit.', false, null, '', 'invalid_response');
        }
        if ($status === 0) {
            $error = $timedOut ? 'The AI provider timed out.' : 'Could not reach the AI provider.';
            return self::fail(0, $error, true, null, '', $timedOut ? 'timeout' : 'network_error');
        }

        $json = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $msg = '';
            if (is_array($json)) {
                $msg = (string)($json['error']['message'] ?? $json['error']['error']['message'] ?? $json['message'] ?? $json['error'] ?? '');
                if (is_array($json['error'] ?? null) && $msg === '') { $msg = (string)($json['error']['type'] ?? ''); }
            }
            $friendly = self::friendly($status, $msg);
            return self::fail($status, $friendly, in_array($status, [408, 409, 425, 429], true) || $status >= 500,
                is_array($json) ? $json : null, $raw, self::diagnosticCode($status, $friendly));
        }
        if (!is_array($json)) {
            return self::fail($status, 'The AI provider returned an unreadable response.', false, null, '', 'invalid_response');
        }
        return ['ok' => true, 'status' => $status, 'json' => $json, 'raw' => $raw, 'error' => '', 'retryable' => false, 'code' => 'connected'];
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

    private static function fail(int $status, string $error, bool $retryable, ?array $json = null, string $raw = '', ?string $code = null): array
    {
        return ['ok' => false, 'status' => $status, 'json' => $json, 'raw' => $raw, 'error' => $error,
                'retryable' => $retryable, 'code' => $code ?? self::diagnosticCode($status, $error)];
    }

    /**
     * Stable machine code for a test outcome, from an HTTP status.
     *
     * "network_error" is status 0 (nothing came back). The codes are stored on
     * ai_providers.last_test_code and rendered as an admin-facing state, so the
     * Providers screen can say "authentication failed" instead of showing a
     * generic failure for every problem.
     */
    public static function classify(int $status): string
    {
        return match (true) {
            $status === 0   => 'network_error',
            $status === 401 => 'auth_failed',
            $status === 402 => 'billing',
            $status === 403 => 'permission_denied',
            $status === 404 => 'model_not_found',
            $status === 408 => 'timeout',
            $status === 429 => 'rate_limited',
            $status >= 500  => 'provider_error',
            $status >= 400  => 'failed',
            default         => 'connected',
        };
    }

    /**
     * A more specific inference diagnostic than the legacy UI status bucket.
     * Provider text is inspected only to classify the failure; callers decide
     * separately whether any detail is safe to retain or display.
     */
    public static function diagnosticCode(int $status, string $message = ''): string
    {
        $text = strtolower($message);
        $normalized = preg_replace('/[_-]+/', ' ', $text) ?? $text;
        if (preg_match('/timed?\s*out|timeout/i', $message) === 1) { return 'timeout'; }
        if ($status === 0) { return 'network_error'; }
        if ($status === 401 || preg_match('/unauthori[sz]ed|authentication (?:error|failed)|auth(?:entication)? (?:error|failed)|invalid (?:api )?key|invalid bearer|expired (?:api )?key|invalid token/i', $normalized) === 1) { return 'auth_failed'; }
        if (preg_match('/quota|billing|insufficient credit|credit balance/i', $normalized) === 1 || $status === 402) { return 'quota_exceeded'; }
        if ($status === 403 || preg_match('/permission (?:denied|error)|forbidden|not permitted|not allowed to use|access denied/i', $normalized) === 1) { return 'permission_denied'; }
        if (preg_match('/rate limit|too many requests|throttl/i', $normalized) === 1 || $status === 429) { return 'rate_limited'; }
        if ($status === 404) {
            return preg_match('/endpoint|route|path/i', $normalized) === 1 && preg_match('/model/i', $normalized) !== 1
                ? 'request_rejected' : 'model_not_found';
        }
        if ($status === 408) { return 'timeout'; }
        if ($status >= 500) { return 'provider_error'; }
        if (in_array($status, [400, 422], true)) {
            if (preg_match('/(?:model|deployment|engine).{0,80}(?:not found|unsupported|unavailable|invalid|does not exist|not supported)|(?:not found|unsupported|unavailable|invalid|does not exist|not supported).{0,80}(?:model|deployment|engine)/i', $normalized) === 1) {
                return 'model_not_found';
            }
            return 'request_rejected';
        }
        if ($status >= 400) { return 'request_rejected'; }
        return 'provider_error';
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
