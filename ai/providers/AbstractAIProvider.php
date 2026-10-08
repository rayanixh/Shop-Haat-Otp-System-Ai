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

    protected function noKey(): array
    {
        return ['ok' => false, 'error' => 'No API key is saved for ' . $this->name() . '.', 'retryable' => false];
    }

    protected function noModel(): array
    {
        return ['ok' => false, 'error' => 'No model is selected for ' . $this->name() . '.', 'retryable' => false];
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

    public function testConnection(): array
    {
        if (!$this->hasKey()) { return ['ok' => false, 'error' => 'No API key configured.']; }
        $model = $this->model([]);
        if ($model === '') {
            $list = $this->listModels();
            if (!empty($list['ok'])) {
                return ['ok' => true, 'detail' => 'Key accepted. ' . count($list['models']) . ' models available — pick a default model.'];
            }
            return ['ok' => false, 'error' => (string)($list['error'] ?? 'No model selected.')];
        }
        $res = $this->generateText('You reply with one word.', 'Reply with the single word: ready',
            ['model' => $model, 'max_tokens' => 8, 'temperature' => 0]);
        if (empty($res['ok'])) { return ['ok' => false, 'error' => (string)$res['error']]; }
        return ['ok' => true, 'detail' => $model . ' responded: ' . mb_substr((string)$res['text'], 0, 40)];
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
