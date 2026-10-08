<?php
/**
 * AI Auto Work — OpenRouter driver (https://openrouter.ai/api/v1).
 *
 * OpenRouter is OpenAI-compatible for chat. On top of that it exposes a rich
 * /models catalogue (modalities, pricing, context) and per-request cost when
 * usage accounting is requested, both of which are surfaced in the admin.
 * Image output goes through /chat/completions with modalities ["image","text"].
 */
require_once __DIR__ . '/OpenAICompatibleProvider.php';

class OpenRouterProvider extends OpenAICompatibleProvider
{
    public static function driverKey(): string { return 'openrouter'; }
    public static function defaultLabel(): string { return 'OpenRouter'; }
    public static function defaultBaseUrl(): string { return 'https://openrouter.ai/api/v1'; }
    public static function supportsCustomHeaders(): bool { return true; }
    public static function starterModels(): array
    {
        return ['openai/gpt-4o-mini', 'anthropic/claude-3.5-haiku', 'google/gemini-2.0-flash-001',
                'meta-llama/llama-3.3-70b-instruct', 'deepseek/deepseek-chat'];
    }
    public static function helpText(): string
    {
        return 'One key for hundreds of models. Model IDs look like vendor/model, e.g. openai/gpt-4o-mini. '
             . 'Press "Load models" to import the live catalogue with pricing and capabilities.';
    }

    protected function headers(): array
    {
        $h = ['Authorization: Bearer ' . $this->apiKey()];
        // Attribution headers are optional but recommended by OpenRouter.
        if (function_exists('sh_site_url')) { $h[] = 'HTTP-Referer: ' . sh_site_url(); }
        if (function_exists('sh_setting')) {
            $title = trim((string)sh_setting('site_name', 'Shop Haat'));
            $h[] = 'X-Title: ' . preg_replace('/[^\x20-\x7E]/', '', $title !== '' ? $title : 'Shop Haat');
        }
        return array_merge($h, $this->extraHeaders());
    }

    protected function decorateChatPayload(array $payload): array
    {
        $payload['usage'] = ['include' => true];       // returns cost in USD with the usage block
        return $payload;
    }

    public function generateImage(string $prompt, array $options = []): array
    {
        if (!$this->hasKey()) { return $this->noKey(); }
        $model = $this->model($options, 'default_image_model');
        if ($model === '') { return ['ok' => false, 'retryable' => false, 'error' => 'No image model is selected for ' . $this->name() . '.']; }

        $res = $this->chat([
            'model'      => $model,
            'messages'   => [['role' => 'user', 'content' => mb_substr($prompt, 0, 3800)]],
            'modalities' => ['image', 'text'],
        ], 180);
        if (!$res['ok']) { return $res; }
        $msg = $res['json']['choices'][0]['message'] ?? [];
        $images = $msg['images'] ?? [];
        $url = '';
        if (is_array($images) && $images) {
            $first = $images[0];
            $url = (string)($first['image_url']['url'] ?? $first['url'] ?? '');
        }
        if ($url === '') {
            return ['ok' => false, 'retryable' => false,
                    'error' => 'The model answered with text only — pick a model whose output modality includes image.'];
        }
        $dec = str_starts_with($url, 'data:') ? AIHttpClient::decodeImageData($url) : null;
        if ($dec === null) {
            $bin = AIHttpClient::download($url);
            if ($bin === null) { return ['ok' => false, 'retryable' => true, 'error' => 'The generated image could not be retrieved.']; }
            $dec = ['binary' => $bin, 'mime' => 'image/png'];
        }
        return ['ok' => true, 'usage' => $this->extractUsage($res['json']), 'model' => (string)($res['json']['model'] ?? $model)] + $dec;
    }

    public function listModels(): array
    {
        // The catalogue is public, but sending the key returns account-specific availability.
        $res = AIHttpClient::get($this->baseUrl() . '/models', $this->hasKey() ? $this->headers() : [], 30);
        if (!$res['ok']) { return ['ok' => false, 'error' => $res['error']]; }
        $out = [];
        foreach (($res['json']['data'] ?? []) as $m) {
            $id = trim((string)($m['id'] ?? ''));
            if ($id === '') { continue; }
            $in = array_map('strval', (array)($m['architecture']['input_modalities'] ?? ['text']));
            $outm = array_map('strval', (array)($m['architecture']['output_modalities'] ?? ['text']));
            $out[] = [
                'id' => $id,
                'name' => (string)($m['name'] ?? $id),
                'input_text' => in_array('text', $in, true) ? 1 : 0,
                'input_image' => in_array('image', $in, true) ? 1 : 0,
                'output_text' => in_array('text', $outm, true) ? 1 : 0,
                'output_image' => in_array('image', $outm, true) ? 1 : 0,
                'context_length' => isset($m['context_length']) ? (int)$m['context_length'] : null,
                'prompt_price' => isset($m['pricing']['prompt']) ? (string)$m['pricing']['prompt'] : null,
                'completion_price' => isset($m['pricing']['completion']) ? (string)$m['pricing']['completion'] : null,
            ];
        }
        usort($out, static fn($a, $b) => strcmp($a['id'], $b['id']));
        return ['ok' => true, 'models' => $out];
    }

    public function testConnection(): array
    {
        if (!$this->hasKey()) { return ['ok' => false, 'error' => 'No API key configured.']; }
        // /auth/key validates the key and reports limits without spending tokens.
        $res = AIHttpClient::get($this->baseUrl() . '/auth/key', $this->headers(), 20);
        if (!$res['ok']) { return ['ok' => false, 'error' => $res['error']]; }
        $d = $res['json']['data'] ?? [];
        $bits = [];
        if (isset($d['label'])) { $bits[] = 'Key "' . (string)$d['label'] . '"'; }
        if (isset($d['limit']) && $d['limit'] !== null) { $bits[] = 'limit $' . number_format((float)$d['limit'], 2); }
        if (isset($d['usage'])) { $bits[] = 'used $' . number_format((float)$d['usage'], 4); }
        $detail = $bits ? implode(', ', $bits) . '.' : 'Key accepted.';
        $model = $this->model([]);
        if ($model !== '') {
            $t = $this->generateText('You reply with one word.', 'Reply with the single word: ready', ['model' => $model, 'max_tokens' => 8, 'temperature' => 0]);
            if (empty($t['ok'])) { return ['ok' => false, 'error' => $detail . ' But the model "' . $model . '" failed: ' . (string)$t['error']]; }
            $detail .= ' ' . $model . ' responded.';
        }
        return ['ok' => true, 'detail' => $detail];
    }
}
