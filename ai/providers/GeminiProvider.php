<?php
/**
 * AI Auto Work — Google Gemini driver (Generative Language API).
 *
 * Text: POST /models/{model}:generateContent
 * Image: Imagen models via :predict; Gemini image-preview models via
 *        generateContent with responseModalities IMAGE.
 */
require_once __DIR__ . '/AbstractAIProvider.php';

class GeminiProvider extends AbstractAIProvider
{
    public static function driverKey(): string { return 'gemini'; }
    public static function defaultLabel(): string { return 'Google Gemini'; }
    public static function defaultBaseUrl(): string { return 'https://generativelanguage.googleapis.com/v1beta'; }
    public static function starterModels(): array
    {
        return ['gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-image', 'imagen-4.0-generate-001'];
    }
    public function supportsImages(): bool { return true; }
    public static function helpText(): string
    {
        return 'Create a key in Google AI Studio. Text: gemini-2.5-flash / gemini-2.5-pro. '
             . 'Images: imagen-4.0-generate-001 or gemini-2.5-flash-image.';
    }

    private function headers(): array
    {
        return array_merge(['x-goog-api-key: ' . $this->apiKey()], $this->extraHeaders());
    }

    private static function cleanId(string $id): string
    {
        return preg_replace('#^models/#', '', trim($id)) ?? $id;
    }

    private function call(string $model, string $method, array $payload, int $timeout = 120): array
    {
        if (!$this->hasKey()) { return $this->noKey(); }
        $url = $this->baseUrl() . '/models/' . rawurlencode(self::cleanId($model)) . ':' . $method;
        $res = AIHttpClient::post($url, $this->headers(), $payload, $timeout);
        if (!$res['ok']) { return ['ok' => false, 'error' => $res['error'], 'retryable' => $res['retryable']]; }
        return ['ok' => true, 'json' => $res['json']];
    }

    private function generate(string $system, string $user, array $options, bool $json): array
    {
        $model = $this->model($options);
        if ($model === '') { return $this->noModel(); }
        $gen = [
            'temperature'     => (float)($options['temperature'] ?? 0.7),
            'maxOutputTokens' => (int)($options['max_tokens'] ?? 1200),
        ];
        if ($json) { $gen['responseMimeType'] = 'application/json'; }
        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig' => $gen,
        ];
        if ($system !== '') { $payload['system_instruction'] = ['parts' => [['text' => $system]]]; }

        $res = $this->call($model, 'generateContent', $payload);
        if (!$res['ok']) { return $res; }
        $j = $res['json'];
        $cand = $j['candidates'][0] ?? null;
        if ($cand === null) {
            $why = (string)($j['promptFeedback']['blockReason'] ?? '');
            return ['ok' => false, 'retryable' => false,
                    'error' => $why !== '' ? 'Gemini blocked the request (' . $why . ').' : 'Gemini returned no candidates.'];
        }
        $text = '';
        foreach (($cand['content']['parts'] ?? []) as $p) { $text .= (string)($p['text'] ?? ''); }
        $text = trim($text);
        if ($text === '') {
            $fin = (string)($cand['finishReason'] ?? '');
            return ['ok' => false, 'retryable' => $fin === 'MAX_TOKENS',
                    'error' => $fin === 'MAX_TOKENS' ? 'Gemini hit the token limit before producing text. Raise max tokens.' : 'Gemini returned an empty response' . ($fin !== '' ? ' (' . $fin . ')' : '') . '.'];
        }
        $u = $j['usageMetadata'] ?? [];
        return ['ok' => true, 'text' => $text, 'model' => self::cleanId($model),
                'usage' => self::usage((int)($u['promptTokenCount'] ?? 0), (int)($u['candidatesTokenCount'] ?? 0), isset($u['totalTokenCount']) ? (int)$u['totalTokenCount'] : null)];
    }

    public function generateText(string $system, string $user, array $options = []): array
    {
        return $this->generate($system, $user, $options, false);
    }

    public function generateStructuredContent(string $system, string $user, array $options = []): array
    {
        $res = $this->generate($system, $user, $options, true);
        if (empty($res['ok'])) { return $res; }
        $data = self::parseJson((string)$res['text']);
        if ($data === null) { return ['ok' => false, 'error' => 'The model did not return valid JSON.', 'retryable' => true] + $res; }
        return ['ok' => true, 'data' => $data] + $res;
    }

    public function generateImage(string $prompt, array $options = []): array
    {
        $model = self::cleanId($this->model($options, 'default_image_model'));
        if ($model === '') { return ['ok' => false, 'retryable' => false, 'error' => 'No image model is selected for ' . $this->name() . '.']; }
        $prompt = mb_substr($prompt, 0, 3800);

        if (str_starts_with(strtolower($model), 'imagen')) {
            $res = $this->call($model, 'predict', ['instances' => [['prompt' => $prompt]], 'parameters' => ['sampleCount' => 1]], 180);
            if (!$res['ok']) { return $res; }
            $p = $res['json']['predictions'][0] ?? [];
            $b64 = (string)($p['bytesBase64Encoded'] ?? '');
            if ($b64 === '') {
                $why = (string)($p['raiFilteredReason'] ?? '');
                return ['ok' => false, 'retryable' => false, 'error' => $why !== '' ? 'Imagen filtered the prompt: ' . $why : 'Imagen returned no image.'];
            }
            $dec = AIHttpClient::decodeImageData($b64);
            if ($dec === null) { return ['ok' => false, 'retryable' => true, 'error' => 'The generated image could not be decoded.']; }
            $dec['mime'] = (string)($p['mimeType'] ?? $dec['mime']);
            return ['ok' => true, 'model' => $model, 'usage' => []] + $dec;
        }

        // Gemini native image output (e.g. gemini-2.5-flash-image).
        $res = $this->call($model, 'generateContent', [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseModalities' => ['IMAGE', 'TEXT']],
        ], 180);
        if (!$res['ok']) { return $res; }
        foreach (($res['json']['candidates'][0]['content']['parts'] ?? []) as $part) {
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (is_array($inline) && !empty($inline['data'])) {
                $dec = AIHttpClient::decodeImageData((string)$inline['data']);
                if ($dec === null) { continue; }
                $dec['mime'] = (string)($inline['mimeType'] ?? $inline['mime_type'] ?? $dec['mime']);
                $u = $res['json']['usageMetadata'] ?? [];
                return ['ok' => true, 'model' => $model,
                        'usage' => self::usage((int)($u['promptTokenCount'] ?? 0), (int)($u['candidatesTokenCount'] ?? 0))] + $dec;
            }
        }
        return ['ok' => false, 'retryable' => false,
                'error' => 'This Gemini model returned no image. Use an image-capable model such as gemini-2.5-flash-image or imagen-4.0-generate-001.'];
    }

    public function listModels(): array
    {
        if (!$this->hasKey()) { return ['ok' => false, 'error' => 'Save an API key first.']; }
        $res = AIHttpClient::get($this->baseUrl() . '/models?pageSize=200', $this->headers(), 30);
        if (!$res['ok']) { return ['ok' => false, 'error' => $res['error']]; }
        $out = [];
        foreach (($res['json']['models'] ?? []) as $m) {
            $id = self::cleanId((string)($m['name'] ?? ''));
            if ($id === '') { continue; }
            $methods = array_map('strval', (array)($m['supportedGenerationMethods'] ?? []));
            $canText = in_array('generateContent', $methods, true);
            $canImagen = in_array('predict', $methods, true) && str_starts_with($id, 'imagen');
            if (!$canText && !$canImagen) { continue; }
            if (preg_match('/embedding|aqa|tts|audio|live|veo/i', $id)) { continue; }
            $imgOut = $canImagen || (bool)preg_match('/image/i', $id);
            $out[] = [
                'id' => $id, 'name' => (string)($m['displayName'] ?? $id),
                'input_text' => 1, 'input_image' => $canText ? 1 : 0,
                'output_text' => $canImagen ? 0 : 1, 'output_image' => $imgOut ? 1 : 0,
                'context_length' => isset($m['inputTokenLimit']) ? (int)$m['inputTokenLimit'] : null,
                'prompt_price' => null, 'completion_price' => null,
            ];
        }
        usort($out, static fn($a, $b) => strcmp($a['id'], $b['id']));
        return ['ok' => true, 'models' => $out];
    }

    public function guessCapabilities(string $modelId): array
    {
        $id = strtolower(self::cleanId($modelId));
        $imagen = str_starts_with($id, 'imagen');
        return ['input_text' => 1, 'input_image' => $imagen ? 0 : 1,
                'output_text' => $imagen ? 0 : 1, 'output_image' => ($imagen || str_contains($id, 'image')) ? 1 : 0];
    }
}
