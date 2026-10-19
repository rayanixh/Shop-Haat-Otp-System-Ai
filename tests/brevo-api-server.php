<?php
/**
 * Test-only Brevo v3 API stub over HTTPS.
 *
 * Usage: php tests/brevo-api-server.php <port> <cert.pem> <key.pem> <output-dir>
 *
 * Accepts a valid test key (test-api-key) and answers GET /v3/account and
 * POST /v3/smtp/email. Any other key gets 401. It records the request path and
 * payload summary, never the api-key header value.
 */
declare(strict_types=1);

$port = (int)($argv[1] ?? 0);
$cert = (string)($argv[2] ?? '');
$key = (string)($argv[3] ?? '');
$dir = (string)($argv[4] ?? sys_get_temp_dir());
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
$server = @stream_socket_server('ssl://127.0.0.1:' . $port, $errno, $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
    fwrite(STDERR, 'listen failed: ' . $errstr . "\n");
    exit(1);
}
@file_put_contents($dir . '/ready', '1');

$respond = static function ($conn, int $status, string $body): void {
    $reason = $status === 201 ? 'Created' : ($status === 200 ? 'OK' : ($status === 401 ? 'Unauthorized' : 'Not Found'));
    $raw = "HTTP/1.1 {$status} {$reason}\r\n"
        . "Content-Type: application/json\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\n"
        . "Connection: close\r\n\r\n" . $body;
    fwrite($conn, $raw);
};

$deadline = time() + 90;
$handled = 0;
while (time() < $deadline && $handled < 6) {
    $conn = @stream_socket_accept($server, 5);
    if ($conn === false) { continue; }
    $handled++;
    stream_set_timeout($conn, 10);
    $request = '';
    while (!str_contains($request, "\r\n\r\n") && ($line = fgets($conn, 8192)) !== false) {
        $request .= $line;
    }
    [$head, $body] = array_pad(explode("\r\n\r\n", $request, 2), 2, '');
    $lines = explode("\r\n", $head);
    $requestLine = (string)array_shift($lines);
    $headers = [];
    foreach ($lines as $headerLine) {
        if (str_contains($headerLine, ':')) {
            [$k, $v] = explode(':', $headerLine, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    $length = (int)($headers['content-length'] ?? 0);
    if ($length > 0 && strlen($body) < $length) {
        $remaining = $length - strlen($body);
        while ($remaining > 0 && ($chunk = fread($conn, min(8192, $remaining))) !== false && $chunk !== '') {
            $body .= $chunk;
            $remaining -= strlen($chunk);
        }
    }
    $method = 'GET';
    $path = '/';
    if (preg_match('~^([A-Z]+)\s+(\S+)~', $requestLine, $m)) { $method = $m[1]; $path = $m[2]; }
    $apiKey = (string)($headers['api-key'] ?? '');
    $entry = ['method' => $method, 'path' => $path, 'key_present' => $apiKey !== '', 'authorized' => $apiKey === 'test-api-key'];
    $payload = json_decode($body, true);
    if (is_array($payload)) {
        $entry['recipient'] = (string)($payload['to'][0]['email'] ?? '');
        $entry['subject'] = (string)($payload['subject'] ?? '');
        $entry['has_text'] = !empty($payload['textContent']);
    }
    @file_put_contents($dir . '/request-' . $handled . '.json', json_encode($entry, JSON_PRETTY_PRINT));

    if ($apiKey !== 'test-api-key') {
        $respond($conn, 401, '{"message":"authentication not found in headers","code":"unauthorized"}');
    } elseif ($method === 'GET' && $path === '/v3/account') {
        $respond($conn, 200, '{"companyName":"ShopHaat TLS Test","email":"test@example.test"}');
    } elseif ($method === 'POST' && $path === '/v3/smtp/email') {
        $respond($conn, 201, '{"messageId":"<test-message@brevo>"}');
    } else {
        $respond($conn, 404, '{"message":"not found","code":"not_found"}');
    }
    @fclose($conn);
}
@fclose($server);
@file_put_contents($dir . '/done', '1');
exit(0);
