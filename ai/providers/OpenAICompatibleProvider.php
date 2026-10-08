<?php
/**
 * AI Auto Work — generic OpenAI-compatible driver.
 *
 * Talks to any endpoint that implements /chat/completions, /images/generations
 * and /models the way OpenAI does (LM Studio, Groq, Together, DeepSeek, vLLM,
 * a corporate gateway, ...). Auth header and extra headers are configurable so
 * new compatible providers need no code.
 */
require_once __DIR__ . '/AbstractAIProvider.php';

class OpenAICompatibleProvider extends AbstractAIProvider
{
    public static function driverKey(): string { return 'openai_compatible'; }
    public static function defaultLabel(): string { return 'Custom OpenAI-compatible'; }
    public static function defaultBaseUrl(): string { return ''; }
    public static function starterModels(): array { return []; }
    public static function supportsCustomHeaders(): bool { return true; }
    public function supportsImages(): bool { return true; }
    public static function helpText(): string
    {
        return 'Any API that mirrors OpenAI\'s /chat/completions, /images/generations and /models routes. '
             . 'Enter the base URL up to and including the version segment, e.g. https://api.groq.com/openai/v1.';
    }

    protected function headers(): array
    {
        $key = $this->apiKey();
        $authHeader = trim((string)($this->row['auth_header'] ?? ''));
        if ($authHeader === '') { $authHeader = 'Authorization: Bearer {key}'; }
        if (str_contains($authHeader, '{key}')) {
            $auth = str_replace('{key}', $key, $authHeader);
        } elseif (str_contains($authHeader, ':')) {
            $auth = $authHeader . ' ' . $key;                    // "x-api-key:" style
        } else {
            $auth = $authHeader . ': ' . $key;                   // bare header name
        }
        return array_merge([$auth], $this->extraHeaders());
    }

    /** Hook for drivers that add request-level fields (e.g. OpenRouter usage accounting). */
    protected function decorateChatPayload(array $payload): array { return $payload; }

    protected function chat(array $payload, int $timeout = 120): array
    {
        if (!$this->hasKey()) { return $this->noKey(); }
        $res = AIHttpClient::post($this->baseUrl() . '/chat/completions', $this->headers(), $this->decorateChatPayload($payload), $timeout);
        if (!$res['ok']) { return ['ok' => false, 'error' => $res['error'], 'retryable' => $res['retryable']]; }
        $j = $res['json'];
        // Some gateways answer 200 with an error envelope.
        if (isset($j['error']) && !isset($j['choices'])) {
            $msg = is_array($j['error']) ? (string)($j['error']['message'] ?? 'Provider error') : (string)$j['error'];
            $code = (int)(is_array($j['error']) ? ($j['error']['code'] ?? 0) : 0);
            return ['ok' => false, 'error' => AIHttpClient::friendly($code, $msg), 'retryable' => $code === 429 || $code >= 500];
        }
        return ['ok' => true, 'json' => $j];
    }

    protected function messages(string $system, string $user): array
    {
        return array_values(array_filter([
            $system !== '' ? ['role' => 'system', 'content' => $system] : null,
            ['role' => 'user', 'content' => $user],
        ]));
    }

    protected function extractUsage(array $j): array
    {
        $u = $j['usage'] ?? [];
        $cost = isset($u['cost']) && is_numeric($u['cost']) ? (float)$u['cost'] : null;
        return self::usage((int)($u['prompt_tokens'] ?? 0), (int)($u['completion_tokens'] ?? 0),
            isset($u['total_tokens']) ? (int)$u['total_tokens'] : null, $cost);
    }

    public function generateText(string $system, string $user, array $options = []): array
    {
        $model = $this->model($options);
        if ($model === '') { return $this->noModel(); }
        $payload = [
            'model'       => $model,
            'messages'    => $this->messages($system, $user),
            'temperature' => (float)($options['temperature'] ?? 0.7),
            'max_tokens'  => (int)($options['max_tokens'] ?? 1200),
        ];
        if (!empty($options['json'])) { $payload['response_format'] = ['type' => 'json_object']; }

        $res = $this->chat($payload);
        if (!$res['ok']) { return $res; }
        $j = $res['json'];
        $content = $j['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {                       // multi-part content
            $content = implode('', array_map(static fn($p) => (string)($p['text'] ?? ''), $content));
        }
        $text = trim((string)$content);
        if ($text === '') {
            $finish = (string)($j['choices'][0]['finish_reason'] ?? '');
            return ['ok' => false, 'retryable' => true,
                    'error' => $finish === 'length' ? 'The model hit the token limit before producing text. Raise max tokens.' : 'The model returned an empty response.'];
        }
        return ['ok' => true, 'text' => $text, 'usage' => $this->extractUsage($j), 'model' => (string)($j['model'] ?? $model)];
    }

    public function generateStructuredContent(string $system, string $user, array $options = []): array
    {
        // Try native JSON mode first; a 400 about response_format means the
        // endpoint lacks it, so retry once in plain mode with lenient parsing.
        $res = $this->generateText($system, $user, $options + ['json' => true]);
        if (empty($res['ok']) && stripos((string)($res['error'] ?? ''), 'response_format') !== false) {
            $res = $this->generateText($system, $user, $options);
        }
        if (empty($res['ok'])) { return $res; }
        $data = self::parseJson((string)$res['text']);
        if ($data === null) {
            return ['ok' => false, 'error' => 'The model did not return valid JSON.', 'retryable' => true] + $res;
        }
        return ['ok' => true, 'data' => $data] + $res;
    }

    public function generateImage(string $prompt, array $options = []): array
    {
        if (!$this->hasKey()) { return $this->noKey(); }
        $model = $this->model($options, 'default_image_model');
        if ($model === '') { return ['ok' => false, 'retryable' => false, 'error' => 'No image model is selected for ' . $this->name() . '.']; }

        $payload = [
            'model'  => $model,
            'prompt' => mb_substr($prompt, 0, 3800),
            'n'      => 1,
            'size'   => (string)($options['size'] ?? '1024x1024'),
        ];
        // gpt-image-* always returns base64 and rejects response_format.
        if (!str_starts_with(strtolower($model), 'gpt-image')) { $payload['response_format'] = 'b64_json'; }

        $res = AIHttpClient::post($this->baseUrl() . '/images/generations', $this->headers(), $payload, 180);
        if (!$res['ok']) { return ['ok' => false, 'error' => $res['error'], 'retryable' => $res['retryable']]; }

        $item = $res['json']['data'][0] ?? [];
        $usage = [];
        if (isset($res['json']['usage'])) {
            $u = $res['json']['usage'];
            $usage = self::usage((int)($u['input_tokens'] ?? 0), (int)($u['output_tokens'] ?? 0), isset($u['total_tokens']) ? (int)$u['total_tokens'] : null);
        }
        $b64 = (string)($item['b64_json'] ?? '');
        if ($b64 !== '') {
            $dec = AIHttpClient::decodeImageData($b64);
            if ($dec === null) { return ['ok' => false, 'retryable' => true, 'error' => 'The generated image could not be decoded.']; }
            return ['ok' => true, 'usage' => $usage, 'model' => $model] + $dec;
        }
        $url = (string)($item['url'] ?? '');
        if ($url === '') { return ['ok' => false, 'retryable' => true, 'error' => 'The provider returned no image.']; }
        $bin = AIHttpClient::download($url);
        if ($bin === null) { return ['ok' => false, 'retryable' => true, 'error' => 'The generated image could not be downloaded.']; }
        return ['ok' => true, 'binary' => $bin, 'mime' => 'image/png', 'usage' => $usage, 'model' => $model];
    }

    public function listModels(): array
    {
        if (!$this->hasKey()) { return ['ok' => false, 'error' => 'Save an API key first.']; }
        $res = AIHttpClient::get($this->baseUrl() . '/models', $this->headers(), 30);
        if (!$res['ok']) { return ['ok' => false, 'error' => $res['error']]; }
        $rows = $res['json']['data'] ?? $res['json']['models'] ?? [];
        if (!is_array($rows)) { return ['ok' => false, 'error' => 'The provider returned no model list.']; }
        $out = [];
        foreach ($rows as $m) {
            $id = trim((string)($m['id'] ?? $m['name'] ?? ''));
            if ($id === '') { continue; }
            $out[] = $this->describeModel($id, is_array($m) ? $m : []);
        }
        usort($out, static fn($a, $b) => strcmp($a['id'], $b['id']));
        return ['ok' => true, 'models' => $out];
    }

    /** Build a catalogue row from whatever metadata the endpoint gives. */
    protected function describeModel(string $id, array $m): array
    {
        return ['id' => $id, 'name' => (string)($m['display_name'] ?? $m['name'] ?? $id),
                'context_length' => isset($m['context_length']) ? (int)$m['context_length'] : (isset($m['context_window']) ? (int)$m['context_window'] : null),
                'prompt_price' => null, 'completion_price' => null] + $this->guessCapabilities($id);
    }

    public function guessCapabilities(string $modelId): array
    {
        $img = self::looksLikeImageModel($modelId);
        return ['input_text' => 1, 'input_image' => self::looksLikeVisionModel($modelId) ? 1 : 0,
                'output_text' => $img && preg_match('/dall-e|gpt-image|imagen|stable|sdxl|flux/i', $modelId) ? 0 : 1,
                'output_image' => $img ? 1 : 0];
    }
}
