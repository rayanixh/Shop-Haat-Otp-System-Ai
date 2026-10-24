import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const require_ = createRequire(import.meta.url);
const { JSDOM } = require_(process.env.SH_JSDOM_PATH || 'jsdom');
const sleep = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));

const html = `<!doctype html><html><body>
<div data-support-chat data-api="/api/support.php" data-csrf="test-token">
  <p data-chat-status></p><p data-ai-note></p><div data-chat-error hidden></div>
  <div data-chat-empty hidden><span data-empty-copy></span><button data-chat-open></button></div>
  <div data-chat-history hidden><span data-thread-title></span><span data-thread-state></span>
    <button data-chat-new hidden></button><button data-chat-delete hidden></button>
    <ol data-chat-messages></ol><button data-load-older hidden></button>
    <button data-handoff hidden></button><span data-handoff-note></span>
    <form data-chat-form><textarea data-chat-input></textarea><span data-char-count></span><button data-chat-send><svg></svg> Send</button></form>
    <div data-chat-disabled hidden></div>
  </div>
  <select data-language><option value="en">English</option><option value="bn">বাংলা</option><option value="banglish">Banglish</option></select>
</div></body></html>`;

let resolveFirstSend;
const sendRequests = [];
const emptySnapshot = {
  ok: true,
  success: true,
  conversation: { id: 7, status: 'open', assigned_admin_id: 0, ai_paused: false },
  messages: [],
  has_more: false,
  older_cursor: 0,
  support: { enabled: true, ai_available: true, handoff_enabled: true },
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

function jsonResponse(payload, status = 200) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(payload) });
}

async function fetchMock(rawUrl, options = {}) {
  const url = new URL(rawUrl, 'http://localhost');
  const action = url.searchParams.get('action');
  if (action === 'state') return jsonResponse(emptySnapshot);
  if (action !== 'send') throw new Error(`Unexpected support action: ${action}`);
  const body = JSON.parse(options.body || '{}');
  sendRequests.push(body);
  if (sendRequests.length === 1) {
    return new Promise((resolve) => { resolveFirstSend = resolve; });
  }
  if (sendRequests.length === 2) throw new Error('simulated network loss after the optimistic send');
  if (sendRequests.length === 3) {
    return jsonResponse({ success: true, ok: true, snapshot: makeSnapshot(body.client_id, 102, body.body, 103, 'A verified store fallback.', 'fallback') });
  }
  throw new Error('Unexpected duplicate send');
}

const dom = new JSDOM(html, { url: 'http://localhost/support.php', runScripts: 'outside-only' });
dom.window.fetch = fetchMock;
dom.window.confirm = () => true;
dom.window.eval(readFileSync(join(ROOT, 'assets/js/support.js'), 'utf8'));
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
  await sleep(20);
  check('customer state loaded before sending', document.querySelector('[data-thread-title]').textContent.includes('#7') || !document.querySelector('[data-chat-empty]').hidden);

  input.value = 'Where is my order?';
  form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  await sleep(0);
  check('message appears optimistically before the HTTP response', messages.textContent.includes('Where is my order?'));
  check('optimistic state says sending, not that a reply already exists', messages.textContent.includes('Sending…') && !messages.textContent.includes('A verified store fallback.'));
  check('one request uses a stable idempotency key', sendRequests.length === 1 && /^[0-9a-f-]{36}$/i.test(sendRequests[0].client_id));

  resolveFirstSend(jsonResponse({ success: true, ok: true, snapshot: makeSnapshot(sendRequests[0].client_id, 100, sendRequests[0].body, 101, 'Your verified order is in transit.') }));
  await sleep(20);
  check('server identity reconciles the optimistic row without duplication', messages.querySelectorAll('.sh-support-chat__message--customer').length === 1);
  check('successful send replaces loading with the server-generated response', messages.textContent.includes('Your verified order is in transit.') && !messages.textContent.includes('Sending…'));
  check('confirmed send clears the composer', input.value === '');

  input.value = 'Is the blue scarf in stock?';
  form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  await sleep(20);
  const firstAttemptId = sendRequests[1] && sendRequests[1].client_id;
  check('network loss keeps the message and shows an honest unconfirmed state', messages.textContent.includes('Is the blue scarf in stock?') && messages.textContent.includes('Delivery is unconfirmed'));
  check('failed transport offers a safe retry control', Boolean(messages.querySelector('button') && messages.textContent.includes('Retry send')) && !error.hidden);

  const retryButton = Array.from(messages.querySelectorAll('button')).find((button) => button.textContent.includes('Retry send'));
  retryButton && retryButton.click();
  await sleep(30);
  check('safe retry reuses the original client id', sendRequests.length === 3 && sendRequests[2].client_id === firstAttemptId);
  check('retry reconciles exactly one customer row and displays the fallback honestly', messages.querySelectorAll('.sh-support-chat__message--customer').length === 2 && messages.textContent.includes('Store fallback') && messages.textContent.includes('A verified store fallback.'));
  check('confirmed retry clears the matching composer text', input.value === '');
} finally {
  dom.window.close();
}

if (failures.length) {
  console.error(`\n${failures.length} failed, ${passed} passed`);
  process.exitCode = 1;
} else {
  console.log(`\n${passed} passed, 0 failed\nSUPPORT CHAT OPTIMISTIC BEHAVIOUR: ALL CHECKS PASSED`);
}
