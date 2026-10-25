import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const require_ = createRequire(import.meta.url);
const { JSDOM } = require_(process.env.SH_JSDOM_PATH || 'jsdom');
const sleep = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));
const supportPage = readFileSync(join(ROOT, 'support.php'), 'utf8');
const supportJs = readFileSync(join(ROOT, 'assets/js/support.js'), 'utf8');
const supportCss = readFileSync(join(ROOT, 'assets/css/support.css'), 'utf8');
const supportBackend = readFileSync(join(ROOT, 'includes/ai-support.php'), 'utf8');
const supportApi = readFileSync(join(ROOT, 'api/support.php'), 'utf8');
const supportSchema = readFileSync(join(ROOT, 'ai/support-schema.php'), 'utf8');

const html = `<!doctype html><html><body>
<div data-support-chat data-api="/api/support.php" data-csrf="test-token">
  <div data-chat-error hidden></div>
  <div data-chat-empty hidden><p data-chat-unavailable hidden></p><button data-chat-open></button></div>
  <div data-chat-history hidden><span data-thread-title></span><span data-thread-state></span>
    <button data-chat-new hidden></button><button data-chat-delete hidden></button>
    <ol data-chat-messages></ol><button data-load-older hidden></button>
    <div data-chat-actions><button data-handoff hidden></button></div>
    <form data-chat-form><textarea data-chat-input></textarea><span data-char-count></span><button data-chat-send><svg></svg> Send</button></form>
    <div data-chat-disabled hidden></div>
  </div>
</div></body></html>`;

let resolveFirstSend;
const sendRequests = [];
const retryRequests = [];
const stateUrls = [];
let humanMode = false;
let humanStatus = { status: 'pending', assigned_admin_id: 0, ai_paused: true };
let failNextHumanResponseAfterCommit = false;
let omitNextHumanRowFromSnapshot = false;
const humanRows = [];
let humanMessageId = 700;
const emptySnapshot = {
  ok: true,
  success: true,
  conversation: { id: 7, status: 'open', assigned_admin_id: 0, ai_paused: false },
  messages: [],
  has_more: false,
  older_cursor: 0,
  support: { enabled: true, handoff_enabled: true },
};

function makeSnapshot(clientId, customerId, customerBody, replyId, replyBody, source = 'ai') {
  return {
    ...emptySnapshot,
    messages: [
      { id: customerId, sender: 'customer', source: 'manual', client_message_id: clientId, body: customerBody, created_at: '2026-10-24 12:00:00', reply_status: 'complete', products: [] },
      { id: replyId, sender: 'assistant', source, client_message_id: '', body: replyBody, created_at: '2026-10-24 12:00:01', reply_status: 'none', products: [] },
    ],
  };
}

function makeFailedSnapshot(clientId, customerId, customerBody) {
  return {
    ...emptySnapshot,
    messages: [
      { id: customerId, sender: 'customer', source: 'manual', client_message_id: clientId, body: customerBody, created_at: '2026-10-24 12:01:00', reply_status: 'failed', products: [] },
    ],
  };
}

function makeHumanSnapshot() {
  return {
    ...emptySnapshot,
    conversation: { ...emptySnapshot.conversation, ...humanStatus },
    messages: [...humanRows],
  };
}

function saveHumanSend(body) {
  const existing = humanRows.find((message) => message.sender === 'customer'
    && message.client_message_id === body.client_id);
  if (existing) {
    if (existing.body !== body.body) return { success: false, error: 'send key body mismatch' };
    return { success: true, ok: true, duplicate: true, message_saved: true, message: existing, snapshot: makeHumanSnapshot() };
  }
  const customer = {
    id: ++humanMessageId, sender: 'customer', source: 'manual', client_message_id: body.client_id,
    body: body.body, created_at: '2026-10-25 12:01:00', reply_status: 'none', products: [],
  };
  humanRows.push(customer);
  humanRows.push({
    id: ++humanMessageId, sender: 'system', source: 'system', client_message_id: '',
    body: 'Your message has been saved for support.', created_at: '2026-10-25 12:01:01',
    reply_status: 'none', products: [],
  });
  const snapshot = makeHumanSnapshot();
  if (omitNextHumanRowFromSnapshot) {
    snapshot.messages = snapshot.messages.filter((message) => message.id !== customer.id);
    omitNextHumanRowFromSnapshot = false;
  }
  return { success: true, ok: true, message_saved: true, message: customer, snapshot };
}

function addAgentReply(body) {
  const message = {
    id: ++humanMessageId, sender: 'admin', source: 'manual', client_message_id: '',
    body, created_at: '2026-10-25 12:02:00', reply_status: 'none', products: [],
  };
  humanRows.push(message);
  return message;
}

function jsonResponse(payload, status = 200) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(payload) });
}

async function fetchMock(rawUrl, options = {}) {
  const url = new URL(rawUrl, 'http://localhost');
  const action = url.searchParams.get('action');
  if (action === 'state') { stateUrls.push(url.search); return jsonResponse(humanMode ? makeHumanSnapshot() : emptySnapshot); }
  if (action === 'retry') {
    const body = JSON.parse(options.body || '{}');
    retryRequests.push(body);
    const failed = sendRequests[2];
    return jsonResponse({ success: true, ok: true, snapshot: makeSnapshot(failed.client_id, 102, failed.body, 104, 'Your earlier message is answered now.') });
  }
  if (action !== 'send') throw new Error(`Unexpected support action: ${action}`);
  const body = JSON.parse(options.body || '{}');
  sendRequests.push(body);
  if (humanMode) {
    const response = saveHumanSend(body);
    if (failNextHumanResponseAfterCommit) {
      failNextHumanResponseAfterCommit = false;
      throw new Error('simulated lost response after database commit');
    }
    return jsonResponse(response);
  }
  if (sendRequests.length === 1) {
    return new Promise((resolve) => { resolveFirstSend = resolve; });
  }
  if (sendRequests.length === 2) throw new Error('simulated network loss after the optimistic send');
  if (sendRequests.length === 3) {
    // The mocked API represents an inference failure with the saved customer
    // row's failed state, not a made-up FAQ/assistant response.
    return jsonResponse({ success: true, ok: true, snapshot: makeFailedSnapshot(body.client_id, 102, body.body) });
  }
  return jsonResponse({ success: true, ok: true, snapshot: makeSnapshot(body.client_id, 105 + sendRequests.length, body.body, 205 + sendRequests.length, 'I can help with that.') });
}

const dom = new JSDOM(html, { url: 'http://localhost/support.php', runScripts: 'outside-only' });
dom.window.fetch = fetchMock;
dom.window.confirm = () => true;
dom.window.eval(supportJs);
const { document, Event } = dom.window;
const form = document.querySelector('[data-chat-form]');
const input = document.querySelector('[data-chat-input]');
const messages = document.querySelector('[data-chat-messages]');
const error = document.querySelector('[data-chat-error]');
let passed = 0;
const failures = [];
function check(name, ok, detail = '') {
  if (ok) { passed += 1; console.log(`  ok  ${name}`); }
  else { failures.push(`${name}${detail ? ` — ${detail}` : ''}`); console.log(`FAIL  ${name}${detail ? ` — ${detail}` : ''}`); }
}

try {
  check('Customer Support eyebrow keeps the Chat with ShopHaat Support main title', /<p class="sh-support-chat__eyebrow">Customer Support<\/p>\s*<h1 id="sh-support-chat-title">Chat with ShopHaat Support<\/h1>/.test(supportPage) && !supportPage.includes('Private Customer Support'));
  check('customer chat has no language selector or diagnostic banner', !supportPage.includes('data-language') && !supportPage.includes('data-chat-status') && !supportPage.includes('data-ai-note') && !supportJs.includes('data-language'));
  check('obsolete AI/FAQ explanations are absent from customer chat', !/AI generation is unavailable|AI reply generate kora jacche na|Saved FAQs, verified catalogue lookup/.test(supportPage + supportJs + supportBackend));
  check('automatic Bengali, Banglish and English detection is present', supportJs.includes('function detectLanguage') && supportJs.includes('0x0980') && supportJs.includes('banglishTerms'));
  check('eyebrow styles and mobile chat rules preserve the intended visual hierarchy and layout', supportCss.includes('.sh-support-chat__eyebrow') && supportCss.includes('@media (max-width: 680px)') && supportCss.includes('.sh-support-chat__message { max-width: 96%; }') && supportCss.includes('grid-template-columns: minmax(0, 1fr);'));
  check('FAQ matching, catalogue products and verified order/policy facts are model context only', supportBackend.includes('sh_support_build_ai_user_prompt($conversationId, $customerMessageId, $body, $products, $orderLookup, $faqs, $language)') && supportBackend.includes('products_from_current_public_catalogue') && supportBackend.includes('authorized_order_from_current_session_or_account') && supportBackend.includes('configured_policy') && !supportBackend.includes('function sh_support_faq_answer'));
  const providerTestStart = supportBackend.indexOf('function sh_support_provider_test(');
  const providerTestEnd = supportBackend.indexOf('function sh_support_sanitize_diagnostic(', providerTestStart);
  const providerTestCode = supportBackend.slice(providerTestStart, providerTestEnd);
  check('selected-model test makes a bounded real text-generation call and rejects empty output', providerTestCode.includes('$adapter->generateText(') && providerTestCode.includes("'model' => $model") && providerTestCode.includes('AIHttpClient::configureLimits(') && providerTestCode.includes("$rawText !== ''"));
  const processStart = supportBackend.indexOf('function sh_support_process_customer_message(');
  const processEnd = supportBackend.indexOf('function sh_support_handoff_public_message(', processStart);
  const processCode = supportBackend.slice(processStart, processEnd);
  const inferenceAt = processCode.indexOf('sh_support_ai_generate(');
  check('ordinary messages reach selected-model inference after order authorization', inferenceAt > 0 && processCode.indexOf('sh_support_find_authorized_order(') < inferenceAt && !/if\s*\(\s*!\s*\$faqs/.test(processCode));
  check('configuration and inference failures mark the saved message failed instead of saving an FAQ reply', processCode.includes('sh_support_mark_reply_failed($conversationId, $customerMessageId)') && !processCode.slice(inferenceAt).includes("'fallback'"));
  check('stale pending replies become retryable after the same bounded 180-second window', supportJs.includes('>= 180000') && supportBackend.includes('time() - 180'));
  check('only real AI replies are stored as assistant messages', supportBackend.includes("$sender = $source === 'ai' ? 'assistant' : 'system';") && supportJs.includes("message.source === 'fallback' ? 'system' : message.sender"));

  const sendStart = supportBackend.indexOf('function sh_support_customer_send(');
  const sendEnd = supportBackend.indexOf('function sh_support_customer_retry(', sendStart);
  const sendCode = supportBackend.slice(sendStart, sendEnd);
  const retryStart = sendEnd;
  const retryEnd = supportBackend.indexOf('function sh_support_customer_handoff(', retryStart);
  const retryCode = supportBackend.slice(retryStart, retryEnd);
  check('customer send is owner-scoped and blocks only resolved conversations, not human takeover', sendCode.includes('sh_support_conversation_for_owner($conversationId, $owner, true)') && sendCode.includes("status'] === 'resolved'") && sendCode.includes("$humanControlled = (int)$conversation['ai_paused'] === 1") && sendCode.includes("in_array((string)$conversation['status'], ['pending','assigned'], true)"));
  check('human-controlled sends keep assignment/status and refresh agent activity/unread state', sendCode.includes("SET subject = ?, last_message_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(), unread_for_admin = 1") && sendCode.includes("SET last_message_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(), unread_for_customer = 1, unread_for_admin = 1") && !sendCode.includes("SET status = 'assigned'"));
  check('human-controlled sends use no AI reply state and return before inference', sendCode.includes("$replyStatus = $humanControlled ? 'none' : 'pending'") && sendCode.indexOf('if ($humanControlled) {') < sendCode.indexOf('sh_support_process_customer_message('));
  check('pending/assigned status alone also blocks AI before inference and suppresses late replies', processCode.includes("in_array((string)$current['status'], ['pending','assigned','resolved'], true)") && supportBackend.includes("in_array((string)$conversation['status'], ['pending','assigned','resolved'], true)"));
  check('committed messages have an exact-row acknowledgement and idempotent retries are checked before resolved status', supportBackend.includes("'message_saved' => true") && sendCode.includes("(string)$duplicate['sender'] !== 'customer'") && sendCode.includes("hash_equals((string)$duplicate['body'], $body)") && sendCode.indexOf('if ($duplicate !== null)') < sendCode.indexOf("if ((string)$conversation['status'] === 'resolved')"));
  check('customer AI-reply retry during takeover queues the existing row for the agent without invoking AI', retryCode.includes("reply_status = 'none'") && retryCode.includes('unread_for_admin = 1') && retryCode.indexOf('if ($humanControlled) {') < retryCode.indexOf('sh_support_process_customer_message('));
  const ownerStart = supportBackend.indexOf('function sh_support_owner_clause(');
  const ownerEnd = supportBackend.indexOf('function sh_support_conversation_for_owner(', ownerStart);
  const ownerCode = supportBackend.slice(ownerStart, ownerEnd);
  const adminListStart = supportBackend.indexOf('function sh_support_admin_conversations(');
  const adminListEnd = supportBackend.indexOf('function sh_support_admin_conversation(', adminListStart);
  const adminListCode = supportBackend.slice(adminListStart, adminListEnd);
  const customerMessagesStart = supportBackend.indexOf('function sh_support_customer_messages(');
  const customerMessagesEnd = supportBackend.indexOf('function sh_support_public_conversation(', customerMessagesStart);
  const customerMessagesCode = supportBackend.slice(customerMessagesStart, customerMessagesEnd);
  check('customer API still enforces CSRF, request limits, length/client-ID validation and owner-scoped send', supportApi.includes("if ($method === 'POST') { sh_csrf_require(); }") && sendCode.includes('sh_support_request_rate_allowed($owner)') && sendCode.includes('sh_support_valid_client_id($clientId)') && sendCode.includes('sh_support_clean_text($body, SH_SUPPORT_MESSAGE_MAX)') && sendCode.includes('sh_support_conversation_for_owner($conversationId, $owner, true)'));
  check('cross-customer isolation still scopes logged-in users and guest tokens independently', ownerCode.includes('user_id = ?') && ownerCode.includes('guest_token_hash = ?') && ownerCode.includes('1 = 0') && supportApi.includes('sh_support_owner_context('));
  check('customer snapshots exclude internal notes while the agent thread remains separate', customerMessagesCode.includes('is_internal = 0') && customerMessagesCode.includes('sender <>') && customerMessagesCode.includes('internal_note') && supportBackend.includes("$sender = $internal ? 'internal_note' : 'admin';"));
  check('assigned follow-ups remain visible and bubble the assigned conversation in the agent inbox', adminListCode.includes('c.unread_for_admin') && adminListCode.includes('c.updated_at DESC') && adminListCode.includes('m.is_internal = 0') && supportBackend.includes('function sh_support_admin_poll('));
  check('existing schema already supports manual customer/admin/system rows and the non-AI none status', supportSchema.includes("sender ENUM('customer','assistant','admin','internal_note','system')") && supportSchema.includes("source ENUM('ai','fallback','manual','system')") && supportSchema.includes("reply_status ENUM('none','pending','complete','failed','paused')"));
  check('front end accepts a committed message acknowledgement even when the snapshot omits that row', supportJs.includes('payload.message_saved === true') && supportJs.includes('reconcileSavedSend(send, payload.message)') && supportJs.includes("message.sender === 'customer'"));

  await sleep(20);
  check('customer state loaded before sending', document.querySelector('[data-thread-title]').textContent.includes('#7'));

  input.value = 'Where is my order?';
  form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  await sleep(0);
  check('message appears optimistically before the HTTP response', messages.textContent.includes('Where is my order?'));
  check('honest loading spinner is shown while the request is pending', Boolean(messages.querySelector('.sh-support-chat__spinner')) && messages.textContent.includes('Replying…'));
  check('one request uses a stable idempotency key', sendRequests.length === 1 && /^[0-9a-f-]{36}$/i.test(sendRequests[0].client_id));
  check('state requests no longer require a selected language', stateUrls.length > 0 && stateUrls.every((query) => !query.includes('language=')));

  resolveFirstSend(jsonResponse({ success: true, ok: true, snapshot: makeSnapshot(sendRequests[0].client_id, 100, sendRequests[0].body, 101, 'Your verified order is in transit.') }));
  await sleep(20);
  check('server identity reconciles the optimistic row without duplication', messages.querySelectorAll('.sh-support-chat__message--customer').length === 1);
  check('mocked successful send displays the returned assistant response', messages.textContent.includes('Your verified order is in transit.') && !messages.querySelector('.sh-support-chat__spinner'));
  check('confirmed send clears the composer', input.value === '');

  input.value = 'Is the blue scarf in stock?';
  form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  await sleep(20);
  const firstAttemptId = sendRequests[1] && sendRequests[1].client_id;
  check('network loss keeps the customer message and shows a short unconfirmed state', messages.textContent.includes('Is the blue scarf in stock?') && messages.textContent.includes('Message not confirmed.'));
  check('failed transport offers one inline safe retry without a duplicate banner', Boolean(Array.from(messages.querySelectorAll('button')).find((button) => button.textContent.includes('Retry send'))) && error.hidden);

  const retrySendButton = Array.from(messages.querySelectorAll('button')).find((button) => button.textContent.includes('Retry send'));
  retrySendButton && retrySendButton.click();
  await sleep(30);
  check('safe send retry reuses the original client id', sendRequests.length === 3 && sendRequests[2].client_id === firstAttemptId);
  check('mocked inference failure keeps the customer message and shows only a friendly retryable error', messages.querySelectorAll('.sh-support-chat__message--customer').length === 2 && messages.textContent.includes("Sorry, I couldn't reply right now. Please try again.") && messages.querySelectorAll('.sh-support-chat__message--assistant').length === 1 && !messages.textContent.includes('Store fallback'));
  check('model failure exposes a reply retry action and clears confirmed composer text', Boolean(Array.from(messages.querySelectorAll('button')).find((button) => button.textContent === 'Retry')) && input.value === '');

  const retryReplyButton = Array.from(messages.querySelectorAll('button')).find((button) => button.textContent === 'Retry');
  retryReplyButton && retryReplyButton.click();
  await sleep(30);
  check('reply retry preserves message identity and replaces the failure state with the response', retryRequests.length === 1 && retryRequests[0].message_id === 102 && messages.textContent.includes('Your earlier message is answered now.') && !messages.textContent.includes("Sorry, I couldn't reply right now."));

  for (const [body, placeholder] of [
    ['Ami order korte chai', 'Message likhun…'],
    ['আমি অর্ডার করতে চাই', 'বার্তা লিখুন…'],
    ['Can you help me?', 'Write a message…'],
  ]) {
    input.value = body;
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await sleep(20);
    check(`chat labels follow the latest ${body === 'Ami order korte chai' ? 'Banglish' : body === 'আমি অর্ডার করতে চাই' ? 'Bengali' : 'English'} message`, input.placeholder === placeholder);
  }

  Object.defineProperty(document, 'hidden', { configurable: true, value: false });
  humanMode = true;
  document.dispatchEvent(new Event('visibilitychange'));
  await sleep(20);
  check('human handoff/requested state still allows the existing conversation form', document.querySelector('[data-thread-state]').textContent === 'Human support requested' && !form.hidden);

  omitNextHumanRowFromSnapshot = true;
  const pendingHandoffBody = 'I requested a human agent to review my order.';
  input.value = pendingHandoffBody;
  form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  await sleep(25);
  const pendingHandoffSend = sendRequests[sendRequests.length - 1];
  check('customer message after requesting human support is confirmed by persisted-row acknowledgement', messages.textContent.includes(pendingHandoffBody) && humanRows.some((row) => row.sender === 'customer' && row.client_message_id === pendingHandoffSend.client_id) && !messages.textContent.includes('Message not confirmed.'));
  check('pending human-support message uses the same conversation and is not sent to AI', Number(pendingHandoffSend.id) === 7 && humanRows.every((row) => row.sender !== 'assistant') && !messages.querySelector('.sh-support-chat__spinner'));

  humanStatus = { status: 'assigned', assigned_admin_id: 17, ai_paused: true };
  addAgentReply('A support agent has taken over this conversation.');
  document.dispatchEvent(new Event('visibilitychange'));
  await sleep(25);
  check('agent assignment/reply is visible after polling the same thread', document.querySelector('[data-thread-state]').textContent === 'A support agent has taken over' && messages.textContent.includes('A support agent has taken over this conversation.'));

  input.value = 'Thanks. I have one more delivery question.';
  form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  await sleep(25);
  const afterAgentReplySend = sendRequests[sendRequests.length - 1];
  check('customer can send again after an agent reply without changing conversation or assignment', Number(afterAgentReplySend.id) === 7 && humanStatus.status === 'assigned' && humanStatus.assigned_admin_id === 17 && humanStatus.ai_paused && humanRows.some((row) => row.sender === 'customer' && row.client_message_id === afterAgentReplySend.client_id));

  addAgentReply('I checked the delivery details for you.');
  document.dispatchEvent(new Event('visibilitychange'));
  await sleep(25);
  check('second agent reply persists and renders between customer follow-ups', messages.textContent.includes('I checked the delivery details for you.'));
  input.value = 'Please confirm the updated delivery date.';
  form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  await sleep(25);
  const thirdHumanSend = sendRequests[sendRequests.length - 1];
  check('repeated customer-agent-customer-agent cycle remains sendable on one conversation', Number(thirdHumanSend.id) === 7 && humanRows.filter((row) => row.sender === 'customer').length === 3 && humanRows.filter((row) => row.sender === 'admin').length === 2);

  failNextHumanResponseAfterCommit = true;
  input.value = 'I did not get the update notification.';
  form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  await sleep(25);
  const failedHumanSend = sendRequests[sendRequests.length - 1];
  check('lost response after commit leaves a retryable customer message instead of losing it', messages.textContent.includes('Message not confirmed.') && humanRows.filter((row) => row.sender === 'customer' && row.client_message_id === failedHumanSend.client_id).length === 1);
  const humanRetryButton = Array.from(messages.querySelectorAll('button')).find((button) => button.textContent.includes('Retry send'));
  humanRetryButton && humanRetryButton.click();
  await sleep(30);
  const humanRetry = sendRequests[sendRequests.length - 1];
  check('human-mode retry reuses its original id and does not duplicate a committed message', humanRetry.client_id === failedHumanSend.client_id && humanRows.filter((row) => row.sender === 'customer' && row.client_message_id === failedHumanSend.client_id).length === 1 && !messages.textContent.includes('Message not confirmed.'));

  addAgentReply('The update is confirmed.');
  document.dispatchEvent(new Event('visibilitychange'));
  await sleep(25);
  check('refresh/poll keeps both customer follow-ups and later agent replies visible once', messages.textContent.includes('Please confirm the updated delivery date.') && messages.textContent.includes('The update is confirmed.') && messages.querySelectorAll('.sh-support-chat__message--customer').length >= 7);

  const reloadedDom = new JSDOM(html, { url: 'http://localhost/support.php', runScripts: 'outside-only' });
  reloadedDom.window.fetch = fetchMock;
  reloadedDom.window.eval(supportJs);
  await sleep(25);
  const reloadedMessages = reloadedDom.window.document.querySelector('[data-chat-messages]');
  check('cold page reload restores persisted customer and agent messages from server state', reloadedDom.window.document.querySelector('[data-thread-title]').textContent.includes('#7') && reloadedMessages.textContent.includes('Please confirm the updated delivery date.') && reloadedMessages.textContent.includes('The update is confirmed.'));
  reloadedDom.window.close();
} finally {
  dom.window.close();
}

if (failures.length) {
  console.error(`\n${failures.length} failed, ${passed} passed`);
  process.exitCode = 1;
} else {
  console.log(`\n${passed} passed, 0 failed\nSUPPORT CHAT OPTIMISTIC BEHAVIOUR: ALL CHECKS PASSED`);
}
