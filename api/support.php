<?php
declare(strict_types=1);
define('SH_JSON_CONTEXT', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once SH_ROOT . '/includes/ai-support.php';

header('Cache-Control: no-store, private');
header('Vary: Cookie');

function sh_support_api_fail(string $message, int $status = 400): void
{
    sh_json(['success' => false, 'error' => $message], $status);
}

if (sh_db_config() === null || !sh_is_locked()) {
    sh_support_api_fail('The store is not installed yet.', 503);
}
try {
    $pdo = sh_db();
    if (!sh_tables_installed($pdo)) { sh_support_api_fail('The store database is not ready.', 503); }
} catch (Throwable $e) {
    sh_log_line('ai-support-api', 'Database connection failed; details omitted.');
    sh_support_api_fail('Service temporarily unavailable.', 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    sh_support_api_fail('GET or POST required.', 405);
}
if (!sh_support_origin_valid()) { sh_support_api_fail('This request did not come from the ShopHaat site.', 403); }
$fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite === 'cross-site') { sh_support_api_fail('Cross-site requests are not allowed.', 403); }

$input = [];
if ($method === 'POST') {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) { sh_support_api_fail('The request is too large.', 413); }
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if ($raw === false || strlen($raw) > 65536) { sh_support_api_fail('The request is too large.', 413); }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) { sh_support_api_fail('The request body is not valid JSON.', 400); }
        $input = $decoded;
    } else {
        $input = $_POST;
    }
}
$action = trim((string)($method === 'GET' ? ($_GET['action'] ?? '') : ($input['action'] ?? '')));
if ($action === '') { sh_support_api_fail('Choose a support action.', 400); }

$isAdminAction = str_starts_with($action, 'admin_');
if ($isAdminAction) {
    require_once SH_ROOT . '/includes/admin-auth.php';
    require_once SH_ROOT . '/includes/admin-perms.php';
    sh_session_start();
    $admin = sh_require_admin();
    sh_perms_schema_ensure();
    if (!sh_admin_can('support.manage')) { sh_support_api_fail('You do not have permission to use the support inbox.', 403); }
    if ($method === 'POST') { sh_csrf_require(); }
    $adminId = (int)$admin['id'];
    if (!sh_support_schema_ensure()) { sh_support_api_fail('The support database is not ready. Run the support migration.', 503); }
    sh_support_retention_prune();

    if ($method === 'GET' && $action === 'admin_list') {
        $result = sh_support_admin_conversations(
            (string)($_GET['status'] ?? ''),
            (string)($_GET['q'] ?? ''),
            max(1, (int)($_GET['page'] ?? 1)),
            max(1, min(50, (int)($_GET['per_page'] ?? 25)))
        );
        sh_json(['success' => true, 'data' => $result, 'stats' => sh_support_usage_stats()]);
    }

    if ($method === 'GET' && $action === 'admin_thread') {
        $conversationId = max(0, (int)($_GET['id'] ?? 0));
        $conversation = sh_support_admin_conversation($conversationId);
        if ($conversation === null) { sh_support_api_fail('Conversation not found.', 404); }
        sh_support_admin_mark_read($conversationId);
        $messages = sh_support_admin_messages($conversationId, max(0, (int)($_GET['before_id'] ?? 0)), 30);
        sh_json([
            'success' => true,
            'conversation' => $conversation,
            'messages' => $messages['messages'],
            'has_more' => $messages['has_more'],
            'older_cursor' => $messages['older_cursor'],
            'events' => sh_support_admin_events($conversationId),
            'agents' => sh_support_authorized_agents(),
        ]);
    }

    if ($method === 'GET' && $action === 'admin_poll') {
        $conversationId = max(0, (int)($_GET['id'] ?? 0));
        $poll = sh_support_admin_poll(
            $conversationId,
            max(0, (int)($_GET['after_message_id'] ?? 0)),
            max(0, (int)($_GET['after_event_id'] ?? 0)),
            25
        );
        if (empty($poll['ok'])) { sh_support_api_fail((string)($poll['error'] ?? 'Conversation not found.'), 404); }
        sh_json(['success' => true] + $poll);
    }

    if ($method === 'POST' && $action === 'admin_reply') {
        $result = sh_support_admin_reply(
            max(0, (int)($input['id'] ?? 0)),
            $adminId,
            (string)($input['body'] ?? ''),
            (string)($input['client_id'] ?? ''),
            !empty($input['internal'])
        );
        if (empty($result['ok'])) { sh_support_api_fail((string)($result['error'] ?? 'The message could not be saved.'), (int)($result['http_status'] ?? 400)); }
        sh_json(['success' => true] + $result);
    }

    if ($method === 'POST' && $action === 'admin_transition') {
        $result = sh_support_admin_transition(
            max(0, (int)($input['id'] ?? 0)),
            $adminId,
            (string)($input['transition'] ?? ''),
            max(0, (int)($input['target_admin_id'] ?? 0)),
            (string)($input['reason'] ?? '')
        );
        if (empty($result['ok'])) { sh_support_api_fail((string)($result['error'] ?? 'The conversation status could not be changed.'), (int)($result['http_status'] ?? 400)); }
        sh_json(['success' => true] + $result);
    }

    sh_support_api_fail('Unknown support action.', 404);
}

if (!sh_support_schema_ensure()) { sh_support_api_fail('The support database is not ready. Run the support migration.', 503); }
sh_support_retention_prune();
sh_session_start();
$guestToken = sh_support_guest_token();
$owner = sh_support_owner_context(
    sh_user_id(),
    $guestToken,
    is_array($_SESSION['guest_orders'] ?? null) ? $_SESSION['guest_orders'] : []
);
if ($method === 'POST') { sh_csrf_require(); }

if ($method === 'GET' && $action === 'state') {
    sh_json(sh_support_customer_state($owner));
}

if ($method === 'POST' && $action === 'open') {
    if (!sh_support_request_rate_allowed($owner)) { sh_support_api_fail('Too many requests. Please wait and try again.', 429); }
    $created = sh_support_create_conversation($owner);
    if (empty($created['ok'])) { sh_support_api_fail((string)($created['error'] ?? 'Could not start a conversation.'), 503); }
    $conversation = $created['conversation'] ?? null;
    if (!is_array($conversation)) { sh_support_api_fail('Could not load the conversation.', 500); }
    sh_json(sh_support_customer_snapshot((int)$conversation['id'], $owner));
}

if ($method === 'GET' && $action === 'messages') {
    $conversationId = max(0, (int)($_GET['id'] ?? 0));
    if (sh_support_conversation_for_owner($conversationId, $owner) === null) { sh_support_api_fail('Conversation not found.', 404); }
    $page = sh_support_customer_messages($conversationId, max(0, (int)($_GET['before_id'] ?? 0)), 30);
    sh_json(['success' => true] + $page);
}

if ($method === 'POST' && $action === 'send') {
    $result = sh_support_customer_send(
        max(0, (int)($input['id'] ?? 0)),
        $owner,
        (string)($input['body'] ?? ''),
        (string)($input['client_id'] ?? '')
    );
    if (empty($result['ok'])) { sh_support_api_fail((string)($result['error'] ?? 'The message could not be saved.'), (int)($result['http_status'] ?? 400)); }
    sh_json(['success' => true] + $result);
}

if ($method === 'POST' && $action === 'retry') {
    $result = sh_support_customer_retry(max(0, (int)($input['id'] ?? 0)), max(0, (int)($input['message_id'] ?? 0)), $owner);
    if (empty($result['ok'])) { sh_support_api_fail((string)($result['error'] ?? 'The retry could not be started.'), (int)($result['http_status'] ?? 400)); }
    sh_json(['success' => true] + $result);
}

if ($method === 'POST' && $action === 'handoff') {
    $result = sh_support_customer_handoff(max(0, (int)($input['id'] ?? 0)), $owner, (string)($input['client_id'] ?? ''));
    if (empty($result['ok'])) { sh_support_api_fail((string)($result['error'] ?? 'The handoff request could not be saved.'), (int)($result['http_status'] ?? 400)); }
    sh_json(['success' => true] + $result);
}

if ($method === 'POST' && $action === 'delete') {
    $result = sh_support_customer_delete(max(0, (int)($input['id'] ?? 0)), $owner);
    if (empty($result['ok'])) { sh_support_api_fail((string)($result['error'] ?? 'The conversation could not be deleted.'), (int)($result['http_status'] ?? 400)); }
    sh_json(['success' => true]);
}

sh_support_api_fail('Unknown support action.', 404);
