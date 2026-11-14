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

/** Adapter for the existing footer widget's URL-encoded start/new/poll protocol. */
function sh_support_legacy_widget_fail(string $message, int $status = 400): void
{
    sh_json(['ok' => false, 'error' => $message], $status);
}

function sh_support_legacy_widget_message(array $message): array
{
    $sender = (string)($message['sender'] ?? 'system');
    $kind = $sender === 'customer' ? 'customer'
        : ($sender === 'admin' ? 'agent'
            : (in_array($sender, ['assistant', 'ai'], true) ? 'assistant' : 'notice'));
    return [
        'id' => (int)($message['id'] ?? 0),
        'kind' => $kind,
        'body' => (string)($message['body'] ?? ''),
        'at' => (string)($message['created_at'] ?? ''),
        'ai' => in_array($sender, ['assistant', 'ai'], true),
    ];
}

function sh_support_legacy_widget_payload(array $snapshot, ?array $messages = null): array
{
    $conversation = is_array($snapshot['conversation'] ?? null) ? $snapshot['conversation'] : [];
    $status = (string)($conversation['status'] ?? 'open');
    $paused = !empty($conversation['ai_paused']);
    // One source of truth: the same state machine the message pipeline uses.
    $human = sh_support_human_controlled($conversation);
    $rows = $messages ?? (is_array($snapshot['messages'] ?? null) ? $snapshot['messages'] : []);
    return [
        'ok' => true,
        'conversation' => [
            'id' => (int)($conversation['id'] ?? 0),
            'status' => $status,
            'human' => $human,
            'ai_paused' => $paused,
            'assigned_admin_id' => (int)($conversation['assigned_admin_id'] ?? 0),
        ],
        'messages' => array_map('sh_support_legacy_widget_message', $rows),
        'suggestions' => [],
    ];
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
    $requiredAdminPerms = $action === 'admin_mark_read'
        ? ['support.view', 'support.reply', 'support.manage']
        : ($method === 'POST'
            ? ['support.reply', 'support.manage']
            : ['support.view', 'support.reply', 'support.manage']);
    if (!sh_admin_can(...$requiredAdminPerms)) { sh_support_api_fail('You do not have permission to use this support action.', 403); }
    if ($method === 'POST') { sh_csrf_require(); }
    $adminId = (int)$admin['id'];
    if (!sh_support_schema_ensure()) { sh_support_api_fail('The support database is not ready. Run the support migration.', 503); }

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

    if ($method === 'POST' && $action === 'admin_mark_read') {
        $conversationId = max(0, (int)($input['id'] ?? 0));
        if ($conversationId <= 0 || sh_support_admin_conversation($conversationId) === null) {
            sh_support_api_fail('Conversation not found.', 404);
        }
        sh_support_admin_mark_read($conversationId);
        sh_json(['success' => true]);
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
        // Explicit server-side allowlist: the browser never gets to invent an
        // ownership or AI-state change. Every entry reaches the same RBAC,
        // CSRF, prepared-SQL and FOR UPDATE checks inside the service layer.
        $transition = (string)($input['transition'] ?? '');
        $allowedTransitions = [
            'join', 'assign_to_me', 'assign', 'unassign', 'leave', 'takeover',
            'close', 'resolve', 'reopen', 'resume_ai',
        ];
        if (!in_array($transition, $allowedTransitions, true)) {
            sh_support_api_fail('Unknown support action.', 400);
        }
        $result = sh_support_admin_transition(
            max(0, (int)($input['id'] ?? 0)),
            $adminId,
            $transition,
            max(0, (int)($input['target_admin_id'] ?? 0)),
            (string)($input['reason'] ?? '')
        );
        if (empty($result['ok'])) { sh_support_api_fail((string)($result['error'] ?? 'The conversation status could not be changed.'), (int)($result['http_status'] ?? 400)); }
        sh_json(['success' => true] + $result);
    }

    sh_support_api_fail('Unknown support action.', 404);
}

if (!sh_support_schema_ensure()) { sh_support_api_fail('The support database is not ready. Run the support migration.', 503); }
sh_session_start();
$guestToken = sh_support_guest_token();
$owner = sh_support_owner_context(
    sh_user_id(),
    $guestToken,
    is_array($_SESSION['guest_orders'] ?? null) ? $_SESSION['guest_orders'] : []
);
if ($method === 'POST') { sh_csrf_require(); }

if ($method === 'POST' && $action === 'mark_read') {
    $result = sh_support_customer_mark_read(max(0, (int)($input['id'] ?? 0)), $owner);
    if ($result === false) { sh_support_api_fail('Conversation not found.', 404); }
    if ($result === null) { sh_support_api_fail('The conversation could not be updated.', 500); }
    sh_json(['success' => true]);
}

$legacyWidgetAction = $method === 'POST' && in_array($action, ['start', 'new', 'poll'], true);
$legacyWidgetAction = $legacyWidgetAction
    || ($method === 'POST' && $action === 'send' && isset($input['conversation_id']) && !isset($input['id']))
    || ($method === 'POST' && $action === 'handoff' && isset($input['conversation_id']) && !isset($input['id']));
if ($legacyWidgetAction) {
    if (in_array($action, ['start', 'new'], true)) {
        if (!sh_support_request_rate_allowed($owner)) { sh_support_legacy_widget_fail('Too many requests. Please wait and try again.', 429); }
        $created = sh_support_create_conversation($owner);
        if (empty($created['ok']) || !is_array($created['conversation'] ?? null)) {
            sh_support_legacy_widget_fail((string)($created['error'] ?? 'Could not start a conversation.'), 503);
        }
        $snapshot = sh_support_customer_snapshot((int)$created['conversation']['id'], $owner);
        if (empty($snapshot['ok'])) { sh_support_legacy_widget_fail('Could not load the conversation.', 500); }
        sh_json(sh_support_legacy_widget_payload($snapshot));
    }

    $conversationId = max(0, (int)($input['conversation_id'] ?? 0));
    if ($action === 'poll') {
        $conversation = sh_support_conversation_for_owner($conversationId, $owner);
        if ($conversation === null) { sh_support_legacy_widget_fail('Conversation not found.', 404); }
        sh_query('UPDATE support_conversations SET unread_for_customer = 0 WHERE id = ?', [$conversationId]);
        $afterId = max(0, (int)($input['after_id'] ?? 0));
        $messages = sh_all("SELECT * FROM support_messages
                            WHERE conversation_id = ? AND id > ? AND is_internal = 0
                              AND sender <> 'internal_note'
                            ORDER BY id ASC LIMIT 50", [$conversationId, $afterId]);
        sh_json(sh_support_legacy_widget_payload(['conversation' => $conversation], $messages));
    }

    if ($action === 'send') {
        $result = sh_support_customer_send(
            $conversationId,
            $owner,
            (string)($input['message'] ?? ''),
            (string)($input['client_msg_id'] ?? '')
        );
        if (empty($result['ok'])) {
            sh_support_legacy_widget_fail((string)($result['error'] ?? 'The message could not be saved.'), (int)($result['http_status'] ?? 400));
        }
        $snapshot = is_array($result['snapshot'] ?? null) ? $result['snapshot'] : sh_support_customer_snapshot($conversationId, $owner);
        sh_json(sh_support_legacy_widget_payload($snapshot));
    }

    if ($action === 'handoff') {
        $result = sh_support_customer_handoff($conversationId, $owner, (string)($input['client_msg_id'] ?? ''));
        if (empty($result['ok'])) {
            sh_support_legacy_widget_fail((string)($result['error'] ?? 'The handoff request could not be saved.'), (int)($result['http_status'] ?? 400));
        }
        $snapshot = is_array($result['snapshot'] ?? null) ? $result['snapshot'] : sh_support_customer_snapshot($conversationId, $owner);
        sh_json(sh_support_legacy_widget_payload($snapshot));
    }
}

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

sh_support_api_fail('Unknown support action.', 404);
