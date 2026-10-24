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

    /**
     * Make one OpenAI-style chat request. If the endpoint explicitly rejects a
     * single parameter name, make at most one narrow compatibility retry; no
     * provider/model switching or unbounded retry loop is permitted here.
     */
    protected function chat(array $payload, int $timeout = 120): array
    {
        if (!$this->hasKey()) { return $this->noKey(); }
        $usedCompatibilityRetry = false;
        $res = AIHttpClient::post($this->baseUrl() . '/chat/completions', $this->headers(), $this->decorateChatPayload($payload), $timeout);
        if (!$res['ok']) {
            $retryPayload = $this->compatibilityRetryPayload($payload, $res);
            if ($retryPayload !== null) {
                $usedCompatibilityRetry = true;
                $res = AIHttpClient::post($this->baseUrl() . '/chat/completions', $this->headers(), $this->decorateChatPayload($retryPayload), $timeout);
            }
        }
        if (!$res['ok']) { return $this->httpFailure($res); }

        $j = $res['json'];
        // A few gateways return an error envelope with HTTP 200. Treat it as a
        // failed inference, while allowing the same one bounded parameter fix.
        if (isset($j['error']) && !isset($j['choices']) && !$usedCompatibilityRetry) {
            $envelope = is_array($j['error']) ? $j['error'] : ['message' => (string)$j['error']];
            $errorMessage = (string)($envelope['message'] ?? $envelope['error'] ?? 'Provider error');
            $embeddedStatus = $envelope['status'] ?? $envelope['code'] ?? 400;
            $embeddedStatus = is_numeric($embeddedStatus) ? (int)$embeddedStatus : 400;
            $retryPayload = $this->compatibilityRetryPayload($payload, ['status' => $embeddedStatus, 'error' => $errorMessage]);
            if ($retryPayload !== null) {
                $usedCompatibilityRetry = true;
                $res = AIHttpClient::post($this->baseUrl() . '/chat/completions', $this->headers(), $this->decorateChatPayload($retryPayload), $timeout);
                if (!$res['ok']) { return $this->httpFailure($res); }
                $j = $res['json'];
            }
        }

        if (isset($j['error']) && !isset($j['choices'])) {
            $error = is_array($j['error']) ? $j['error'] : ['message' => (string)$j['error']];
            $msg = (string)($error['message'] ?? $error['error'] ?? 'Provider error');
            $errorType = (string)($error['type'] ?? (is_string($error['code'] ?? null) ? $error['code'] : ''));
            $providerStatus = $error['status'] ?? $error['code'] ?? 400;
            $providerStatus = is_numeric($providerStatus) ? (int)$providerStatus : 400;
            $code = AIHttpClient::diagnosticCode($providerStatus, $errorType . ' ' . $msg);
            return ['ok' => false, 'error' => $msg !== '' ? $msg : 'Provider error', 'status' => (int)($res['status'] ?? 200),
                'provider_status' => $providerStatus, 'code' => $code,
                'retryable' => in_array($code, ['timeout', 'rate_limited', 'provider_error', 'network_error'], true)];
        }
        return ['ok' => true, 'json' => $j, 'status' => (int)($res['status'] ?? 200)];
    }

    /**
     * @return array|null A single adjusted payload, or null when the provider
     *                    did not explicitly reject a known optional parameter.
     */
    protected function compatibilityRetryPayload(array $payload, array $failure): ?array
    {
        if (!in_array((int)($failure['status'] ?? 0), [400, 422], true)) { return null; }
        $error = (string)($failure['error'] ?? '');
        $unsupported = preg_match('/unsupported|unrecognized|unknown parameter|unknown field|unexpected parameter|not supported|not allowed|not accepted|invalid parameter|invalid field/i', $error) === 1;
        if (!$unsupported) { return null; }

        foreach (['max_tokens', 'max_completion_tokens'] as $field) {
            if (array_key_exists($field, $payload) && preg_match('/\b' . preg_quote($field, '/') . '\b/i', $error) === 1) {
                $alternative = $field === 'max_tokens' ? 'max_completion_tokens' : 'max_tokens';
                $payload[$alternative] = $payload[$field];
                unset($payload[$field]);
                return $payload;
            }
        }
        if (array_key_exists('temperature', $payload) && preg_match('/\btemperature\b/i', $error) === 1) {
            unset($payload['temperature']);
            return $payload;
        }
        return null;
    }

    protected function messages(string $system, string $user, array $options = []): array
    {
        // A vision-capable model receives the product photo as a content part on
        // the same user message. Text-only models get the plain string, so this
        // branch is never reached for them (sh_ai_dispatch checks first).
        $image = $this->imageOption($options);
        $userContent = $user;
        if ($image !== null) {
            $userContent = [
                ['type' => 'text', 'text' => $user],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . $image['data']]],
            ];
        }
        return array_values(array_filter([
            $system !== '' ? ['role' => 'system', 'content' => $system] : null,
            ['role' => 'user', 'content' => $userContent],
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
            'model' => $model,
            'messages' => $this->messages($system, $user, $options),
            $this->maxTokenField($model) => max(1, (int)($options['max_tokens'] ?? 1200)),
        ];
        if ($this->supportsTemperature($model) && array_key_exists('temperature', $options)) {
            $payload['temperature'] = (float)$options['temperature'];
        } elseif ($this->supportsTemperature($model) && !array_key_exists('temperature', $options)) {
            $payload['temperature'] = 0.7;
        }
        if (!empty($options['json'])) { $payload['response_format'] = ['type' => 'json_object']; }

        $res = $this->chat($payload);
        if (empty($res['ok'])) { return $res; }
        $j = $res['json'];
        $choice = $j['choices'][0] ?? null;
        if (!is_array($choice) || !isset($choice['message']) || !is_array($choice['message'])) {
            return ['ok' => false, 'code' => 'invalid_response', 'status' => (int)($res['status'] ?? 200),
                    'retryable' => false, 'error' => 'The provider returned a successful response without a usable chat choice.'];
        }
        $content = $choice['message']['content'] ?? '';
        if (is_array($content)) {
            $content = implode('', array_map(static fn($part) => is_array($part) ? (string)($part['text'] ?? '') : '', $content));
        }
        $text = trim(is_string($content) || is_numeric($content) ? (string)$content : '');
        if ($text === '') {
            $finish = strtolower((string)($choice['finish_reason'] ?? ''));
            $code = in_array($finish, ['length', 'max_tokens'], true) ? 'output_limit' : 'empty_response';
            return ['ok' => false, 'code' => $code, 'status' => (int)($res['status'] ?? 200), 'retryable' => true,
                    'error' => $code === 'output_limit' ? 'The model reached its output-token limit before producing text.' : 'The model returned an empty response.'];
        }
        return ['ok' => true, 'text' => $text, 'usage' => $this->extractUsage($j),
                'model' => (string)($j['model'] ?? $model), 'status' => (int)($res['status'] ?? 200)];
    }

    /** OpenAI-compatible APIs use max_tokens unless a concrete driver overrides it. */
    protected function maxTokenField(string $model): string { return 'max_tokens'; }

    /** Most compatible APIs accept temperature; concrete model drivers may omit it. */
    protected function supportsTemperature(string $model): bool { return true; }

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
        if (!$res['ok']) { return $this->httpFailure($res); }

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

    /**
     * Real chat probe: one very short message, conservative budget, no image,
     * no tools. A 200 with an empty reply is checked against finish_reason so a
     * token limit is reported as such and never as a bad key.
     */
    public function testConnection(): array
    {
        if (!$this->hasKey()) {
            return $this->testIncomplete('No API key is saved for ' . $this->name() . '.',
                'Paste the API key, save it, then test again.');
        }
        if ($this->baseUrl() === '') {
            return $this->testIncomplete('This provider has no base URL.',
                'Enter the OpenAI-compatible base URL (for example https://api.groq.com/openai/v1) and save.');
        }
        $model = trim($this->model([]));
        if ($model === '') {
            return $this->testIncomplete('No default text model is selected for ' . $this->name() . '.',
                'Choose a default text model and save, then test again.');
        }

        $res = $this->chat([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => 'Reply with the single word: ready']],
            $this->maxTokenField($model) => 64,
        ], 15);
        if (empty($res['ok'])) { return $this->testHttpFailure($res, $model, $this->name()); }

        $j = $res['json'];
        $choice = $j['choices'][0] ?? null;
        if (!is_array($choice) || !is_array($choice['message'] ?? null)) {
            return $this->testResult(false, 'invalid_response',
                $this->name() . ' returned HTTP 200 without a usable chat choice.',
                ['status' => (int)($res['status'] ?? 200), 'model' => $model,
                 'next' => 'Check that this endpoint implements the OpenAI chat-completions response format.']);
        }
        $content = $choice['message']['content'] ?? '';
        if (is_array($content)) {
            $content = implode('', array_map(static fn($part) => is_array($part) ? (string)($part['text'] ?? '') : '', $content));
        }
        $text = trim(is_string($content) || is_numeric($content) ? (string)$content : '');
        $finish = strtolower((string)($choice['finish_reason'] ?? ''));
        if ($text === '' && in_array($finish, ['length', 'max_tokens'], true)) {
            return $this->testResult(true, 'token_limit',
                'Connected to ' . $this->name() . '. ' . $model . ' reached its output-token limit before replying.',
                ['status' => (int)($res['status'] ?? 200), 'model' => $model,
                 'next' => 'Raise the token budget for generations; the key itself is valid.']);
        }
        if ($text === '') {
            return $this->testResult(false, 'empty_response',
                $this->name() . ' accepted the request but returned no assistant text' . ($finish !== '' ? ' (' . $finish . ')' : '') . '.',
                ['status' => (int)($res['status'] ?? 200), 'model' => $model,
                 'next' => 'Choose a text-capable model and test again.']);
        }
        return $this->testResult(true, 'connected',
            'Connected to ' . $this->name() . '. ' . $model . ' replied: ' . mb_substr($text, 0, 40),
            ['status' => (int)($res['status'] ?? 200), 'model' => $model, 'next' => '']);
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
