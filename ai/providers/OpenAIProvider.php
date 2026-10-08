<?php
/**
 * AI Auto Work — OpenAI driver (api.openai.com).
 */
require_once __DIR__ . '/OpenAICompatibleProvider.php';

class OpenAIProvider extends OpenAICompatibleProvider
{
    public static function driverKey(): string { return 'openai'; }
    public static function defaultLabel(): string { return 'OpenAI'; }
    public static function defaultBaseUrl(): string { return 'https://api.openai.com/v1'; }
    public static function supportsCustomHeaders(): bool { return false; }
    public static function starterModels(): array
    {
        return ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1', 'gpt-image-1', 'dall-e-3'];
    }
    public static function helpText(): string
    {
        return 'Keys start with sk-. Text models: gpt-4o-mini, gpt-4.1… Image models: gpt-image-1, dall-e-3.';
    }

    protected function headers(): array
    {
        $h = ['Authorization: Bearer ' . $this->apiKey()];
        $org = trim((string)($this->row['auth_header'] ?? ''));   // reused as optional organisation id
        if ($org !== '' && preg_match('/^org-[A-Za-z0-9]+$/', $org)) { $h[] = 'OpenAI-Organization: ' . $org; }
        return $h;
    }

    protected function describeModel(string $id, array $m): array
    {
        $row = parent::describeModel($id, $m);
        $row['name'] = $id;
        return $row;
    }

    public function listModels(): array
    {
        $res = parent::listModels();
        if (empty($res['ok'])) { return $res; }
        // Hide the long tail of embeddings / audio / moderation models.
        $res['models'] = array_values(array_filter($res['models'], static fn($m) =>
            preg_match('/^(gpt-|o[1-9]|chatgpt-|dall-e|gpt-image)/i', $m['id'])
            && !preg_match('/embedding|whisper|tts|audio|realtime|moderation|transcribe|search/i', $m['id'])));
        return $res;
    }
}
