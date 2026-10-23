<?php
/**
 * AI Auto Work — shared plumbing for every provider adapter.
 *
 * An adapter is built from one ai_providers row. The API key is decrypted
 * lazily at request time and is never returned by any public method.
 */
require_once __DIR__ . '/AIProviderInterface.php';
require_once __DIR__ . '/AIHttpClient.php';

abstract class AbstractAIProvider implements AIProviderInterface
{
    protected array $row;

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function id(): int { return (int)($this->row['id'] ?? 0); }
    public function name(): string { return (string)($this->row['name'] ?? static::defaultLabel()); }
    public function driver(): string { return static::driverKey(); }

    /** Machine key of the driver ("openai", "openrouter", ...). */
    abstract public static function driverKey(): string;

    /** Human label of the driver for the admin UI. */
    abstract public static function defaultLabel(): string;

    /** Base URL used when the admin leaves the field blank. */
    abstract public static function defaultBaseUrl(): string;

    /** Sensible starting models when nothing else is known. */
    abstract public static function starterModels(): array;

    /** Does the driver take a custom auth header name / extra headers? */
    public static function supportsCustomHeaders(): bool { return false; }

    public function supportsImages(): bool { return false; }

    /** Documentation blurb for the Providers screen (plain text, no secrets). */
    public static function helpText(): string { return ''; }

    protected function baseUrl(): string
    {
        $b = trim((string)($this->row['base_url'] ?? ''));
        return rtrim($b !== '' ? $b : static::defaultBaseUrl(), '/');
    }

    /** Decrypted key — server-side only; never echo the return value. */
    protected function apiKey(): string
    {
        $stored = (string)($this->row['api_key'] ?? '');
        return function_exists('sh_ai_decrypt') ? sh_ai_decrypt($stored) : '';
    }

    protected function hasKey(): bool
    {
        return trim((string)($this->row['api_key'] ?? '')) !== '';
    }

    /** Extra headers configured by the admin (JSON object "Header": "value"). */
    protected function extraHeaders(): array
    {
        $raw = (string)($this->row['extra_headers'] ?? '');
        if (trim($raw) === '') { return []; }
        $d = json_decode($raw, true);
        $out = [];
        if (is_array($d)) {
            foreach ($d as $k => $v) {
                $k = trim((string)$k);
                if ($k === '' || !preg_match('/^[A-Za-z0-9\-]+$/', $k) || !is_scalar($v)) { continue; }
                if (in_array(strtolower($k), ['host', 'content-length', 'transfer-encoding'], true)) { continue; }
                $out[] = $k . ': ' . trim(str_replace(["\r", "\n"], ' ', (string)$v));
            }
        }
        return $out;
    }

    protected function model(array $options, string $field = 'default_model'): string
    {
        $m = trim((string)($options['model'] ?? ''));
        if ($m === '') { $m = trim((string)($this->row[$field] ?? '')); }
        return $m;
    }

    /**
     * The optional reference image carried in $options['image'].
     *
     * Only the caller (sh_ai_dispatch) decides WHETHER an image may be sent; it
     * has already checked the routed model's advertised input capabilities. This
     * helper is the last line of defence: base64 only, image MIME only, no data:
     * URLs, no file paths and no URLs the browser could fetch on our behalf.
     *
     * @return array{data:string,mime:string}|null
     */
    protected function imageOption(array $options): ?array
    {
        $img = $options['image'] ?? null;
        if (!is_array($img)) { return null; }
        $data = preg_replace('/\s+/', '', (string)($img['data'] ?? '')) ?? '';
        $mime = strtolower(trim((string)($img['mime'] ?? '')));
        if ($data === '' || preg_match('~^image/(jpeg|png|webp)$~', $mime) !== 1) { return null; }
        // ~4.5 MB of base64 is already far beyond what a product photo needs.
        if (strlen($data) > 6_000_000) { return null; }
        if (base64_decode($data, true) === false) { return null; }
        return ['data' => $data, 'mime' => $mime];
    }

    protected function noKey(): array
    {
        return ['ok' => false, 'error' => 'No API key is saved for ' . $this->name() . '.', 'retryable' => false];
    }

    protected function noModel(): array
    {
        return ['ok' => false, 'error' => 'No model is selected for ' . $this->name() . '.', 'retryable' => false];
    }

    /* ------------------------------------------------------------------ *
     * Connection tests
     *
     * Every driver overrides testConnection() with the lightest READ-ONLY
     * request its own API supports. The result shape below is what the
     * Providers screen stores and renders: a machine code, the HTTP status the
     * provider actually returned, a short message and one next action. A
     * token-limit answer is never reported as a bad key.
     * ------------------------------------------------------------------ */

    /** Standard result for one connection test. Never contains the API key. */
    protected function testResult(bool $ok, string $code, string $message, array $extra = []): array
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        $out = [
            'ok'        => $ok,
            'code'      => $code,
            'message'   => $message,
            'status'    => (int)($extra['status'] ?? 0),
            'model'     => (string)($extra['model'] ?? ''),
            'next'      => (string)($extra['next'] ?? ''),
            'tested_at' => date('Y-m-d H:i:s'),
        ];
        // Older callers read error/detail; keep both in sync with message.
        if ($ok) { $out['detail'] = $message; } else { $out['error'] = $message; }
        return $out;
    }

    protected function testIncomplete(string $message, string $next): array
    {
        return $this->testResult(false, 'config_incomplete', $message, ['next' => $next]);
    }

    /**
     * Turn a failed AIHttpClient response into an actionable test result.
     *
     * @param array{status?:int,error?:string} $http
     */
    protected function testHttpFailure(array $http, string $model = '', string $what = ''): array
    {
        $status = (int)($http['status'] ?? 0);
        $code   = AIHttpClient::classify($status);
        $what   = $what !== '' ? $what : $this->name();
        $detail = trim((string)($http['error'] ?? ''));
        // Status 0 means "no HTTP response"; a curl timeout is still a timeout,
        // not a generic network failure, and the admin hint differs.
        if ($status === 0 && stripos($detail, 'timed out') !== false) { $code = 'timeout'; }

        [$msg, $next] = match ($code) {
            'network_error' => ['Could not reach ' . $what . '. No HTTP response was received.',
                                'Check the base URL and that this server can make outbound HTTPS requests.'],
            'timeout'       => [$what . ' did not answer before the test timed out.',
                                'Retry the test; if it keeps timing out, check the base URL.'],
            'auth_failed'   => ['The API key was rejected by ' . $what . '.',
                                'Paste a current key, save it, then test again.'],
            'permission_denied' => [$what . ' refused this key access to the requested model.',
                                'Enable the model for this key in the provider dashboard, or choose another model.'],
            'model_not_found' => ['The selected model was not found at ' . $what . '.',
                                'Pick a model the account can use (Models tab) and test again.'],
            'rate_limited'  => [$what . ' rate-limited the test request.',
                                'Wait a minute and run the test again.'],
            'billing'       => [$what . ' reports a billing or credit problem.',
                                'Add credit or fix billing in the provider dashboard.'],
            'provider_error'=> [$what . ' returned a server error (HTTP ' . $status . ').',
                                'Retry later. A server-side error does not necessarily mean the key is wrong.'],
            default         => ['The test request was rejected by ' . $what . ' (HTTP ' . $status . ').',
                                'Read the message below, adjust the provider settings and test again.'],
        };
        if ($detail !== '') { $msg .= ' ' . $detail; }
        return $this->testResult(false, $code, $msg, ['status' => $status, 'model' => $model, 'next' => $next]);
    }

    /** Friendly summary used when a driver only has a text error to work with. */
    protected function testCodeFromText(string $error): string
    {
        $e = strtolower($error);
        if (str_contains($e, 'timed out') || str_contains($e, 'timeout')) { return 'timeout'; }
        if (str_contains($e, 'could not reach') || str_contains($e, 'network')) { return 'network_error'; }
        if (str_contains($e, '401') || str_contains($e, 'rejected')) { return 'auth_failed'; }
        if (str_contains($e, '403') || str_contains($e, 'not allowed')) { return 'permission_denied'; }
        if (str_contains($e, '404') || str_contains($e, 'not found')) { return 'model_not_found'; }
        if (str_contains($e, 'rate limit') || str_contains($e, 'quota')) { return 'rate_limited'; }
        if (str_contains($e, 'billing') || str_contains($e, 'credit')) { return 'billing'; }
        return 'failed';
    }

    /** Default structured call: ask for JSON, parse leniently. Drivers override to enable native JSON mode. */
    public function generateStructuredContent(string $system, string $user, array $options = []): array
    {
        $res = $this->generateText($system, $user, $options);
        if (empty($res['ok'])) { return $res; }
        $data = self::parseJson((string)$res['text']);
        if ($data === null) {
            return ['ok' => false, 'error' => 'The model did not return valid JSON.', 'retryable' => true,
                    'text' => (string)$res['text'], 'usage' => $res['usage'] ?? [], 'model' => $res['model'] ?? null];
        }
        return ['ok' => true, 'data' => $data] + $res;
    }

    public function generateImage(string $prompt, array $options = []): array
    {
        return ['ok' => false, 'retryable' => false,
                'error' => $this->name() . ' does not support image generation.'];
    }

    public function guessCapabilities(string $modelId): array
    {
        return ['input_text' => 1, 'input_image' => 0, 'output_text' => 1, 'output_image' => 0];
    }

    /**
     * Generic fallback probe. Every bundled driver overrides this with a probe
     * that is specific to its own API, so this only runs for a future driver
     * that has no override yet.
     */
    public function testConnection(): array
    {
        if (!$this->hasKey()) {
            return $this->testIncomplete('No API key is saved for ' . $this->name() . '.',
                'Paste the API key, save it, then test again.');
        }
        $model = trim($this->model([]));
        if ($model === '') {
            return $this->testIncomplete('No default text model is selected for ' . $this->name() . '.',
                'Choose a default text model and save, then test again.');
        }
        $res = $this->generateText(
            'You are a connection probe. Answer with one word.',
            'Reply with the single word: ready',
            ['model' => $model, 'max_tokens' => 64, 'temperature' => 0]
        );
        if (empty($res['ok'])) {
            $error = (string)($res['error'] ?? 'The test request failed.');
            $code = $this->testCodeFromText($error);
            // A token limit proves the key works; it is not an authentication failure.
            if (str_contains(strtolower($error), 'token limit')) {
                return $this->testResult(true, 'token_limit',
                    'Connected to ' . $this->name() . '. The probe reached the output-token limit before replying.',
                    ['model' => $model, 'next' => 'Raise the token budget for generations; the key itself is valid.']);
            }
            return $this->testResult(false, $code, $error, ['model' => $model,
                'next' => $code === 'auth_failed' ? 'Paste a current key and save it again.' : 'Review the provider settings and test again.']);
        }
        return $this->testResult(true, 'connected',
            'Connected to ' . $this->name() . '. ' . $model . ' replied: ' . mb_substr(trim((string)$res['text']), 0, 40),
            ['model' => $model, 'next' => '']);
    }

    /** Parse a JSON reply, tolerating code fences and surrounding prose. */
    public static function parseJson(string $text): ?array
    {
        $t = trim($text);
        $t = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $t) ?? $t;
        $d = json_decode($t, true);
        if (is_array($d)) { return $d; }
        if (preg_match('/[\{\[].*[\}\]]/s', $t, $m)) {
            $d = json_decode($m[0], true);
            if (is_array($d)) { return $d; }
        }
        return null;
    }

    /** Normalise a usage block to prompt/completion/total tokens (+ cost when known). */
    protected static function usage(int $prompt, int $completion, ?int $total = null, ?float $cost = null): array
    {
        $u = ['prompt_tokens' => $prompt, 'completion_tokens' => $completion,
              'total_tokens' => $total ?? ($prompt + $completion)];
        if ($cost !== null) { $u['cost'] = $cost; }
        return $u;
    }

    /** Cheap capability heuristics shared by OpenAI-style model ids. */
    protected static function looksLikeImageModel(string $id): bool
    {
        $id = strtolower($id);
        return (bool)preg_match('/dall-e|gpt-image|imagen|stable-diffusion|sdxl|flux|image-preview|image-generation|[-\/]image(?:$|[-:])/', $id);
    }

    protected static function looksLikeVisionModel(string $id): bool
    {
        $id = strtolower($id);
        return (bool)preg_match('/gpt-4o|gpt-4\.1|gpt-4-turbo|gpt-5|o1|o3|o4|gemini|claude-3|claude-4|claude-sonnet|claude-opus|claude-haiku|vision|llava|pixtral|vl/', $id);
    }
}
