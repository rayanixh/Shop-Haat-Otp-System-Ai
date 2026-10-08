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
        $payload = [
            'model'       => $model,
            'max_tokens'  => max(1, (int)($options['max_tokens'] ?? 1200)),
            'temperature' => min(1.0, max(0.0, (float)($options['temperature'] ?? 0.7))),
            'messages'    => [['role' => 'user', 'content' => $user]],
        ];
        if ($system !== '') { $payload['system'] = $system; }
        if (!empty($options['json'])) {
            // No native JSON mode: reinforce the instruction; the reply is parsed leniently.
            $payload['system'] = trim(($payload['system'] ?? '') . "\nRespond with a single JSON object only, no prose, no code fences.");
        }

        $res = AIHttpClient::post($this->baseUrl() . '/messages', $this->headers(), $payload, 120);
        if (!$res['ok']) { return ['ok' => false, 'error' => $res['error'], 'retryable' => $res['retryable']]; }
        $j = $res['json'];
        $text = '';
        foreach (($j['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') { $text .= (string)($block['text'] ?? ''); }
        }
        $text = trim($text);
        if ($text === '') {
            $stop = (string)($j['stop_reason'] ?? '');
            return ['ok' => false, 'retryable' => $stop === 'max_tokens',
                    'error' => $stop === 'max_tokens' ? 'Claude hit the token limit before producing text. Raise max tokens.' : 'Claude returned an empty response.'];
        }
        $u = $j['usage'] ?? [];
        return ['ok' => true, 'text' => $text, 'model' => (string)($j['model'] ?? $model),
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
