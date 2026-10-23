<?php
/**
 * Customer support chat API (storefront).
 *
 * Thin wrapper over includes/support.php. Every action is CSRF-checked and
 * ownership is verified against the signed-in customer or the anonymous
 * browser session — a conversation id alone is never proof of ownership.
 */
define('SH_JSON_CONTEXT', true);
require_once __DIR__ . '/../config/config.php';

if (sh_db_config() === null || !sh_is_locked()) {
    http_response_code(503);
    sh_json(['ok' => false, 'error' => 'Store is not available yet.']);
}
try { sh_db(); } catch (Throwable $e) {
    http_response_code(503);
    sh_json(['ok' => false, 'error' => 'Store is temporarily unavailable.']);
}
require_once SH_ROOT . '/includes/functions.php';
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/csrf.php';
require_once SH_ROOT . '/includes/validation.php';
require_once SH_ROOT . '/includes/support.php';
sh_session_start();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    sh_json(['ok' => false, 'error' => 'Method not allowed.']);
}
sh_csrf_require();

$cfg = sh_support_cfg();
if (!$cfg['enabled']) {
    http_response_code(503);
    sh_json(['ok' => false, 'error' => 'Support chat is currently unavailable.']);
}
sh_support_ensure_schema();

$identity = sh_support_identity();
$action = sh_post('action');

function sh_support_api_welcome(array $conv, array $cfg): void
{
    $lang = sh_support_pick_lang((string)($conv['lang'] ?? ''), $cfg);
    if (($conv['lang'] ?? '') === 'en' && in_array('banglish', $cfg['languages'], true)) { $lang = 'banglish'; }
    $text = trim((string)($cfg['welcome_' . $lang] ?? ''));
    if ($text === '') { $text = (string)sh_support_defaults()['welcome_' . $lang]; }
    sh_support_insert_message((int)$conv['id'], 'system', $text, ['lang' => $lang, 'facts' => ['kind' => 'assistant', 'source' => 'welcome']]);
}

function sh_support_api_suggestions(string $lang): array
{
    return match ($lang) {
        'bn' => ['আমার অর্ডার কোথায়?', 'ডেলিভারি চার্জ কত?', 'একটি পণ্য খুঁজছি', 'এজেন্টের সাথে কথা বলব'],
        'en' => ['Where is my order?', 'What is the delivery charge?', 'I am looking for a product', 'Talk to an agent'],
        default => ['Amar order kothay?', 'Delivery charge koto?', 'Ekta ponno khujchi', 'Agent er sathe kotha bolbo'],
    };
}

function sh_support_api_reply(array $conv, array $cfg, int $afterId, array $extra = []): never
{
    $msgs = array_map('sh_support_message_public', sh_support_messages((int)$conv['id'], $afterId, false, 100));
    sh_support_mark_read((int)$conv['id'], 'customer');
    sh_json(array_merge([
        'ok' => true,
        'conversation' => sh_support_conversation_public($conv, $cfg),
        'messages' => $msgs,
        'suggestions' => sh_support_api_suggestions(sh_support_pick_lang((string)($conv['lang'] ?? 'en'), $cfg)),
    ], $extra));
}

function sh_support_api_own_conversation(array $identity): ?array
{
    $convId = (int)sh_post('conversation_id', 0);
    $conv = sh_support_conversation($convId);
    if ($conv === null || !sh_support_owns($conv, $identity)) { return null; }
    return $conv;
}

try {
    switch ($action) {
        case 'start': {
            $conv = sh_support_active_conversation($identity);
            if ($conv === null) {
                $conv = sh_support_create_conversation($identity);
                if ($conv !== []) { sh_support_api_welcome($conv, $cfg); }
            }
            if ($conv === []) { http_response_code(500); sh_json(['ok' => false, 'error' => 'Could not open the conversation.']); }
            sh_support_api_reply($conv, $cfg, 0, ['max_input' => (int)$cfg['max_input']]);
        }
        case 'new': {
            $conv = sh_support_create_conversation($identity);
            if ($conv === []) { http_response_code(500); sh_json(['ok' => false, 'error' => 'Could not open the conversation.']); }
            sh_support_api_welcome($conv, $cfg);
            sh_support_api_reply($conv, $cfg, 0);
        }
        case 'send': {
            $conv = sh_support_api_own_conversation($identity);
            if ($conv === null) { http_response_code(403); sh_json(['ok' => false, 'error' => 'Conversation not found.']); }
            $afterId = max(0, (int)sh_post('after_id', 0));
            $res = sh_support_handle_customer_message(
                $identity, (int)$conv['id'],
                (string)sh_post('message', ''), (string)sh_post('client_msg_id', '')
            );
            if (empty($res['ok'])) {
                $code = match ((string)($res['error'] ?? '')) { 'rate_limited' => 429, 'too_long' => 422, 'empty' => 422, 'support_disabled' => 503, default => 400 };
                http_response_code($code);
                sh_json(['ok' => false, 'error' => (string)($res['error'] ?? 'failed')]);
            }
            $fresh = sh_support_conversation((int)$conv['id']) ?? $conv;
            sh_support_api_reply($fresh, $cfg, $afterId, ['duplicate' => !empty($res['duplicate'])]);
        }
        case 'poll': {
            $conv = sh_support_api_own_conversation($identity);
            if ($conv === null) { http_response_code(403); sh_json(['ok' => false, 'error' => 'Conversation not found.']); }
            sh_support_api_reply($conv, $cfg, max(0, (int)sh_post('after_id', 0)));
        }
        case 'handoff': {
            $conv = sh_support_api_own_conversation($identity);
            if ($conv === null) { http_response_code(403); sh_json(['ok' => false, 'error' => 'Conversation not found.']); }
            if (empty($conv['ai_paused'])) { sh_support_handoff((int)$conv['id'], null, 'widget_button'); }
            $fresh = sh_support_conversation((int)$conv['id']) ?? $conv;
            sh_support_api_reply($fresh, $cfg, max(0, (int)sh_post('after_id', 0)));
        }
        default:
            http_response_code(400);
            sh_json(['ok' => false, 'error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    sh_log_exception($e, 'support-api');
    http_response_code(500);
    sh_json(['ok' => false, 'error' => 'Something went wrong. Your message was not lost if it reached us — please retry.']);
}
