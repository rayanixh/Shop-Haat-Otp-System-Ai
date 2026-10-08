<?php
/**
 * AI Auto Work — admin AJAX endpoint. Always returns JSON.
 *
 * Every action requires: an authenticated admin session, a valid CSRF token and
 * validated input. All provider calls happen here, server-side, so the API key
 * never reaches a browser.
 */
declare(strict_types=1);
define('SH_JSON_CONTEXT', true);
require_once dirname(__DIR__) . '/config/config.php';

if (sh_db_config() === null || !sh_is_locked()) {
    sh_json(['success' => false, 'error' => 'The store is not installed yet.'], 503);
}
try { sh_db(); } catch (Throwable $e) {
    sh_log_exception($e, 'api-ai-db');
    sh_json(['success' => false, 'error' => 'Service temporarily unavailable.'], 503);
}

require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/ai/AIManager.php';

sh_session_start();
$admin = sh_require_admin();          // redirects/401s anyone who is not an admin
$adminId = (int)($admin['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_json(['success' => false, 'error' => 'POST required.'], 405);
}
sh_csrf_require();

if (!sh_ai_installed()) {
    sh_json(['success' => false, 'error' => 'AI Auto Work is not installed. Open AI Settings and run the installer.'], 400);
}

$action = sh_post('action');

/** Simple per-admin throttle so a stuck loop cannot burn credit. */
function sh_ai_throttle(int $adminId, int $perMinute = 30): bool
{
    try {
        $n = (int)sh_val(
            'SELECT COUNT(*) FROM ai_generations WHERE admin_id = ? AND created_at > (NOW() - INTERVAL 1 MINUTE)',
            [$adminId], 0
        );
        return $n < $perMinute;
    } catch (Throwable $e) {
        return true;
    }
}

try {
    switch ($action) {

        /* ---------- single generation (preview only, nothing saved) ------- */
        case 'generate': {
            if (!sh_ai_throttle($adminId)) {
                sh_json(['success' => false, 'error' => 'Too many requests in the last minute. Please wait a moment.'], 429);
            }
            $task = sh_post('task');
            $refId = sh_int($_POST['ref_id'] ?? 0);
            $allowed = ['title', 'description', 'short', 'tags', 'seo', 'category',
                        'image_prompt', 'image', 'category_content', 'blog'];
            if (!in_array($task, $allowed, true)) {
                sh_json(['success' => false, 'error' => 'Unknown generation task.'], 400);
            }
            if ($task !== 'blog' && $refId <= 0) {
                sh_json(['success' => false, 'error' => 'Choose an item first.'], 400);
            }

            $extra = [];
            if ($task === 'blog') {
                $extra = [
                    'topic'    => mb_substr(sh_post('topic'), 0, 300),
                    'audience' => mb_substr(sh_post('audience'), 0, 200),
                    'keywords' => mb_substr(sh_post('keywords'), 0, 300),
                    'length'   => max(200, min(2000, sh_int($_POST['length'] ?? 700))),
                ];
                if (trim($extra['topic']) === '') {
                    sh_json(['success' => false, 'error' => 'Enter a topic for the blog post.'], 400);
                }
            }
            if ($task === 'image') {
                $extra['prompt'] = mb_substr(sh_post('prompt'), 0, 3000);
                // Image AI lets the admin pick a compatible provider/model explicitly.
                $pid = sh_int($_POST['provider_id'] ?? 0);
                if ($pid > 0) {
                    $extra['provider_id'] = $pid;
                    $extra['model'] = mb_substr(sh_post('model'), 0, 120);
                }
            }

            $res = sh_ai_run_task($task, $refId, $adminId, $extra);
            if (empty($res['ok'])) {
                sh_json(['success' => false, 'error' => (string)$res['error'], 'capability' => !empty($res['capability'])]);
            }

            $payload = ['success' => true, 'task' => $task, 'data' => $res['data'], 'via' => $res['via'] ?? null];
            if ($task === 'image' && !empty($res['data']['file'])) {
                $payload['url'] = sh_url('uploads/products/' . rawurlencode((string)$res['data']['file']));
            }
            // Auto-save applies only where a straight column write is unambiguous.
            if (sh_ai_config()['auto_save']
                && in_array($task, ['title', 'description', 'short', 'tags', 'seo', 'category_content'], true)) {
                $applied = sh_ai_apply($task, $refId, (array)$res['data'], $adminId);
                $payload['auto_saved'] = !empty($applied['ok']);
            }
            sh_json($payload);
        }

        /* ---------- persist an approved result ---------------------------- */
        case 'save': {
            $task = sh_post('task');
            $refId = sh_int($_POST['ref_id'] ?? 0);
            $raw = (string)($_POST['data'] ?? '');
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                sh_json(['success' => false, 'error' => 'Nothing to save.'], 400);
            }
            $allowed = ['title', 'description', 'short', 'tags', 'seo', 'category', 'image', 'category_content'];
            if (!in_array($task, $allowed, true) || $refId <= 0) {
                sh_json(['success' => false, 'error' => 'Unknown save target.'], 400);
            }
            $res = sh_ai_apply($task, $refId, $data, $adminId);
            if (empty($res['ok'])) { sh_json(['success' => false, 'error' => (string)$res['error']]); }
            sh_log_line('admin', 'AI applied "' . $task . '" to #' . $refId . ' by ' . ($admin['email'] ?? ''));
            sh_json(['success' => true, 'message' => 'Saved.']);
        }

        /* ---------- blog: save as draft or publish ------------------------ */
        case 'save_blog': {
            $data = json_decode((string)($_POST['data'] ?? ''), true);
            if (!is_array($data)) { sh_json(['success' => false, 'error' => 'Nothing to save.'], 400); }
            // Publishing requires BOTH an explicit request and the setting enabled.
            $wants = !empty($_POST['publish']);
            $publish = $wants && sh_ai_config()['auto_publish'];
            $res = sh_ai_save_blog($data, $adminId, $publish);
            if (empty($res['ok'])) { sh_json(['success' => false, 'error' => (string)$res['error']]); }
            $msg = $res['status'] === 'published'
                ? 'Post published.'
                : ($wants && !$publish
                    ? 'Saved as a draft. Enable "Allow auto publish" in AI Settings to publish directly.'
                    : 'Saved as a draft.');
            sh_json(['success' => true, 'message' => $msg, 'id' => $res['id'], 'status' => $res['status']]);
        }

        /* ---------- create a category the AI suggested -------------------- */
        case 'create_category': {
            $name = trim(mb_substr(sh_post('name'), 0, 120));
            $productId = sh_int($_POST['ref_id'] ?? 0);
            if ($name === '') { sh_json(['success' => false, 'error' => 'The category needs a name.'], 400); }
            $slug = sh_slug($name);
            if ($slug === '') { sh_json(['success' => false, 'error' => 'Could not build a URL slug.'], 400); }
            $exists = sh_one('SELECT id FROM categories WHERE slug = ? OR name = ? LIMIT 1', [$slug, $name]);
            if ($exists !== null) {
                // Never create a duplicate; reuse what is already there.
                $catId = (int)$exists['id'];
            } else {
                $catId = (int)sh_insert('categories', [
                    'name' => $name, 'slug' => $slug, 'icon' => 'grid',
                    'sort_order' => (int)sh_val('SELECT COALESCE(MAX(sort_order),0)+1 FROM categories', [], 1),
                    'status' => 1,
                ]);
            }
            if ($productId > 0) { sh_query('UPDATE products SET category_id = ? WHERE id = ?', [$catId, $productId]); }
            sh_json(['success' => true, 'category_id' => $catId,
                     'message' => $exists !== null ? 'That category already existed and was applied.' : 'Category created and applied.']);
        }

        /* ---------- bulk queue -------------------------------------------- */
        case 'bulk_queue': {
            $ids = $_POST['products'] ?? [];
            $tasks = $_POST['tasks'] ?? [];
            if (is_string($ids)) { $ids = explode(',', $ids); }
            if (is_string($tasks)) { $tasks = explode(',', $tasks); }
            if (!is_array($ids) || !is_array($tasks)) {
                sh_json(['success' => false, 'error' => 'Select products and tasks.'], 400);
            }
            $res = sh_ai_queue_batch($ids, $tasks, $adminId, sh_int($_POST['provider_id'] ?? 0), mb_substr(sh_post('model'), 0, 120));
            if (empty($res['ok'])) { sh_json(['success' => false, 'error' => (string)$res['error']]); }
            sh_json(['success' => true] + $res);
        }

        case 'bulk_process': {
            $batch = preg_replace('/[^a-z0-9]/i', '', sh_post('batch_id')) ?? '';
            if ($batch === '') { sh_json(['success' => false, 'error' => 'Unknown batch.'], 400); }
            $progress = sh_ai_process_batch($batch, $adminId);
            sh_json(['success' => true] + $progress);
        }

        case 'bulk_status': {
            $batch = preg_replace('/[^a-z0-9]/i', '', sh_post('batch_id')) ?? '';
            if ($batch === '') { sh_json(['success' => false, 'error' => 'Unknown batch.'], 400); }
            sh_json(['success' => true] + sh_ai_batch_progress($batch));
        }

        case 'bulk_retry': {
            $batch = preg_replace('/[^a-z0-9]/i', '', sh_post('batch_id')) ?? '';
            if ($batch === '') { sh_json(['success' => false, 'error' => 'Unknown batch.'], 400); }
            $res = sh_ai_retry_failed($batch);
            if (empty($res['ok'])) { sh_json(['success' => false, 'error' => (string)$res['error']]); }
            sh_json(['success' => true] + $res);
        }

        /* ---------- providers: verify the credentials really work --------- */
        case 'test_connection': {
            $pid = sh_int($_POST['provider_id'] ?? 0);
            if ($pid <= 0) {
                $d = sh_ai_default_provider_row();
                $pid = $d ? (int)$d['id'] : 0;
            }
            if ($pid <= 0) { sh_json(['success' => false, 'error' => 'No provider configured.']); }
            $res = sh_ai_provider_test($pid);
            if (empty($res['ok'])) { sh_json(['success' => false, 'error' => (string)$res['error']]); }
            sh_json(['success' => true, 'message' => 'Connection OK. ' . (string)($res['detail'] ?? '')]);
        }

        /* ---------- providers: import the live model catalogue ------------ */
        case 'sync_models': {
            $pid = sh_int($_POST['provider_id'] ?? 0);
            if ($pid <= 0) { sh_json(['success' => false, 'error' => 'Unknown provider.'], 400); }
            $res = sh_ai_models_sync($pid);
            if (empty($res['ok'])) { sh_json(['success' => false, 'error' => (string)$res['error']]); }
            $models = [];
            foreach (sh_ai_models($pid) as $m) {
                $models[] = ['id' => (string)$m['model_id'], 'name' => (string)$m['name'],
                             'text' => (int)$m['output_text'], 'image' => (int)$m['output_image'], 'enabled' => (int)$m['status']];
            }
            sh_json(['success' => true, 'count' => (int)$res['count'], 'models' => $models,
                     'message' => (int)$res['count'] . ' models loaded from the provider.']);
        }

        /* ---------- models: enabled models of one provider (no secrets) --- */
        case 'provider_models': {
            $pid = sh_int($_POST['provider_id'] ?? 0);
            $kind = sh_post('kind') === 'image' ? 'image' : 'text';
            $models = [];
            foreach (sh_ai_models($pid, true) as $m) {
                if ($kind === 'image' ? empty($m['output_image']) : empty($m['output_text'])) { continue; }
                $models[] = ['id' => (string)$m['model_id'], 'name' => (string)$m['name']];
            }
            sh_json(['success' => true, 'models' => $models]);
        }

        /* ---------- product picker for the AI screens --------------------- */
        case 'search_products': {
            $q = trim(sh_post('q'));
            $rows = $q === ''
                ? sh_all('SELECT id, name, image FROM products ORDER BY id DESC LIMIT 20')
                : sh_all('SELECT id, name, image FROM products WHERE name LIKE ? OR sku LIKE ? ORDER BY name ASC LIMIT 20',
                    ['%' . $q . '%', '%' . $q . '%']);
            $out = [];
            foreach ($rows as $r) {
                $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'],
                          'image' => sh_product_image($r['image'])];
            }
            sh_json(['success' => true, 'products' => $out]);
        }
    }

    sh_json(['success' => false, 'error' => 'Unknown action.'], 400);

} catch (Throwable $e) {
    // Never leak a stack trace, SQL or a path to the browser.
    sh_log_exception($e, 'api-ai');
    sh_json(['success' => false, 'error' => 'Something went wrong. The error has been logged.'], 500);
}
