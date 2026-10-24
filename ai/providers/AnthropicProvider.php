<?php
/**
 * AI Auto Work — Anthropic Claude driver (Messages API).
 * Claude has no image-generation endpoint; generateImage() reports that honestly.
 */
require_once __DIR__ . '/AbstractAIProvider.php';

class AnthropicProvider extends AbstractAIProvider
{
    private const VERSION = '2023-06-01';

    public static function driverKey(): string { return 'anthropic'; }
    public static function defaultLabel(): string { return 'Anthropic Claude'; }
    public static function defaultBaseUrl(): string { return 'https://api.anthropic.com/v1'; }
    public static function starterModels(): array
    {
        return ['claude-3-5-haiku-latest', 'claude-sonnet-4-20250514', 'claude-3-7-sonnet-latest', 'claude-opus-4-20250514'];
    }
    public static function helpText(): string
    {
        return 'Keys start with sk-ant-. Text only — Claude models cannot generate images.';
    }

    private function headers(): array
    {
        return array_merge(['x-api-key: ' . $this->apiKey(), 'anthropic-version: ' . self::VERSION], $this->extraHeaders());
    }

    public function generateText(string $system, string $user, array $options = []): array
    {
        if (!$this->hasKey()) { return $this->noKey(); }
        $model = $this->model($options);
        if ($model === '') { return $this->noModel(); }
        // Content blocks: the reference photo (when the routed model reads
        // images) plus the instruction text. Claude takes base64 image blocks.
        $content = [['type' => 'text', 'text' => $user]];
        $image = $this->imageOption($options);
        if ($image !== null) {
            array_unshift($content, [
                'type' => 'image',
                'source' => ['type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['data']],
            ]);
        }
        $payload = [
            'model'       => $model,
            'max_tokens'  => max(1, (int)($options['max_tokens'] ?? 1200)),
            'temperature' => min(1.0, max(0.0, (float)($options['temperature'] ?? 0.7))),
            'messages'    => [['role' => 'user', 'content' => $content]],
        ];
        if ($system !== '') { $payload['system'] = $system; }
        if (!empty($options['json'])) {
            // No native JSON mode: reinforce the instruction; the reply is parsed leniently.
            $payload['system'] = trim(($payload['system'] ?? '') . "\nRespond with a single JSON object only, no prose, no code fences.");
        }

        $res = AIHttpClient::post($this->baseUrl() . '/messages', $this->headers(), $payload, 120);
        if (!$res['ok']) { return $this->httpFailure($res); }
        $j = $res['json'];
        $envelope = $this->responseEnvelopeFailure((array)$j, (int)($res['status'] ?? 200));
        if ($envelope !== null) { return $envelope; }
        $blocks = $j['content'] ?? null;
        if (!is_array($blocks)) {
            return ['ok' => false, 'code' => 'invalid_response', 'status' => (int)($res['status'] ?? 200),
                    'retryable' => false, 'error' => 'Anthropic returned an invalid Messages response.'];
        }
        $text = '';
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') { $text .= (string)($block['text'] ?? ''); }
        }
        $text = trim($text);
        if ($text === '') {
            $stop = (string)($j['stop_reason'] ?? '');
            $code = $stop === 'max_tokens' ? 'output_limit' : 'empty_response';
            return ['ok' => false, 'code' => $code, 'status' => (int)($res['status'] ?? 200), 'retryable' => $code === 'output_limit',
                    'error' => $code === 'output_limit' ? 'Claude reached the output-token limit before producing text.' : 'Claude returned an empty response.'];
        }
        $u = $j['usage'] ?? [];
        return ['ok' => true, 'text' => $text, 'model' => (string)($j['model'] ?? $model), 'status' => (int)($res['status'] ?? 200),
                'usage' => self::usage((int)($u['input_tokens'] ?? 0), (int)($u['output_tokens'] ?? 0))];
    }

    public function generateStructuredContent(string $system, string $user, array $options = []): array
    {
        $res = $this->generateText($system, $user, $options + ['json' => true]);
        if (empty($res['ok'])) { return $res; }
        $data = self::parseJson((string)$res['text']);
        if ($data === null) { return ['ok' => false, 'error' => 'The model did not return valid JSON.', 'retryable' => true] + $res; }
        return ['ok' => true, 'data' => $data] + $res;
    }

    /**
     * Claude probe: one Messages call with a 64-token budget. Claude requires
     * max_tokens on every request; 64 is enough for a one-word answer, and a
     * max_tokens stop with no text is reported as a token limit, never a key
     * problem. No image, no tools, no prompt caching.
     */
    public function testConnection(): array
    {
        if (!$this->hasKey()) {
            return $this->testIncomplete('No API key is saved for ' . $this->name() . '.',
                'Paste the Anthropic key, save it, then test again.');
        }
        $model = trim($this->model([]));
        if ($model === '') {
            return $this->testIncomplete('No default text model is selected for Claude.',
                'Choose a Claude model (for example claude-3-5-haiku-latest) and save.');
        }
        $payload = [
            'model'      => $model,
            'max_tokens' => 64,
            'messages'   => [['role' => 'user', 'content' => 'Reply with the single word: ready']],
            'temperature' => 0,
        ];
        $res = AIHttpClient::post($this->baseUrl() . '/messages', $this->headers(), $payload, 15);
        if (!$res['ok']) { return $this->testHttpFailure($res, $model, 'Anthropic Claude'); }

        $j = $res['json'];
        $envelope = $this->responseEnvelopeFailure((array)$j, (int)($res['status'] ?? 200));
        if ($envelope !== null) { return $this->testHttpFailure($envelope, $model, 'Anthropic Claude'); }
        $text = '';
        $blocks = $j['content'] ?? [];
        if (!is_array($blocks)) { $blocks = []; }
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') { $text .= (string)($block['text'] ?? ''); }
        }
        $text = trim($text);
        $stop = (string)($j['stop_reason'] ?? '');
        if ($text === '' && $stop === 'max_tokens') {
            return $this->testResult(true, 'token_limit',
                'Connected to Anthropic Claude. ' . $model . ' reached the output-token limit before replying.',
                ['status' => 200, 'model' => $model,
                 'next' => 'Raise max tokens for generations; the key itself is valid.']);
        }
        if ($text === '') {
            return $this->testResult(false, 'empty_response',
                'Anthropic accepted the request but returned no text' . ($stop !== '' ? ' (' . $stop . ')' : '') . '.',
                ['status' => 200, 'model' => $model, 'next' => 'Choose a Claude model and test again.']);
        }
        return $this->testResult(true, 'connected',
            'Connected to Anthropic Claude. ' . $model . ' replied: ' . mb_substr($text, 0, 40),
            ['status' => 200, 'model' => $model, 'next' => '']);
    }

    public function listModels(): array
    {
        if (!$this->hasKey()) { return ['ok' => false, 'error' => 'Save an API key first.']; }
        $res = AIHttpClient::get($this->baseUrl() . '/models?limit=100', $this->headers(), 30);
        if (!$res['ok']) { return ['ok' => false, 'error' => $res['error']]; }
        $out = [];
        foreach (($res['json']['data'] ?? []) as $m) {
            $id = trim((string)($m['id'] ?? ''));
            if ($id === '') { continue; }
            $out[] = ['id' => $id, 'name' => (string)($m['display_name'] ?? $id),
                      'input_text' => 1, 'input_image' => 1, 'output_text' => 1, 'output_image' => 0,
                      'context_length' => null, 'prompt_price' => null, 'completion_price' => null];
        }
        usort($out, static fn($a, $b) => strcmp($a['id'], $b['id']));
        return ['ok' => true, 'models' => $out];
    }

    public function guessCapabilities(string $modelId): array
    {
        return ['input_text' => 1, 'input_image' => 1, 'output_text' => 1, 'output_image' => 0];
    }
}
