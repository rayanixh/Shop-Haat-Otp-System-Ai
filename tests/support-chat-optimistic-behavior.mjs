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

function jsonResponse(payload, status = 200) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(payload) });
}

async function fetchMock(rawUrl, options = {}) {
  const url = new URL(rawUrl, 'http://localhost');
  const action = url.searchParams.get('action');
  if (action === 'state') { stateUrls.push(url.search); return jsonResponse(emptySnapshot); }
  if (action === 'retry') {
    const body = JSON.parse(options.body || '{}');
    retryRequests.push(body);
    const failed = sendRequests[2];
    return jsonResponse({ success: true, ok: true, snapshot: makeSnapshot(failed.client_id, 102, failed.body, 104, 'Your earlier message is answered now.') });
  }
  if (action !== 'send') throw new Error(`Unexpected support action: ${action}`);
  const body = JSON.parse(options.body || '{}');
  sendRequests.push(body);
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
} finally {
  dom.window.close();
}

if (failures.length) {
  console.error(`\n${failures.length} failed, ${passed} passed`);
  process.exitCode = 1;
} else {
  console.log(`\n${passed} passed, 0 failed\nSUPPORT CHAT OPTIMISTIC BEHAVIOUR: ALL CHECKS PASSED`);
}
