/**
 * Admin support inbox behaviour harness.
 *
 * Runs the REAL assets/js/support-admin.js in jsdom with a scripted fetch()
 * standing in for api/support.php, against fixture markup mirroring
 * admin/support.php (kept honest by source checks). Covers the human-support
 * ownership workflow requested by the store owner:
 *
 *   - every human-requested conversation shows customer name / Guest
 *     customer, preview, request time, last message time, assignment status,
 *     human-support status, AI status and unread count;
 *   - Join Conversation / Assign to Me / Reply as Human / Leave Conversation /
 *     Close Conversation / Reopen Conversation are explicit and wired to the
 *     server-side allowlisted transitions with CSRF;
 *   - joining changes Pending human support -> In progress, shows the agent
 *     name, and enables the human composer;
 *   - polling and the ordinary reply/poll requests still work.
 *
 * Test-only tooling: the shipped store remains plain PHP/MySQL with no Node.js.
 *
 *   node tests/support-admin-inbox-behavior.mjs
 */
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const require_ = createRequire(import.meta.url);
let JSDOM = null;
try {
  const path = process.env.SH_JSDOM_PATH;
  ({ JSDOM } = path ? require_(path) : require_('jsdom'));
} catch { JSDOM = null; }
if (!JSDOM) {
  const msg = 'jsdom is not installed, so the admin-support-inbox harness did not run.';
  if (process.env.SH_SC_REQUIRE_JSDOM === '1') { console.error('::error::' + msg); process.exit(1); }
  console.log('SKIP  ' + msg);
  process.exit(0);
}

let passed = 0;
const failures = [];
function check(name, condition, detail) {
  if (condition) { passed += 1; console.log('  ok  ' + name); }
  else { failures.push(name); console.log('FAIL  ' + name + (detail ? ' — ' + detail : '')); }
}
const sleep = (ms = 0) => new Promise((r) => setTimeout(r, ms));

const pagePhp = readFileSync(join(ROOT, 'admin/support.php'), 'utf8');
const adminJs = readFileSync(join(ROOT, 'assets/js/support-admin.js'), 'utf8');
const adminCss = readFileSync(join(ROOT, 'assets/css/support-admin.css'), 'utf8');
const apiPhp = readFileSync(join(ROOT, 'api/support.php'), 'utf8');
const backendPhp = readFileSync(join(ROOT, 'includes/ai-support.php'), 'utf8');

/* --- Source checks: the shipped page must actually expose the actions ----- */
check('inbox page sets data-can-reply so actions and the composer are not hidden',
  /data-can-reply="<\?=\s*\$canReply\s*\?\s*'1'\s*:\s*'0'\s*\?>"/.test(pagePhp)
  && adminJs.includes("root.dataset.canReply === '1'"));
for (const [attr, label] of [
  ['data-join', 'Join Conversation'], ['data-assign-me', 'Assign to Me'],
  ['data-reply-human', 'Reply as Human'], ['data-leave', 'Leave Conversation'],
  ['data-close', 'Close Conversation'], ['data-reopen', 'Reopen Conversation'],
]) {
  check('shipped markup exposes ' + label, pagePhp.includes(attr) && pagePhp.includes(label));
}
for (const t of ['join', 'assign_to_me', 'leave', 'close', 'reopen', 'resume_ai']) {
  check('server API allowlists ' + t, apiPhp.includes("'" + t + "'"));
}
check('admin inbox is RBAC + CSRF protected server-side',
  pagePhp.includes("sh_require_admin()") && pagePhp.includes("sh_admin_can('support.view'")
  && apiPhp.includes('sh_csrf_require()') && apiPhp.includes("sh_admin_can(...$requiredAdminPerms)"));
check('join uses an atomic conditional UPDATE and FOR UPDATE lock',
  backendPhp.includes("SELECT * FROM support_conversations WHERE id = ? FOR UPDATE")
  && backendPhp.includes("WHERE id = ? AND (assigned_admin_id IS NULL OR assigned_admin_id = 0)")
  && backendPhp.includes('$claimed !== 1'));
check('polling is preserved', adminJs.includes("request('admin_poll'") && adminJs.includes('setInterval(poll, 12000)'));
check('AI resumes only via an explicit transition', adminJs.includes("'resume_ai'")
  && backendPhp.includes("$action === 'resume_ai'"));
check('inbox state grid styling exists', adminCss.includes('.sh-support-admin__state-grid')
  && adminCss.includes('.sh-support-admin__human-actions'));

/* --- Runtime harness ------------------------------------------------------ */
const HTML = `<!doctype html><html><body>
<div class="sh-support-admin" data-support-inbox data-api="/api/support.php" data-csrf="csrf-token"
     data-selected-id="7" data-admin-id="1" data-can-reply="1" data-superadmin="0" data-status="" data-query="">
  <div data-support-stats><span data-stat-conversations>1</span><span data-stat-unread>2</span>
    <span data-stat-ai>0</span><span data-stat-fallback>0</span></div>
  <form data-support-filter><select data-filter-status><option value="">All</option></select>
    <input data-filter-query><button type="submit">Find</button></form>
  <div data-admin-error hidden></div>
  <div data-conversation-list></div><span data-list-count></span>
  <p data-list-empty hidden></p>
  <div data-list-pagination hidden><button data-page-prev></button><span data-page-label></span><button data-page-next></button></div>
  <div data-thread-empty><h3>Select a conversation</h3></div>
  <div data-thread hidden>
    <div class="sh-support-admin__thread-head"><h3 data-thread-subject></h3>
      <p data-customer-details></p><p data-thread-status></p>
      <div class="sh-support-admin__thread-actions">
        <button data-ai-action></button><button data-resolve-action></button></div></div>
    <div data-state-grid>
      <div><span>Human support</span><strong data-state-human>—</strong></div>
      <div><span>Assignment</span><strong data-state-assignment>—</strong></div>
      <div><span>AI</span><strong data-state-ai>—</strong></div>
      <div><span>Unread</span><strong data-state-unread>0</strong></div>
      <div><span>Requested</span><strong data-state-requested>—</strong></div>
      <div><span>Last message</span><strong data-state-last>—</strong></div>
    </div>
    <div data-human-actions>
      <button data-join>Join Conversation</button><button data-assign-me>Assign to Me</button>
      <button data-reply-human>Reply as Human</button><button data-leave>Leave Conversation</button>
      <button data-close>Close Conversation</button><button data-reopen>Reopen Conversation</button>
    </div>
    <div class="sh-support-admin__assignment">
      <select data-agent-select><option value="">Unassigned</option></select>
      <button data-assign>Assign</button><button data-unassign>Unassign</button><button data-takeover>Take over</button>
    </div>
    <div data-handoff-events hidden></div>
    <button data-thread-older hidden>Load older</button>
    <ol data-thread-messages></ol>
    <form data-reply-form>
      <select data-reply-kind><option value="public">Reply to customer</option><option value="internal">Internal note</option></select>
      <span data-reply-label>Reply</span><textarea data-reply-input></textarea>
      <span data-reply-count>0 / 2,000</span><button type="submit" data-reply-send><svg></svg> Send reply</button>
    </form>
  </div>
</div></body></html>`;

const dom = new JSDOM(HTML, { url: 'https://shop.example.test/admin/support.php', runScripts: 'outside-only', pretendToBeVisual: true });
const { window } = dom;
window.Element.prototype.scrollIntoView = function () {};
window.crypto.getRandomValues = (arr) => { for (let i = 0; i < arr.length; i += 1) arr[i] = (i * 17 + 3) & 255; return arr; };
window.setInterval = () => 1;   // avoid a real 12s poll timer in the harness
window.clearInterval = () => {};
window.fetch = async (url, config = {}) => {
  const u = new URL(String(url), window.location.href);
  const action = u.searchParams.get('action');
  const method = config.method || 'GET';
  requests.push({ action, method, config, body: config.body ? JSON.parse(config.body) : null });
  const reply = (obj, status = 200) => ({
    ok: status >= 200 && status < 300, status,
    json: async () => obj,
  });
  if (action === 'admin_list') {
    return reply({ success: true, data: { rows: [Object.assign({}, conversation)], total: 1, page: 1, pages: 1 },
      stats: { conversations: 1, unread: 2, today: { succeeded: 0, fallback: 0 } } });
  }
  if (action === 'admin_thread') {
    return reply({ success: true, conversation: Object.assign({}, conversation),
      messages: [{ id: 10, sender: 'customer', body: 'Please call me back', created_at: '2026-11-07 10:00:00', is_internal: 0 },
        { id: 11, sender: 'system', body: 'Your request has been recorded in the support inbox.', created_at: '2026-11-07 10:00:01', is_internal: 0 }],
      events: [], agents: [{ id: 1, name: 'Test Agent' }, { id: 2, name: 'Second Agent' }], has_more: false, older_cursor: 0 });
  }
  if (action === 'admin_poll') {
    return reply({ success: true, conversation: Object.assign({}, conversation), messages: [], events: [] });
  }
  if (action === 'admin_mark_read' || action === 'admin_transition' || action === 'admin_reply') {
    if (method !== 'POST' || config.headers['X-CSRF-Token'] !== 'csrf-token') {
      return reply({ success: false, error: 'CSRF required' }, 403);
    }
    if (action === 'admin_transition') {
      const t = config.body ? JSON.parse(config.body).transition : '';
      transitions.push(t);
      if (t === 'join' || t === 'assign_to_me') {
        Object.assign(conversation, { status: 'assigned', assigned_admin_id: 1, assigned_name: 'Test Agent',
          human_status: 'in_progress', human_status_label: 'In progress', ai_status: 'Paused',
          assignment_status: 'Assigned', assignment_label: 'Assigned to Test Agent',
          assigned_agent_name: 'Test Agent', unread_count: 0, unread_for_admin: 0 });
      } else if (t === 'leave') {
        Object.assign(conversation, { status: 'pending', assigned_admin_id: 0, assigned_name: '',
          human_status: 'pending', human_status_label: 'Pending human support',
          assignment_status: 'Unassigned', assignment_label: 'Unassigned', assigned_agent_name: '' });
      } else if (t === 'close') {
        Object.assign(conversation, { status: 'closed', human_status: 'resolved', human_status_label: 'Closed / resolved' });
      } else if (t === 'reopen') {
        Object.assign(conversation, { status: 'open', ai_paused: 1, human_status: 'pending',
          human_status_label: 'Pending human support' });
      }
    }
    return reply({ success: true });
  }
  return reply({ success: false, error: 'unknown' }, 404);
};

const requests = [];
const transitions = [];
let conversation = {
  id: 7, user_id: 0, subject: 'Order question', status: 'pending', assigned_admin_id: 0, ai_paused: 1,
  unread_for_admin: 2, unread_for_customer: 0, last_message_at: '2026-11-07 10:00:00',
  created_at: '2026-11-07 09:00:00', updated_at: '2026-11-07 10:00:00',
  customer_name: '', customer_email: '', customer_phone: '', assigned_name: '',
  human_status: 'pending', human_status_label: 'Pending human support', ai_status: 'Paused',
  assignment_status: 'Unassigned', assignment_label: 'Unassigned', assigned_agent_name: '',
  unread_count: 2, request_time: '2026-11-07 09:30:00', last_message_time: '2026-11-07 10:00:00',
  message_preview: 'Please call me back', last_message: 'Please call me back',
};

const $ = (sel) => window.document.querySelector(sel);
const click = async (sel) => { $(sel).click(); await sleep(40); };

window.eval(adminJs);
await sleep(60);

/* --- Inbox row fields ------------------------------------------------------ */
const rowText = $('[data-conversation-list]').textContent;
check('inbox shows Guest customer when there is no account name', rowText.includes('Guest customer'));
check('inbox shows the latest message preview', rowText.includes('Please call me back'));
check('inbox shows the human-support status', rowText.includes('Pending human support'));
check('inbox shows AI status', rowText.includes('Paused'));
check('inbox shows assignment status', rowText.includes('Unassigned'));
check('inbox shows the unread count', /2 unread/.test(rowText));
check('inbox shows request and last-message times',
  rowText.includes('Requested') && rowText.includes('Last message'));

/* --- Thread state before join ---------------------------------------------- */
check('thread status grid shows the pending state', $('[data-state-human]').textContent === 'Pending human support');
check('thread status grid shows AI Paused', $('[data-state-ai]').textContent === 'Paused');
check('thread status grid shows Unassigned', $('[data-state-assignment]').textContent === 'Unassigned');
check('thread status grid shows an unread count', $('[data-state-unread]').textContent === '2');
check('Join Conversation is offered for a pending unassigned request', $('[data-join]').hidden === false);
check('Assign to Me is offered for a pending unassigned request', $('[data-assign-me]').hidden === false);
check('Leave Conversation is hidden before joining', $('[data-leave]').hidden === true);
check('Reopen Conversation is hidden while open', $('[data-reopen]').hidden === true);
check('the human composer is visible once canReply is set', $('[data-reply-form]').hidden === false);
check('opening the thread is a read-only GET and marking read is a CSRF POST',
  requests.some((r) => r.action === 'admin_thread' && r.method === 'GET')
  && requests.some((r) => r.action === 'admin_mark_read' && r.method === 'POST' && r.config.headers['X-CSRF-Token'] === 'csrf-token'));

/* --- Join ------------------------------------------------------------------ */
await click('[data-join]');
check('join posts an allowlisted CSRF-protected transition',
  transitions.includes('join') && requests.some((r) => r.action === 'admin_transition' && r.method === 'POST'
    && r.body.transition === 'join' && r.body.id === 7 && r.config.headers['X-CSRF-Token'] === 'csrf-token'));
check('joining changes Pending human support to In progress', $('[data-state-human]').textContent === 'In progress');
check('joining displays the assigned agent name', $('[data-thread-status]').textContent.includes('Agent Test Agent')
  && $('[data-state-assignment]').textContent.includes('Test Agent'));
check('AI stays Paused after joining', $('[data-state-ai]').textContent === 'Paused');
check('Join and Assign to Me are hidden once owned', $('[data-join]').hidden === true && $('[data-assign-me]').hidden === true);
check('Leave Conversation becomes available to the owner', $('[data-leave]').hidden === false);
check('composer is flagged as human-owned', $('[data-reply-form]').classList.contains('is-human-owned'));

/* --- Reply as Human -------------------------------------------------------- */
$('[data-reply-kind]').value = 'internal';
await click('[data-reply-human]');
check('Reply as Human enables a public human reply', $('[data-reply-kind]').value === 'public'
  && $('[data-reply-input]').disabled === false && $('[data-reply-form]').hidden === false);
check('Reply as Human does not claim ownership again', transitions.filter((t) => t === 'join').length === 1);

/* --- Reply delivery -------------------------------------------------------- */
$('[data-reply-input]').value = 'Hello, this is a human support agent.';
$('[data-reply-form]').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
await sleep(60);
check('the human reply is sent through the existing admin_reply endpoint with CSRF',
  requests.some((r) => r.action === 'admin_reply' && r.method === 'POST'
    && r.body.body === 'Hello, this is a human support agent.'
    && r.config.headers['X-CSRF-Token'] === 'csrf-token'));

/* --- Leave / Close / Reopen ------------------------------------------------ */
await click('[data-leave]');
check('Leave Conversation posts the leave transition', transitions.includes('leave'));
check('leaving returns the inbox state to Pending human support', $('[data-state-human]').textContent === 'Pending human support');
await click('[data-close]');
check('Close Conversation posts the close transition', transitions.includes('close'));
check('closing shows Reopen Conversation', $('[data-reopen]').hidden === false && $('[data-close]').hidden === true);
await click('[data-reopen]');
check('Reopen Conversation posts the reopen transition', transitions.includes('reopen'));
check('reopening does not silently resume AI', $('[data-state-ai]').textContent === 'Paused');

console.log(`\n${passed} passed, ${failures.length} failed`);
if (failures.length) { console.log('FAILURES:\n - ' + failures.join('\n - ')); process.exit(1); }
console.log('ADMIN SUPPORT INBOX BEHAVIOUR: ALL CHECKS PASSED');
