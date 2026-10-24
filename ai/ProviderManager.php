<?php
/**
 * AI Auto Work — provider layer.
 *
 * Everything between "a task wants text/JSON/an image" and "an HTTP call to a
 * vendor" lives here: configured providers (ai_providers), the model catalogue
 * (ai_models), per-task routing, optional fallback and generation logging.
 *
 * Task modules (Product AI, Blog AI, SEO AI, Bulk AI, Image AI) only ever call
 * sh_ai_text(), sh_ai_structured() and sh_ai_image().
 */

require_once __DIR__ . '/providers/AIProviderInterface.php';
require_once __DIR__ . '/providers/AbstractAIProvider.php';
require_once __DIR__ . '/providers/OpenAICompatibleProvider.php';
require_once __DIR__ . '/providers/OpenAIProvider.php';
require_once __DIR__ . '/providers/OpenRouterProvider.php';
require_once __DIR__ . '/providers/GeminiProvider.php';
require_once __DIR__ . '/providers/AnthropicProvider.php';

/* ------------------------------------------------------------------ *
 * Driver registry — the only place a new driver class is named
 * ------------------------------------------------------------------ */

/** @return array<string,class-string<AbstractAIProvider>> */
function sh_ai_drivers(): array
{
    return [
        OpenAIProvider::driverKey()           => OpenAIProvider::class,
        OpenRouterProvider::driverKey()       => OpenRouterProvider::class,
        GeminiProvider::driverKey()           => GeminiProvider::class,
        AnthropicProvider::driverKey()        => AnthropicProvider::class,
        OpenAICompatibleProvider::driverKey() => OpenAICompatibleProvider::class,
    ];
}

function sh_ai_driver_label(string $driver): string
{
    $cls = sh_ai_drivers()[$driver] ?? null;
    return $cls ? $cls::defaultLabel() : ucfirst($driver);
}

/**
 * Return trusted, local identity artwork for a registered provider driver.
 * Matching uses only the normalized driver ID; configured names and endpoint
 * URLs are deliberately never used to infer a vendor logo.
 *
 * @return array{asset:?string,alt:string,fallback:string,wide:bool,width:int,height:int}
 */
function sh_ai_provider_identity(string $driver): array
{
    static $identities = [
        'openai' => [
            'asset' => 'assets/images/ai-providers/openai.svg',
            'alt' => 'OpenAI logo',
            'fallback' => 'OpenAI',
            'wide' => false,
            'width' => 716,
            'height' => 716,
        ],
        'openrouter' => [
            'asset' => 'assets/images/ai-providers/openrouter.svg',
            'alt' => 'OpenRouter logo',
            'fallback' => 'OpenRouter',
            'wide' => true,
            'width' => 1608,
            'height' => 294,
        ],
        'gemini' => [
            'asset' => 'assets/images/ai-providers/gemini.png',
            'alt' => 'Google Gemini logo',
            'fallback' => 'Gemini',
            'wide' => false,
            'width' => 512,
            'height' => 512,
        ],
        'anthropic' => [
            'asset' => 'assets/images/ai-providers/anthropic.svg',
            'alt' => 'Anthropic logo',
            'fallback' => 'Anthropic',
            'wide' => false,
            'width' => 248,
            'height' => 248,
        ],
        // This driver can point to many unrelated services. There is no
        // single verified brand mark, so keep the identity explicit in text.
        'openai_compatible' => [
            'asset' => null,
            'alt' => '',
            'fallback' => 'Custom',
            'wide' => false,
            'width' => 0,
            'height' => 0,
        ],
    ];

    $key = strtolower(trim($driver));
    return $identities[$key] ?? [
        'asset' => null,
        'alt' => '',
        'fallback' => 'Custom',
        'wide' => false,
        'width' => 0,
        'height' => 0,
    ];
}

/** Build an adapter from a provider row. Null when the driver is unknown. */
function sh_ai_adapter(array $row): ?AIProviderInterface
{
    $cls = sh_ai_drivers()[(string)($row['driver'] ?? '')] ?? null;
    return $cls ? new $cls($row) : null;
}

/* ------------------------------------------------------------------ *
 * Task catalogue
 * ------------------------------------------------------------------ */

/**
 * Routable tasks. "kind" decides which capability the chosen model needs.
 * @return array<string,array{label:string,group:string,kind:string}>
 */
function sh_ai_tasks(): array
{
    return [
        'product_title'       => ['label' => 'Product title',        'group' => 'Products', 'kind' => 'text'],
        'product_description' => ['label' => 'Product description',  'group' => 'Products', 'kind' => 'text'],
        'product_short'       => ['label' => 'Short description',    'group' => 'Products', 'kind' => 'text'],
        'product_tags'        => ['label' => 'Product tags',         'group' => 'Products', 'kind' => 'text'],
        'product_category'    => ['label' => 'Category suggestion',  'group' => 'Products', 'kind' => 'text'],
        'product_full'        => ['label' => 'Complete product draft', 'group' => 'Products', 'kind' => 'text'],
        'product_seo'         => ['label' => 'Product SEO',          'group' => 'SEO',      'kind' => 'text'],
        'category_content'    => ['label' => 'Category content',     'group' => 'SEO',      'kind' => 'text'],
        'blog_post'           => ['label' => 'Blog post',            'group' => 'Blog',     'kind' => 'text'],
        'image_prompt'        => ['label' => 'Image prompt',         'group' => 'Images',   'kind' => 'text'],
        'product_image'       => ['label' => 'Product image',        'group' => 'Images',   'kind' => 'image'],
    ];
}

/* ------------------------------------------------------------------ *
 * Providers (ai_providers)
 * ------------------------------------------------------------------ */

function sh_ai_providers_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) { return $cache; }
    try {
        $cache = sh_all('SELECT * FROM ai_providers ORDER BY is_default DESC, sort_order ASC, id ASC');
    } catch (Throwable $e) { $cache = []; }
    return $cache;
}

function sh_ai_provider_row(int $id): ?array
{
    foreach (sh_ai_providers_all() as $r) { if ((int)$r['id'] === $id) { return $r; } }
    return null;
}

/** Enabled providers that have a key (or need none). */
function sh_ai_providers_usable(): array
{
    return array_values(array_filter(sh_ai_providers_all(), static fn($r) =>
        (int)$r['status'] === 1 && trim((string)$r['api_key']) !== '' && isset(sh_ai_drivers()[$r['driver']])));
}

function sh_ai_default_provider_row(): ?array
{
    $usable = sh_ai_providers_usable();
    foreach ($usable as $r) { if ((int)$r['is_default'] === 1) { return $r; } }
    return $usable[0] ?? null;
}

/** Create or update a provider. Returns id. Never stores a plaintext key. */
function sh_ai_provider_save(array $in, int $id = 0): int
{
    $data = [
        'name'                => mb_substr(trim((string)($in['name'] ?? '')), 0, 80),
        'driver'              => (string)($in['driver'] ?? 'openai'),
        'base_url'            => mb_substr(trim((string)($in['base_url'] ?? '')), 0, 255),
        'auth_header'         => mb_substr(trim((string)($in['auth_header'] ?? '')), 0, 120),
        'extra_headers'       => (string)($in['extra_headers'] ?? ''),
        'default_model'       => mb_substr(trim((string)($in['default_model'] ?? '')), 0, 120),
        'default_image_model' => mb_substr(trim((string)($in['default_image_model'] ?? '')), 0, 120),
        'status'              => !empty($in['status']) ? 1 : 0,
    ];
    if (array_key_exists('api_key_plain', $in) && (string)$in['api_key_plain'] !== '') {
        $data['api_key'] = sh_ai_encrypt((string)$in['api_key_plain']);
    }
    if (!empty($in['clear_key'])) { $data['api_key'] = ''; }

    if ($id > 0) {
        sh_update('ai_providers', $data, 'id = ?', [$id]);
    } else {
        $data['sort_order'] = (int)sh_val('SELECT COALESCE(MAX(sort_order),0)+1 FROM ai_providers', [], 1);
        $data['api_key'] = $data['api_key'] ?? '';
        $id = (int)sh_insert('ai_providers', $data);
    }
    if (!empty($in['is_default'])) { sh_ai_provider_set_default($id); }
    elseif ((int)sh_val('SELECT COUNT(*) FROM ai_providers WHERE is_default = 1', [], 0) === 0) {
        sh_ai_provider_set_default($id);
    }
    sh_ai_providers_all(true);
    return $id;
}

function sh_ai_provider_set_default(int $id): void
{
    sh_query('UPDATE ai_providers SET is_default = 0');
    sh_query('UPDATE ai_providers SET is_default = 1 WHERE id = ?', [$id]);
    sh_ai_providers_all(true);
}

function sh_ai_provider_delete(int $id): void
{
    sh_query('DELETE FROM ai_models WHERE provider_id = ?', [$id]);
    sh_query('DELETE FROM ai_providers WHERE id = ?', [$id]);
    // Routing that pointed at it falls back to the default provider.
    foreach (sh_ai_settings() as $k => $v) {
        if (preg_match('/^(task_\w+_provider|fallback_provider|fallback_image_provider)$/', $k) && (int)$v === $id) {
            sh_ai_setting_save($k, '');
        }
    }
    sh_ai_providers_all(true);
}

/** Masked key for display: first 4 + last 4 characters only. */
function sh_ai_provider_masked_key(array $row): string
{
    $v = sh_ai_decrypt((string)($row['api_key'] ?? ''));
    if ($v === '') { return ''; }
    $len = strlen($v);
    if ($len <= 10) { return str_repeat('•', max(4, $len)); }
    return substr($v, 0, 4) . str_repeat('•', 10) . substr($v, -4);
}

/* ------------------------------------------------------------------ *
 * Model catalogue (ai_models)
 * ------------------------------------------------------------------ */

function sh_ai_models(int $providerId = 0, bool $enabledOnly = false): array
{
    try {
        $w = ['1=1']; $a = [];
        if ($providerId > 0) { $w[] = 'provider_id = ?'; $a[] = $providerId; }
        if ($enabledOnly) { $w[] = 'status = 1'; }
        return sh_all('SELECT * FROM ai_models WHERE ' . implode(' AND ', $w) . ' ORDER BY provider_id ASC, is_default DESC, model_id ASC', $a);
    } catch (Throwable $e) { return []; }
}

function sh_ai_model_row(int $providerId, string $modelId): ?array
{
    try {
        return sh_one('SELECT * FROM ai_models WHERE provider_id = ? AND model_id = ? LIMIT 1', [$providerId, $modelId]);
    } catch (Throwable $e) { return null; }
}

/** Insert or refresh one catalogue row. Manual flags set by the admin are kept. */
function sh_ai_model_upsert(int $providerId, array $m, string $source = 'api'): void
{
    $modelId = mb_substr(trim((string)($m['id'] ?? '')), 0, 120);
    if ($modelId === '') { return; }
    $existing = sh_ai_model_row($providerId, $modelId);
    $data = [
        'name'             => mb_substr((string)($m['name'] ?? $modelId), 0, 160),
        'input_text'       => (int)!empty($m['input_text']),
        'input_image'      => (int)!empty($m['input_image']),
        'output_text'      => (int)!empty($m['output_text']),
        'output_image'     => (int)!empty($m['output_image']),
        'context_length'   => isset($m['context_length']) && $m['context_length'] !== null ? (int)$m['context_length'] : null,
        'prompt_price'     => isset($m['prompt_price']) && is_numeric($m['prompt_price']) ? (string)$m['prompt_price'] : null,
        'completion_price' => isset($m['completion_price']) && is_numeric($m['completion_price']) ? (string)$m['completion_price'] : null,
        'source'           => $source,
    ];
    if ($existing !== null) {
        if ((string)$existing['source'] === 'manual' && $source === 'api') {
            // Admin-entered rows keep their capability flags; only metadata refreshes.
            unset($data['input_text'], $data['input_image'], $data['output_text'], $data['output_image']);
        }
        sh_update('ai_models', $data, 'id = ?', [(int)$existing['id']]);
    } else {
        $data['provider_id'] = $providerId;
        $data['model_id'] = $modelId;
        $data['status'] = 1;
        sh_insert('ai_models', $data);
    }
}

/** Make sure a manually typed model id exists in the catalogue. */
function sh_ai_model_ensure(int $providerId, string $modelId): void
{
    $modelId = trim($modelId);
    if ($modelId === '' || $providerId <= 0 || sh_ai_model_row($providerId, $modelId) !== null) { return; }
    $row = sh_ai_provider_row($providerId);
    $adapter = $row ? sh_ai_adapter($row) : null;
    $caps = $adapter ? $adapter->guessCapabilities($modelId) : ['input_text' => 1, 'input_image' => 0, 'output_text' => 1, 'output_image' => 0];
    sh_ai_model_upsert($providerId, ['id' => $modelId, 'name' => $modelId] + $caps, 'manual');
}

/** Pull the live catalogue from the provider. Returns count or an error. */
function sh_ai_models_sync(int $providerId): array
{
    $row = sh_ai_provider_row($providerId);
    $adapter = $row ? sh_ai_adapter($row) : null;
    if ($adapter === null) { return ['ok' => false, 'error' => 'Unknown provider.']; }
    $res = $adapter->listModels();
    if (empty($res['ok'])) { return ['ok' => false, 'error' => (string)($res['error'] ?? 'The model list could not be loaded.')]; }
    $n = 0;
    try {
        foreach ($res['models'] as $m) { sh_ai_model_upsert($providerId, $m, 'api'); $n++; }
        sh_update('ai_providers', ['models_synced_at' => date('Y-m-d H:i:s')], 'id = ?', [$providerId]);
        sh_ai_providers_all(true);
    } catch (Throwable $e) {
        sh_log_exception($e, 'ai-models-sync');
        return ['ok' => false, 'error' => 'The catalogue could not be saved.'];
    }
    return ['ok' => true, 'count' => $n];
}

/** Seed the starter models for a provider that has never been synced. */
function sh_ai_models_seed(int $providerId): void
{
    $row = sh_ai_provider_row($providerId);
    $cls = $row ? (sh_ai_drivers()[$row['driver']] ?? null) : null;
    if ($cls === null || sh_ai_models($providerId)) { return; }
    $adapter = sh_ai_adapter($row);
    foreach ($cls::starterModels() as $mid) {
        sh_ai_model_upsert($providerId, ['id' => $mid, 'name' => $mid] + $adapter->guessCapabilities($mid), 'manual');
    }
}

/** Does this provider/model pair have the capability a task kind needs? */
function sh_ai_model_supports(int $providerId, string $modelId, string $kind): bool
{
    $m = sh_ai_model_row($providerId, $modelId);
    if ($m === null) {
        $row = sh_ai_provider_row($providerId);
        $adapter = $row ? sh_ai_adapter($row) : null;
        if ($adapter === null) { return false; }
        $m = $adapter->guessCapabilities($modelId);
        if ($kind === 'image' && !$adapter->supportsImages()) { return false; }
    } elseif ((int)$m['status'] !== 1) {
        return false;
    }
    return $kind === 'image' ? !empty($m['output_image']) : !empty($m['output_text']);
}

/**
 * Can this exact provider/model READ an image as part of a text request?
 *
 * This is different from sh_ai_model_supports(...,'image'), which asks whether
 * the model can GENERATE images. Vision is reported by the provider's model
 * catalogue (OpenRouter modalities, our capability guesses for OpenAI-compatible
 * endpoints, Claude and Gemini), and an unlisted/manual model falls back to the
 * driver's guess. When this returns false the caller must not send an image and
 * must not claim the image was analysed.
 */
function sh_ai_model_accepts_image(int $providerId, string $modelId): bool
{
    $modelId = trim($modelId);
    if ($providerId <= 0 || $modelId === '') { return false; }
    $m = sh_ai_model_row($providerId, $modelId);
    if ($m !== null) {
        return (int)$m['status'] === 1 && !empty($m['input_image']);
    }
    $row = sh_ai_provider_row($providerId);
    $adapter = $row !== null ? sh_ai_adapter($row) : null;
    if ($adapter === null) { return false; }
    $caps = $adapter->guessCapabilities($modelId);
    return !empty($caps['input_image']);
}

/** Providers that have at least one enabled image-capable model — for the Image AI picker. */
function sh_ai_image_capable_targets(): array
{
    $out = [];
    foreach (sh_ai_providers_usable() as $p) {
        foreach (sh_ai_models((int)$p['id'], true) as $m) {
            if (!empty($m['output_image'])) {
                $out[] = ['provider_id' => (int)$p['id'], 'provider' => (string)$p['name'], 'model' => (string)$m['model_id'], 'model_name' => (string)$m['name']];
            }
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 * Routing: which provider/model handles a task
 * ------------------------------------------------------------------ */

/**
 * @return array{provider:?array,model:string,source:string}  source = task|default|none
 */
function sh_ai_route(string $task): array
{
    $kind = sh_ai_tasks()[$task]['kind'] ?? 'text';
    $pid = (int)sh_ai_setting('task_' . $task . '_provider', '0');
    $model = sh_ai_setting('task_' . $task . '_model', '');
    if ($pid > 0) {
        $row = sh_ai_provider_row($pid);
        if ($row !== null && (int)$row['status'] === 1 && trim((string)$row['api_key']) !== '') {
            if ($model === '') { $model = (string)$row[$kind === 'image' ? 'default_image_model' : 'default_model']; }
            return ['provider' => $row, 'model' => $model, 'source' => 'task'];
        }
    }
    $row = sh_ai_default_provider_row();
    if ($row === null) { return ['provider' => null, 'model' => '', 'source' => 'none']; }
    return ['provider' => $row, 'model' => (string)$row[$kind === 'image' ? 'default_image_model' : 'default_model'], 'source' => 'default'];
}

/** Configured fallback for a task kind, or null when disabled / unset. */
function sh_ai_fallback(string $kind): ?array
{
    if (sh_ai_setting('fallback_enabled', '0') !== '1') { return null; }
    $suffix = $kind === 'image' ? 'fallback_image' : 'fallback';
    $pid = (int)sh_ai_setting($suffix . '_provider', '0');
    $row = $pid > 0 ? sh_ai_provider_row($pid) : null;
    if ($row === null || (int)$row['status'] !== 1 || trim((string)$row['api_key']) === '') { return null; }
    $model = sh_ai_setting($suffix . '_model', '');
    if ($model === '') { $model = (string)$row[$kind === 'image' ? 'default_image_model' : 'default_model']; }
    if ($model === '') { return null; }
    return ['provider' => $row, 'model' => $model];
}

/* ------------------------------------------------------------------ *
 * Generation logging
 * ------------------------------------------------------------------ */

function sh_ai_log_generation(array $row): int
{
    try {
        $usage = (array)($row['usage'] ?? []);
        return (int)sh_insert('ai_generations', [
            'admin_id'          => $row['admin_id'] ?? null,
            'type'              => mb_substr((string)($row['type'] ?? 'unknown'), 0, 40),
            'reference_type'    => $row['reference_type'] ?? null,
            'reference_id'      => $row['reference_id'] ?? null,
            'prompt'            => mb_substr((string)($row['prompt'] ?? ''), 0, 20000),
            'response'          => mb_substr((string)($row['response'] ?? ''), 0, 40000),
            'status'            => ($row['status'] ?? 'success') === 'failed' ? 'failed' : 'success',
            'provider'          => isset($row['provider']) ? mb_substr((string)$row['provider'], 0, 40) : null,
            'provider_id'       => $row['provider_id'] ?? null,
            'model'             => isset($row['model']) ? mb_substr((string)$row['model'], 0, 120) : null,
            'tokens_used'       => (int)($usage['total_tokens'] ?? $row['tokens_used'] ?? 0),
            'prompt_tokens'     => (int)($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
            'cost'              => isset($usage['cost']) ? (string)round((float)$usage['cost'], 8) : null,
            'fallback_used'     => !empty($row['fallback_used']) ? 1 : 0,
            'duration_ms'       => (int)($row['duration_ms'] ?? 0),
            'error_message'     => isset($row['error_message']) && $row['error_message'] !== null ? mb_substr((string)$row['error_message'], 0, 500) : null,
        ]);
    } catch (Throwable $e) {
        sh_log_exception($e, 'ai-log');
        return 0;
    }
}

/* ------------------------------------------------------------------ *
 * The unified call — what every task module uses
 * ------------------------------------------------------------------ */

/**
 * Run one operation ("text" | "structured" | "image") for a task through the
 * routed provider, optionally falling back once. Logs every attempt.
 *
 * @param array $meta admin_id, reference_type, reference_id, type, options, prompt (for the log), provider_id/model (explicit override)
 */
function sh_ai_dispatch(string $op, string $task, array $args, array $meta = []): array
{
    $kind = $op === 'image' ? 'image' : 'text';
    $cfg = sh_ai_config();

    if (!empty($meta['provider_id'])) {
        $row = sh_ai_provider_row((int)$meta['provider_id']);
        if ($row === null || (int)$row['status'] !== 1 || trim((string)$row['api_key']) === '') {
            return ['ok' => false, 'error' => 'The selected AI provider is disabled or has no API key.'];
        }
        $route = ['provider' => $row, 'model' => (string)($meta['model'] ?? $row[$kind === 'image' ? 'default_image_model' : 'default_model']), 'source' => 'explicit'];
    } else {
        $route = sh_ai_route($task);
    }
    if ($route['provider'] === null) {
        return ['ok' => false, 'error' => 'No AI provider is configured. Add one under AI Auto Work → Providers.'];
    }
    if ($route['model'] === '') {
        return ['ok' => false, 'error' => 'No ' . ($kind === 'image' ? 'image ' : '') . 'model is selected for ' . $route['provider']['name'] . '. Choose one under AI Auto Work → Models.'];
    }
    if (!sh_ai_model_supports((int)$route['provider']['id'], $route['model'], $kind)) {
        return ['ok' => false, 'capability' => true,
                'error' => $kind === 'image'
                    ? 'This provider/model does not support image generation.'
                    : 'The model "' . $route['model'] . '" on ' . $route['provider']['name'] . ' cannot produce text.'];
    }

    $attempts = [['provider' => $route['provider'], 'model' => $route['model'], 'fallback' => false]];
    $fb = sh_ai_fallback($kind);
    if ($fb !== null && !((int)$fb['provider']['id'] === (int)$route['provider']['id'] && $fb['model'] === $route['model'])
        && sh_ai_model_supports((int)$fb['provider']['id'], $fb['model'], $kind)) {
        $attempts[] = ['provider' => $fb['provider'], 'model' => $fb['model'], 'fallback' => true];
    }

    $options = ($meta['options'] ?? []) + ['temperature' => $cfg['temperature'], 'max_tokens' => $cfg['max_tokens']];
    $last = ['ok' => false, 'error' => 'The AI request failed.'];

    // Optional reference image for models that can READ images. The caller has
    // already validated it and base64-encoded the bytes; it is never logged and
    // it is only attached when the routed model really advertises image input.
    $imageIn = null;
    if (isset($meta['image']) && is_array($meta['image']) && (string)($meta['image']['data'] ?? '') !== '') {
        $imageIn = ['data' => (string)$meta['image']['data'], 'mime' => (string)($meta['image']['mime'] ?? '')];
    }

    foreach ($attempts as $i => $at) {
        $adapter = sh_ai_adapter($at['provider']);
        if ($adapter === null) { $last = ['ok' => false, 'error' => 'Unknown provider driver.']; continue; }
        $options['model'] = $at['model'];

        // Per-attempt, so a text-only primary can fall back to a vision model
        // (or the other way round) without ever sending an image to a model
        // that would reject it.
        $attemptOptions = $options;
        $imageSent = $imageIn !== null && $op !== 'image'
            && sh_ai_model_accepts_image((int)$at['provider']['id'], $at['model']);
        if ($imageSent) { $attemptOptions['image'] = $imageIn; }

        $started = microtime(true);
        $res = match ($op) {
            'structured' => $adapter->generateStructuredContent((string)$args['system'], (string)$args['user'], $attemptOptions),
            'image'      => $adapter->generateImage((string)$args['prompt'], $attemptOptions),
            default      => $adapter->generateText((string)$args['system'], (string)$args['user'], $attemptOptions),
        };
        $ms = (int)round((microtime(true) - $started) * 1000);

        sh_ai_log_generation([
            'admin_id'       => $meta['admin_id'] ?? null,
            'type'           => $meta['type'] ?? $task,
            'reference_type' => $meta['reference_type'] ?? null,
            'reference_id'   => $meta['reference_id'] ?? null,
            'prompt'         => $op === 'image' ? (string)$args['prompt'] : (string)$args['user'],
            'response'       => !empty($res['ok']) ? ($op === 'image' ? '[image ' . strlen((string)$res['binary']) . ' bytes]' : (string)($res['text'] ?? json_encode($res['data'] ?? null, JSON_UNESCAPED_UNICODE))) : (string)($res['text'] ?? ''),
            'status'         => !empty($res['ok']) ? 'success' : 'failed',
            'provider'       => $at['provider']['name'],
            'provider_id'    => (int)$at['provider']['id'],
            'model'          => (string)($res['model'] ?? $at['model']),
            'usage'          => $res['usage'] ?? [],
            'fallback_used'  => $at['fallback'],
            'duration_ms'    => $ms,
            'error_message'  => $res['error'] ?? null,
        ]);

        if (!empty($res['ok'])) {
            $res['provider'] = $at['provider']['name'];
            $res['provider_id'] = (int)$at['provider']['id'];
            $res['model'] = (string)($res['model'] ?? $at['model']);
            $res['fallback_used'] = $at['fallback'];
            // The truth about the image, so no caller can claim an image was
            // analysed when the model never received one.
            $res['image_used'] = $imageSent;
            $res['image_note'] = $imageIn === null ? 'none' : ($imageSent ? 'sent' : 'unsupported');
            return $res;
        }
        $last = $res;
        // Only move on to the fallback for transient failures.
        if (empty($res['retryable'])) { break; }
    }

    if (count($attempts) > 1 && !empty($last['retryable'])) {
        $last['error'] = (string)$last['error'] . ' (primary and fallback both failed)';
    }
    return ['ok' => false, 'error' => (string)($last['error'] ?? 'The AI request failed.')];
}

function sh_ai_text(string $task, string $system, string $user, array $meta = []): array
{
    return sh_ai_dispatch('text', $task, ['system' => $system, 'user' => $user], $meta);
}

function sh_ai_structured(string $task, string $system, string $user, array $meta = []): array
{
    return sh_ai_dispatch('structured', $task, ['system' => $system, 'user' => $user], $meta);
}

function sh_ai_image(string $task, string $prompt, array $meta = []): array
{
    return sh_ai_dispatch('image', $task, ['prompt' => $prompt], $meta);
}

/** Human labels for the machine codes a provider test can return. */
function sh_ai_test_state_labels(): array
{
    return [
        'connected'           => 'Connected',
        'token_limit'         => 'Connected — token limit',
        'config_incomplete'   => 'Configuration incomplete',
        'provider_missing'    => 'Provider missing',
        'provider_disabled'   => 'Provider disabled',
        'missing_key'         => 'API key missing',
        'credential_decryption_failed' => 'Saved key unavailable',
        'auth_failed'         => 'Authentication failed',
        'permission_denied'   => 'Permission denied',
        'model_not_found'     => 'Model not available',
        'model_unavailable'   => 'Model not available',
        'request_rejected'    => 'Request rejected',
        'empty_response'      => 'Empty model response',
        'invalid_response'    => 'Invalid provider response',
        'rate_limited'        => 'Rate limited',
        'quota_exceeded'      => 'Quota or billing limit',
        'billing'             => 'Billing problem',
        'timeout'             => 'Timed out',
        'dns_error'           => 'DNS lookup failed',
        'tls_error'           => 'TLS verification failed',
        'connection_refused'  => 'Connection refused',
        'connection_failed'   => 'Connection failed',
        'transport_unavailable' => 'HTTP transport unavailable',
        'network_error'       => 'Network error',
        'provider_error'      => 'Provider error',
        'failed'              => 'Test failed',
    ];
}

function sh_ai_test_state_label(string $code): string
{
    return sh_ai_test_state_labels()[$code] ?? 'Test failed';
}

/**
 * Badge tone for a test code: ok / warn / bad. A token limit, missing
 * configuration or unreachable transport is a warning, not evidence of a bad key.
 */
function sh_ai_test_state_tone(string $code): string
{
    if ($code === 'connected') { return 'ok'; }
    if (in_array($code, ['token_limit', 'config_incomplete', 'provider_missing', 'provider_disabled', 'missing_key', 'credential_decryption_failed', 'model_not_found', 'model_unavailable', 'request_rejected', 'rate_limited', 'quota_exceeded', 'timeout', 'dns_error', 'tls_error', 'connection_refused', 'connection_failed', 'transport_unavailable', 'network_error'], true)) {
        return 'warn';
    }
    return 'bad';
}

/** Redact provider secrets, configured extra-header values and inline credentials from test diagnostics. */
function sh_ai_redact_provider_test_text(string $text, array $provider): string
{
    $secrets = [];
    if (function_exists('sh_ai_decrypt')) {
        $key = sh_ai_decrypt((string)($provider['api_key'] ?? ''));
        if ($key !== '') { $secrets[] = $key; }
    }
    $auth = trim((string)($provider['auth_header'] ?? ''));
    if ($auth !== '') { $secrets[] = $auth; }
    $extra = json_decode((string)($provider['extra_headers'] ?? ''), true);
    if (is_array($extra)) {
        foreach ($extra as $value) { if (is_scalar($value)) { $secrets[] = (string)$value; } }
    }
    foreach ($secrets as $secret) {
        if (strlen($secret) >= 6) { $text = str_replace($secret, '[redacted]', $text); }
    }
    $text = preg_replace('/\b(?:sk-[A-Za-z0-9_-]{8,}|AIza[0-9A-Za-z_-]{20,}|Bearer\s+[A-Za-z0-9._~+\/-]{10,})\b/i', '[redacted]', $text) ?? $text;
    $text = preg_replace('/\b[A-Za-z0-9_.-]*(?:api[_ -]?key|secret|token|password|authorization)[A-Za-z0-9_.-]*\s*[:=]\s*[^\s,;]+/i', '[credential]=[redacted]', $text) ?? $text;
    return mb_substr(trim(preg_replace('/\s+/', ' ', $text) ?? $text), 0, 255);
}

/** Test one configured provider and remember the real outcome. */
function sh_ai_provider_test(int $providerId): array
{
    $row = sh_ai_provider_row($providerId);
    $adapter = $row ? sh_ai_adapter($row) : null;
    if ($adapter === null) {
        return ['ok' => false, 'code' => 'config_incomplete', 'message' => 'This provider type is unknown.',
                'status' => 0, 'model' => '', 'next' => 'Edit the provider and choose a supported type.',
                'tested_at' => date('Y-m-d H:i:s'), 'error' => 'This provider type is unknown.'];
    }

    try {
        $res = $adapter->testConnection();
    } catch (Throwable $e) {
        sh_log_line('ai-provider-test', 'Provider adapter test threw an exception; details omitted.');
        $res = ['ok' => false, 'code' => 'provider_error', 'status' => 0,
                'message' => 'The provider test could not be completed due to an internal error.',
                'next' => 'Check the provider configuration and server logs, then test again.'];
    }
    foreach (['message', 'error', 'detail', 'next'] as $k) {
        if (isset($res[$k])) { $res[$k] = sh_ai_redact_provider_test_text((string)$res[$k], $row); }
    }
    if (!isset($res['code']) || $res['code'] === '') {
        $res['code'] = !empty($res['ok']) ? 'connected' : 'failed';
    }
    $res['message'] = (string)($res['message'] ?? ($res['ok'] ? ($res['detail'] ?? 'Connected.') : ($res['error'] ?? 'The test failed.')));
    $res['message'] = mb_substr(trim(preg_replace('/\s+/', ' ', $res['message']) ?? $res['message']), 0, 240);
    $res['tested_at'] = (string)($res['tested_at'] ?? date('Y-m-d H:i:s'));
    $res['status'] = (int)($res['status'] ?? 0);
    $res['next'] = (string)($res['next'] ?? '');
    if (!empty($res['ok'])) { $res['detail'] = $res['message']; } else { $res['error'] = $res['message']; }

    sh_ai_test_columns_ensure();
    $data = [
        'last_tested_at' => $res['tested_at'],
        'last_test_ok'   => !empty($res['ok']) ? 1 : 0,
        'last_test_note' => mb_substr($res['message'], 0, 255),
    ];
    if (sh_ai_column_exists('ai_providers', 'last_test_code')) {
        $data['last_test_code']   = mb_substr((string)$res['code'], 0, 40);
        $data['last_test_status'] = $res['status'] > 0 ? $res['status'] : null;
        $data['last_test_next']   = mb_substr((string)$res['next'], 0, 255);
    }
    try {
        sh_update('ai_providers', $data, 'id = ?', [$providerId]);
        sh_ai_providers_all(true);
    } catch (Throwable $e) {
        sh_log_exception($e, 'ai-provider-test-save');
    }
    return $res;
}
