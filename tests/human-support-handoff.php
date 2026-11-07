<?php
/**
 * Human-support state-machine integration tests.
 *
 * Executes the REAL functions from includes/ai-support.php (the same ones
 * api/support.php calls) against a real PDO database. See
 * tests/_support_db_bootstrap.php for the MySQL / SQLite drivers.
 *
 * Covers the mandatory acceptance flow: one handoff confirmation, silent
 * saving of follow-ups, no AI/FAQ/fallback reply, agent reply visible to the
 * customer, retries/refresh idempotency, explicit Resume AI, and a second
 * genuine AI -> human transition.
 *
 *     php tests/human-support-handoff.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_support_db_bootstrap.php';
require_once SH_ROOT . '/includes/ai-support.php';

sh_test_schema();
sh_setting_save('support_chat_enabled', '1');
sh_setting_save('support_chat_handoff_enabled', '1');
sh_setting_save('support_chat_ai_enabled', '0');
sh_setting_save('support_chat_hours', '');
sh_setting_save('support_chat_contact_email', '');
sh_setting_save('support_chat_contact_phone', '');

$adminId = sh_insert('admins', ['name' => 'Test Agent', 'email' => 'agent@example.test', 'role' => 'superadmin', 'status' => 'active']);
$owner = ['user_id' => 0, 'guest_hash' => hash('sha256', 'human-support-test-guest'), 'guest_order_ids' => []];

$now = static fn(): string => gmdate('Y-m-d H:i:s');
$countMsg = static fn(string $sender = ''): int => (int)sh_val(
    'SELECT COUNT(*) FROM support_messages WHERE is_internal = 0' . ($sender !== '' ? ' AND sender = ?' : ''),
    $sender !== '' ? [$sender] : [], 0
);
$countEvent = static fn(string $event): int => (int)sh_val(
    'SELECT COUNT(*) FROM support_handoff_events WHERE event = ?', [$event], 0
);
/** Only the one-time handoff confirmation, never other system fallback notices. */
$countConfirm = static fn(int $cid): int => (int)sh_val(
    "SELECT COUNT(*) FROM support_messages WHERE conversation_id = ? AND sender = 'system' AND body LIKE '%support inbox%'",
    [$cid], 0
);
$conv = static fn(int $id): array => (array)sh_one('SELECT * FROM support_conversations WHERE id = ?', [$id]);
/** Tests exercise the state machine, not throttling: clear buckets between actions. */
$resetRate = static function (): void { sh_query('DELETE FROM support_rate_limits'); };
$send = static function (int $id, string $body, string $clientId = '') use ($owner, $resetRate): array {
    $resetRate();
    return sh_support_customer_send($id, $owner, $body, $clientId !== '' ? $clientId : sh_test_uuid());
};
$handoff = static function (int $id, string $clientId = '') use ($owner, $resetRate): array {
    $resetRate();
    return sh_support_customer_handoff($id, $owner, $clientId !== '' ? $clientId : sh_test_uuid());
};
$adminReply = static function (int $id, string $body) use ($adminId, $resetRate): array {
    $resetRate();
    return sh_support_admin_reply($id, $adminId, $body, sh_test_uuid(), false);
};
$adminTransition = static function (int $id, string $action, int $targetAdminId = 0) use ($adminId, $resetRate): array {
    $resetRate();
    return sh_support_admin_transition($id, $adminId, $action, $targetAdminId);
};
$newConversation = static function (string $status = 'open', int $paused = 0) use ($owner, $now, $resetRate): int {
    $resetRate();
    return sh_insert('support_conversations', [
        'user_id' => null, 'guest_token_hash' => $owner['guest_hash'],
        'status' => $status, 'assigned_admin_id' => null, 'ai_paused' => $paused,
        'unread_for_admin' => 0, 'unread_for_customer' => 0, 'lang' => 'en', 'subject' => null,
        'last_message_at' => null, 'created_at' => $now(), 'updated_at' => $now(),
    ]);
};

[$passed, $failed] = sh_test_run('Human support state machine — real PHP + SQL', static function ($ok, $eq) use (
    $owner, $adminId, $countMsg, $countEvent, $countConfirm, $conv, $send, $handoff, $adminReply, $adminTransition,
    $newConversation, $resetRate, $now
) {
    echo "Engine: " . (sh_db()->isMySql ? 'MySQL/MariaDB (production DDL)' : 'SQLite (MySQL SQL translated)') . "\n\n";

    /* --- State derivation ------------------------------------------------- */
    $eq('ai_active', sh_support_human_state(['status' => 'open', 'ai_paused' => 0, 'assigned_admin_id' => 0]), 'open + AI on => ai_active');
    $eq('human_requested', sh_support_human_state(['status' => 'pending', 'ai_paused' => 1, 'assigned_admin_id' => 0]), 'pending => human_requested');
    $eq('human_active', sh_support_human_state(['status' => 'assigned', 'ai_paused' => 1, 'assigned_admin_id' => 4]), 'assigned agent => human_active');
    $eq('resolved', sh_support_human_state(['status' => 'resolved', 'ai_paused' => 1, 'assigned_admin_id' => 0]), 'resolved => resolved');
    $ok(sh_support_human_state(['status' => 'open', 'ai_paused' => 1, 'assigned_admin_id' => 0]) === 'human_requested', 'reopened thread with paused AI stays human_requested');

    /* --- 1. AI-active message does not create a system notice ------------- */
    $id = $newConversation();
    $before = $countMsg('system');
    $r = $send($id, 'Hello there, I need some help');
    $ok(!empty($r['ok']) && !empty($r['message_saved']), 'customer message before handoff is saved');
    $eq(0, $countMsg('system'), 'no automatic notice before handoff');
    $eq('ai_active', sh_support_human_state($conv($id)), 'conversation remains AI-active before handoff');
    $aiCallsBefore = $GLOBALS['sh_test_ai_calls'];

    /* --- 2. Human support requested => exactly ONE confirmation ----------- */
    $r = $handoff($id);
    $ok(!empty($r['ok']), 'handoff action succeeds');
    $eq(1, $countMsg('system'), 'exactly one handoff confirmation is written');
    $eq(1, $countEvent('requested'), 'exactly one requested handoff event');
    $eq(1, $countEvent('handoff_confirmed'), 'one durable confirmation marker event recorded');
    $eq('human_requested', sh_support_human_state($conv($id)), 'state transitions to human_requested');
    $eq(1, (int)$conv($id)['ai_paused'], 'AI is paused after handoff');
    // The durable marker itself is idempotent: even a direct second call that
    // claims the state was AI-active must not write a second confirmation.
    sh_db()->beginTransaction();
    sh_one('SELECT * FROM support_conversations WHERE id = ? FOR UPDATE', [$id]);
    $markerRepeat = sh_support_confirm_handoff_once($id,
        ['status' => 'open', 'ai_paused' => 0, 'assigned_admin_id' => 0],
        'en', 'system', null, 'duplicate writer');
    sh_db()->commit();
    $eq(0, $markerRepeat, 'a second confirmation writer is a no-op');
    $eq(1, $countMsg('system'), 'the direct duplicate writer adds no message');
    $eq(1, $countEvent('handoff_confirmed'), 'the direct duplicate writer adds no marker');

    /* --- 3/4/5. Follow-ups are silent, saved, and AI never runs ---------- */
    $historyBefore = $countMsg();
    $r = $send($id, 'Please check my delivery address');
    $ok(!empty($r['ok']) && !empty($r['message_saved']), 'second customer message is saved');
    $eq($historyBefore + 1, $countMsg(), 'exactly one row added (no automatic reply)');
    $eq(1, $countMsg('system'), 'no repeated "saved for support" notice');
    $eq(0, $countMsg('assistant') + $countMsg('ai'), 'no AI/assistant reply while human owns the thread');
    $eq($aiCallsBefore, $GLOBALS['sh_test_ai_calls'], 'AI provider stack is not invoked after handoff');
    $eq(1, (int)$conv($id)['unread_for_admin'], 'follow-up appears as unread in the support inbox');
    $inbox = sh_support_admin_conversations('', '', 1, 25);
    $ok((int)($inbox['total'] ?? 0) >= 1, 'conversation is listed in the admin support inbox');

    /* --- 6. Refresh / retry / duplicate click idempotency ---------------- */
    $dupId = sh_test_uuid();
    $first = $send($id, 'Another detail', $dupId);
    $second = $send($id, 'Another detail', $dupId);
    $ok(!empty($first['ok']) && !empty($second['duplicate']), 'retry with the same client id is a duplicate, not a new message');
    $eq(1, (int)sh_val('SELECT COUNT(*) FROM support_messages WHERE client_message_id = ?', [$dupId], 0), 'retry stores the customer message exactly once');
    $eventsBefore = $countEvent('requested');
    $r1 = $handoff($id);
    $r2 = $handoff($id);
    $ok(!empty($r1['ok']) && !empty($r2['ok']), 'repeated handoff clicks return success');
    $eq($eventsBefore, $countEvent('requested'), 'repeated handoff clicks create no extra events');
    $eq(1, $countMsg('system'), 'repeated handoff clicks create no extra confirmations');

    /* --- 7. Handoff-intent text while human-owned stays silent ------------ */
    $beforeMsgs = $countMsg('system');
    $r = $send($id, 'Please connect me to a human agent again');
    $ok(!empty($r['ok']), 'handoff-intent text is accepted as an ordinary customer message');
    $eq($beforeMsgs, $countMsg('system'), 'no second confirmation from handoff-intent text');
    $eq($eventsBefore, $countEvent('requested'), 'no duplicate handoff event from handoff-intent text');

    /* --- 8. Retry endpoint never restarts AI ------------------------------ */
    $pending = sh_one("SELECT id FROM support_messages WHERE conversation_id = ? AND sender = 'customer' ORDER BY id DESC LIMIT 1", [$id]);
    $resetRate();
    $retry = sh_support_customer_retry($id, (int)$pending['id'], $owner);
    $ok(empty($retry['ok']) && (int)($retry['http_status'] ?? 0) === 409, 'retry of an already-saved human-owned message is a safe no-op');
    $eq($aiCallsBefore, $GLOBALS['sh_test_ai_calls'], 'no-op retry does not invoke the AI provider');
    // Simulate an AI turn that was still in flight when the agent took over.
    sh_query("UPDATE support_messages SET reply_status = 'pending' WHERE id = ?", [(int)$pending['id']]);
    $resetRate();
    $retry = sh_support_customer_retry($id, (int)$pending['id'], $owner);
    $ok(!empty($retry['ok']) && !empty($retry['human_controlled']), 'retry of an interrupted AI turn returns human_controlled');
    $eq('none', (string)sh_val('SELECT reply_status FROM support_messages WHERE id = ?', [(int)$pending['id']], ''), 'interrupted turn becomes a saved inbox message, not an AI reply');
    $eq($aiCallsBefore, $GLOBALS['sh_test_ai_calls'], 'retry never invokes the AI provider');
    $eq(1, $countMsg('system'), 'retry does not add a system notice');

    /* --- 9. Direct early-return gate in the processing service ----------- */
    $beforeMsgs = $countMsg();
    sh_query("UPDATE support_messages SET reply_status = 'pending' WHERE id = ?", [(int)$pending['id']]);
    $gate = sh_support_process_customer_message($id, (int)$pending['id'], 'still waiting', $owner);
    $ok(empty($gate['saved']) && !empty($gate['paused']), 'process_customer_message refuses to reply while human owns the thread');
    $eq($beforeMsgs, $countMsg(), 'processing gate writes no message');
    $eq($aiCallsBefore, $GLOBALS['sh_test_ai_calls'], 'processing gate never reaches the AI/FAQ/fallback pipeline');

    /* --- 10. Agent reply appears in the customer's transcript ------------ */
    $reply = $adminReply($id, 'Hello, this is Rina from support. We are checking your order now.');
    $ok(!empty($reply['ok']), 'administrator reply is saved');
    $eq('human_active', sh_support_human_state($conv($id)), 'agent reply moves state to human_active');
    $eq(1, $countMsg('system'), 'agent takeover does NOT add a second confirmation');
    $snapshot = sh_support_customer_snapshot($id, $owner);
    $agentVisible = false;
    foreach ($snapshot['messages'] ?? [] as $m) {
        if (($m['sender'] ?? '') === 'admin' && str_contains((string)($m['body'] ?? ''), 'Rina')) { $agentVisible = true; }
    }
    $ok($agentVisible, 'customer sees the agent reply in the existing conversation');

    /* --- 11. Many more customer messages, still no notices ---------------- */
    foreach (['One more thing', 'My phone number is 01712345678', 'Thank you'] as $text) {
        $ok(!empty($send($id, $text)['ok']), 'additional customer message saved: ' . $text);
    }
    $eq(1, $countMsg('system'), 'no duplicate system notification after several follow-ups');
    $eq(0, $countMsg('assistant') + $countMsg('ai'), 'no AI reply at any point during human ownership');
    $eq($aiCallsBefore, $GLOBALS['sh_test_ai_calls'], 'AI provider never called while human support owns the thread');
    $customerRows = (int)sh_val("SELECT COUNT(*) FROM support_messages WHERE conversation_id = ? AND sender = 'customer'", [$id], 0);
    $ok($customerRows >= 7, 'entire customer conversation history is preserved (' . $customerRows . ' customer rows)');

    /* --- 12. Only an explicit authorized Resume AI re-enables the AI ------ */
    $resume = $adminTransition($id, 'resume_ai');
    $ok(!empty($resume['ok']), 'authorized admin Resume AI succeeds');
    $c = $conv($id);
    $eq('ai_active', sh_support_human_state($c), 'state returns to ai_active only after Resume AI');
    $eq(0, (int)$c['ai_paused'], 'AI pause flag cleared');
    $eq(0, (int)($c['assigned_admin_id'] ?? 0), 'assignment cleared');

    $aiCallsAtResume = $GLOBALS['sh_test_ai_calls'];
    $r = $send($id, 'What is the delivery time?');
    $ok(!empty($r['ok']), 'customer message after Resume AI is saved');
    $ok($GLOBALS['sh_test_ai_calls'] > $aiCallsAtResume, 'AI availability is evaluated again for the resumed conversation');
    $eq(1, $countMsg('system'), 'Resume AI does not add a system notice');
    $queued = sh_one('SELECT reply_status FROM support_messages WHERE conversation_id = ? AND sender = \'customer\' ORDER BY id DESC LIMIT 1', [$id]);
    $ok(in_array((string)$queued['reply_status'], ['failed', 'pending', 'complete'], true), 'post-resume message is queued/processed through the normal AI pipeline');

    /* --- 13. A genuinely NEW AI -> human transition gets one new notice -- */
    $r = $handoff($id);
    $ok(!empty($r['ok']), 'second, genuine handoff after Resume AI succeeds');
    $eq(2, $countMsg('system'), 'exactly one new confirmation for the new ownership cycle');
    $eq(2, $countEvent('handoff_confirmed'), 'one confirmation marker per ownership cycle');
    $ok(!empty($send($id, 'Following up again')['ok']), 'follow-up after second handoff saved');
    $eq(2, $countMsg('system'), 'no notice for follow-ups in the second cycle');

    /* --- 14. Agent takeover from AI-active also confirms exactly once ---- */
    $id2 = $newConversation();
    $ok(!empty($send($id2, 'Hi, I have a question')['ok']), 'second conversation starts AI-active');
    $eq(0, (int)sh_val('SELECT COUNT(*) FROM support_messages WHERE conversation_id = ? AND sender = \'system\'', [$id2], 0), 'no notice before takeover');
    $ok(!empty($adminReply($id2, 'I will help you directly.')['ok']), 'agent takes over by replying');
    $eq(1, (int)sh_val('SELECT COUNT(*) FROM support_messages WHERE conversation_id = ? AND sender = \'system\'', [$id2], 0), 'takeover generates exactly one confirmation');
    $ok(!empty($send($id2, 'Thanks, here are the details')['ok']), 'customer follow-up after takeover saved');
    $eq(1, (int)sh_val('SELECT COUNT(*) FROM support_messages WHERE conversation_id = ? AND sender = \'system\'', [$id2], 0), 'no repeated confirmation after takeover follow-up');
    $eq('human_active', sh_support_human_state($conv($id2)), 'takeover state is human_active');

    /* --- 15. Legacy handoff is backfilled without a duplicate notice ---- */
    $id3 = $newConversation('pending', 1);
    sh_insert('support_messages', [
        'conversation_id' => $id3, 'sender' => 'system', 'source' => 'system',
        'body' => 'Legacy handoff notice', 'reply_status' => 'none', 'is_internal' => 0,
        'created_at' => $now(),
    ]);
    $legacy = $conv($id3);
    $marker = sh_support_confirm_handoff_once($id3, $legacy, 'en', 'system', null, 'legacy backfill');
    $eq(0, $marker, 'legacy human-owned conversation gets a marker but no new message');
    $eq(1, (int)sh_val('SELECT COUNT(*) FROM support_messages WHERE conversation_id = ? AND sender = \'system\'', [$id3], 0), 'legacy history is preserved without a duplicate notice');

    /* --- 16. Customer support page: no secondary "other ways" section ----- */
    $supportPage = (string)file_get_contents(SH_ROOT . '/support.php');
    foreach (['sh-support-contact', 'Contact Support</h2>', 'sp-name', 'sp-email', 'sp-subject', 'sp-msg',
              'Send message</button>', 'sh_mail_send(', 'sh_notify_log(', 'sh_csrf_field()'] as $removed) {
        $ok(!str_contains($supportPage, $removed), 'customer support page no longer renders ' . $removed);
    }
    foreach (['data-support-chat', 'data-chat-messages', 'data-chat-form', 'data-handoff', 'api/support.php'] as $kept) {
        $ok(str_contains($supportPage, $kept), 'customer support chat still renders ' . $kept);
    }
    $adminInbox = (string)file_get_contents(SH_ROOT . '/admin/support.php');
    $ok(str_contains($adminInbox, 'sh_require_admin()') && str_contains($adminInbox, "sh_admin_can('support.view'"),
        'admin support inbox is untouched and still permission-gated');
    $supportCss = (string)file_get_contents(SH_ROOT . '/assets/css/support.css');
    $ok(preg_match('~\.sh-support-chat\s*\{[^}]*margin:\s*0\s*;~s', $supportCss) === 1,
        'no trailing empty layout space remains below the conversation');
    $chatJs = (string)file_get_contents(SH_ROOT . '/assets/js/support.js');
    foreach (['saved for support', 'save kora', 'message has been saved'] as $notice) {
        $ok(!str_contains(strtolower($chatJs), strtolower($notice)), 'no saved-message notice in chat JS: ' . $notice);
    }

    /* --- 17. assign / resolve / reopen / resume_ai confirmation audit ----- */
    $id4 = $newConversation();
    $ok(!empty($send($id4, 'Hello, I have a general question')['ok']), 'AI-active thread created for admin transitions');
    $eq(0, $countConfirm($id4), 'no notice before direct assignment');
    $r = $adminTransition($id4, 'assign', $adminId);
    $ok(!empty($r['ok']), 'direct admin assignment succeeds');
    $eq('human_active', sh_support_human_state($conv($id4)), 'assignment transfers the thread to human support');
    $eq(1, $countConfirm($id4), 'assignment sends exactly one confirmation');
    $eq(1, (int)sh_val('SELECT COUNT(*) FROM support_handoff_events WHERE conversation_id = ? AND event = \'handoff_confirmed\'', [$id4], 0), 'assignment records one confirmation marker');
    $adminTransition($id4, 'assign', $adminId);
    $eq(1, $countConfirm($id4), 'repeated assignment adds no confirmation');
    $ok(!empty($send($id4, 'Any update?')['ok']), 'follow-up after assignment is saved');
    $eq(1, $countConfirm($id4), 'follow-up after assignment adds no notice');
    $r = $adminTransition($id4, 'resolve');
    $ok(!empty($r['ok']), 'authorized admin can resolve the conversation');
    $eq('resolved', sh_support_human_state($conv($id4)), 'resolve state is reported');
    $eq(1, $countConfirm($id4), 'resolve adds no notice');
    $r = $adminTransition($id4, 'resolve');
    $ok(empty($r['ok']) && (int)($r['http_status'] ?? 0) === 409, 'resolving an already-resolved thread is a safe no-op conflict');
    $r = $adminTransition($id4, 'reopen');
    $ok(!empty($r['ok']), 'authorized admin can reopen the conversation');
    $eq('human_requested', sh_support_human_state($conv($id4)), 'reopen keeps AI paused until an explicit Resume AI');
    $eq(1, $countConfirm($id4), 'reopen adds no notice');
    $r = $adminTransition($id4, 'resume_ai');
    $ok(!empty($r['ok']), 'explicit Resume AI succeeds after reopen');
    $eq('ai_active', sh_support_human_state($conv($id4)), 'Resume AI restores AI ownership');
    $eq(1, $countConfirm($id4), 'Resume AI adds no notice');
    $r = $adminTransition($id4, 'resume_ai');
    $ok(!empty($r['ok']) && !empty($r['unchanged']), 'retrying Resume AI is an idempotent no-op');
    $ok(!empty($handoff($id4)['ok']), 'a later genuine handoff after Resume AI succeeds');
    $eq(2, $countConfirm($id4), 'new ownership cycle sends exactly one new confirmation');
    $adminTransition($id4, 'unassign');
    $eq(2, $countConfirm($id4), 'unassign does not repeat the confirmation');

    /* --- Join / ownership / inbox fields / close / reopen ---------------- */
    $agent2 = sh_insert('admins', [
        'name' => 'Second Agent', 'email' => 'agent2@example.test',
        'role' => 'support_agent', 'status' => 'active',
    ]);
    $id5 = $newConversation();
    $ok(!empty($send($id5, 'I would like to speak with a person please')['ok']), 'customer opens one conversation for the join flow');
    $conversationCountBefore = (int)sh_val('SELECT COUNT(*) FROM support_conversations', [], 0);
    $ok(!empty($handoff($id5)['ok']), 'customer requests human support');
    $ok(!empty($handoff($id5)['ok']), 'refresh/retry of the same request is a no-op');
    $eq($conversationCountBefore, (int)sh_val('SELECT COUNT(*) FROM support_conversations', [], 0), 'no new conversation per handoff or retry');
    $eq('pending', (string)$conv($id5)['status'], 'handoff stages the conversation as pending');

    $row = sh_support_admin_conversation($id5);
    $ok($row !== null, 'admin thread lookup returns the same conversation');
    $eq('Guest customer', (string)$row['customer_label'], 'guest customer is labelled explicitly');
    $eq('Pending human support', (string)$row['human_status_label'], 'inbox shows Pending human support before join');
    $eq('Paused', (string)$row['ai_status'], 'inbox shows AI Paused after a human request');
    $eq('Unassigned', (string)$row['assignment_status'], 'inbox shows Unassigned before join');
    $ok((int)$row['unread_count'] >= 1, 'inbox shows the unread count');
    $ok((string)$row['request_time'] !== '', 'inbox shows the real handoff request time');
    $ok((string)$row['message_preview'] !== '', 'inbox shows a latest-message preview');

    $list = sh_support_admin_conversations('pending', '', 1, 25);
    $listRow = null;
    foreach ($list['rows'] as $candidate) { if ((int)$candidate['id'] === $id5) { $listRow = $candidate; } }
    $ok($listRow !== null, 'pending human-support conversation appears in the admin inbox list');
    $eq('Pending human support', (string)($listRow['human_status_label'] ?? ''), 'list row shows human-support status');
    $eq('Paused', (string)($listRow['ai_status'] ?? ''), 'list row shows AI status');
    $eq('Unassigned', (string)($listRow['assignment_status'] ?? ''), 'list row shows assignment status');
    $ok((string)($listRow['request_time'] ?? '') !== '', 'list row shows request time');
    $ok((string)($listRow['last_message_time'] ?? '') !== '', 'list row shows last message time');
    $ok((int)($listRow['unread_count'] ?? 0) >= 1, 'list row shows unread count');

    $r = $adminTransition($id5, 'join');
    $ok(!empty($r['ok']), 'Join Conversation claims the conversation');
    $c = $conv($id5);
    $eq($adminId, (int)$c['assigned_admin_id'], 'joining assigns the acting agent');
    $eq('assigned', (string)$c['status'], 'joining moves the DB status to assigned');
    $eq(1, (int)$c['ai_paused'], 'AI stays paused after join');
    $row = sh_support_admin_conversation($id5);
    $eq('In progress', (string)$row['human_status_label'], 'joining changes Pending human support to In progress');
    $eq('Test Agent', (string)$row['assigned_agent_name'], 'agent name is shown after join');
    $ok(str_contains((string)$row['assignment_label'], 'Test Agent'), 'assignment label names the agent');
    $eq(1, $countConfirm($id5), 'joining never repeats the one handoff confirmation');

    $r2 = sh_support_admin_transition($id5, $agent2, 'join', 0);
    $ok(empty($r2['ok']) && (int)($r2['http_status'] ?? 0) === 409,
        'a second agent cannot take simultaneous ownership');
    $eq($adminId, (int)$conv($id5)['assigned_admin_id'], 'failed concurrent join leaves ownership unchanged');
    $r2 = sh_support_admin_transition($id5, $agent2, 'assign', $agent2);
    $ok(!empty($r2['ok']), 'a superadmin may deliberately reassign (explicit, not accidental)');
    $r2 = $adminTransition($id5, 'join');
    $ok(empty($r2['ok']), 'joining a conversation already owned by a colleague is refused');

    // Subsequent customer messages go to the same inbox, never to AI/FAQ.
    $beforeMessages = (int)sh_val('SELECT COUNT(*) FROM support_messages WHERE conversation_id = ?', [$id5], 0);
    $aiCallsBefore = $GLOBALS['sh_test_ai_calls'];
    $ok(!empty($send($id5, 'Here is my follow-up question')['ok']), 'follow-up customer message is saved');
    $eq($beforeMessages + 1, (int)sh_val('SELECT COUNT(*) FROM support_messages WHERE conversation_id = ?', [$id5], 0),
        'follow-up appends exactly one message to the existing history');
    $eq($aiCallsBefore, $GLOBALS['sh_test_ai_calls'], 'no AI/FAQ/fallback inference after a human request');
    $eq(1, $countConfirm($id5), 'no repeated handoff confirmation on follow-up');
    $eq(1, (int)$conv($id5)['unread_for_admin'], 'follow-up is delivered to the assigned human inbox');
    $eq($conversationCountBefore, (int)sh_val('SELECT COUNT(*) FROM support_conversations', [], 0),
        'no new conversation is created for a follow-up message');

    $ok(!empty($adminReply($id5, 'Hello, this is your support agent.')['ok']), 'agent replies as a human');
    $snapshot = sh_support_customer_snapshot($id5, $owner);
    $agentMessage = null;
    foreach ($snapshot['messages'] as $m) { if ((string)($m['sender'] ?? '') === 'admin') { $agentMessage = $m; } }
    $ok($agentMessage !== null, 'agent reply appears in the SAME customer chat');
    $ok((string)($agentMessage['created_at'] ?? '') !== '', 'agent reply carries a timestamp');
    $adminRows = sh_support_admin_messages($id5, 0, 30)['messages'];
    $adminRow = null;
    foreach ($adminRows as $m) { if ((string)($m['sender'] ?? '') === 'admin') { $adminRow = $m; } }
    $ok($adminRow !== null, 'agent reply is in the same admin conversation history');
    $eq('Test Agent', (string)($adminRow['admin_name'] ?? ''), 'agent reply carries the correct sender label');
    $eq($adminId, (int)($adminRow['sender_admin_id'] ?? 0), 'agent reply records the acting support agent');
    $ok((string)($adminRow['created_at'] ?? '') !== '', 'agent reply carries a timestamp in admin history');
    $eq(1, $countConfirm($id5), 'agent reply never repeats the handoff confirmation');

    $r = $adminTransition($id5, 'leave');
    $ok(!empty($r['ok']), 'Leave Conversation releases ownership');
    $c = $conv($id5);
    $eq(0, (int)$c['assigned_admin_id'], 'leaving clears the assignee');
    $eq('pending', (string)$c['status'], 'leaving returns the conversation to the queue');
    $eq(1, (int)$c['ai_paused'], 'AI remains paused after leaving');
    $eq('Pending human support', (string)sh_support_admin_conversation($id5)['human_status_label'],
        'inbox shows Pending human support again after leaving');

    $r = $adminTransition($id5, 'close');
    $ok(!empty($r['ok']), 'Close Conversation closes the support request');
    $eq('closed', (string)$conv($id5)['status'], 'close sets the closed state');
    $eq('resolved', sh_support_human_state($conv($id5)), 'closed conversation is reported as resolved');
    $r = $adminTransition($id5, 'reopen');
    $ok(!empty($r['ok']), 'Reopen Conversation restores the thread');
    $eq(1, (int)$conv($id5)['ai_paused'], 'AI is still paused after reopen');
    $eq('human_requested', sh_support_human_state($conv($id5)), 'reopen never silently resumes AI');
    $r = $adminTransition($id5, 'resume_ai');
    $ok(!empty($r['ok']), 'only an explicit authorized Resume AI resumes AI');
    $eq('ai_active', sh_support_human_state($conv($id5)), 'AI ownership returns after explicit Resume AI');
    $eq(1, $countConfirm($id5), 'close/reopen/Resume AI never repeat the handoff confirmation');

    // Server-side allowlist for every browser-driven transition.
    $apiSrc = (string)file_get_contents(SH_ROOT . '/api/support.php');
    foreach (['join', 'assign_to_me', 'leave', 'close', 'reopen', 'resume_ai'] as $t) {
        $ok(str_contains($apiSrc, "'" . $t . "'"), 'API allowlist includes ' . $t);
    }
    $ok(str_contains($apiSrc, '$allowedTransitions'), 'browser cannot invent an ownership/AI transition');
});

exit($failed > 0 ? 1 : 0);
