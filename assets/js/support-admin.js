(() => {
  'use strict';
  const root = document.querySelector('[data-support-inbox]');
  if (!root) return;
  const api = root.dataset.api || '';
  const csrf = root.dataset.csrf || '';
  const adminId = Number(root.dataset.adminId || 0);
  const superadmin = root.dataset.superadmin === '1';
  const $ = (selector) => root.querySelector(selector);
  const listNode = $('[data-conversation-list]');
  const listEmpty = $('[data-list-empty]');
  const listCount = $('[data-list-count]');
  const pagination = $('[data-list-pagination]');
  const pagePrev = $('[data-page-prev]');
  const pageNext = $('[data-page-next]');
  const pageLabel = $('[data-page-label]');
  const errorNode = $('[data-admin-error]');
  const threadEmpty = $('[data-thread-empty]');
  const threadNode = $('[data-thread]');
  const threadSubject = $('[data-thread-subject]');
  const customerDetails = $('[data-customer-details]');
  const threadStatus = $('[data-thread-status]');
  const messageList = $('[data-thread-messages]');
  const eventsNode = $('[data-handoff-events]');
  const olderButton = $('[data-thread-older]');
  const agentSelect = $('[data-agent-select]');
  const assignButton = $('[data-assign]');
  const unassignButton = $('[data-unassign]');
  const takeoverButton = $('[data-takeover]');
  const aiActionButton = $('[data-ai-action]');
  const resolveButton = $('[data-resolve-action]');
  const replyForm = $('[data-reply-form]');
  const replyKind = $('[data-reply-kind]');
  const replyLabel = $('[data-reply-label]');
  const replyInput = $('[data-reply-input]');
  const replyCount = $('[data-reply-count]');
  const replySend = $('[data-reply-send]');

  let statusFilter = root.dataset.status || '';
  let queryFilter = root.dataset.query || '';
  let currentPage = 1;
  let totalPages = 1;
  let selectedId = Number(root.dataset.selectedId || 0);
  let currentThread = null;
  let messageMap = new Map();
  let eventsMap = new Map();
  let hasMore = false;
  let olderCursor = 0;
  let busy = false;
  let pollTimer = null;

  function showError(message) {
    errorNode.textContent = message || 'The action could not be completed.';
    errorNode.hidden = false;
  }
  function clearError() { errorNode.hidden = true; errorNode.textContent = ''; }

  function makeUuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    const b = new Uint8Array(16);
    window.crypto.getRandomValues(b);
    b[6] = (b[6] & 15) | 64;
    b[8] = (b[8] & 63) | 128;
    const h = Array.from(b, (v) => v.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
  }

  async function request(action, options = {}) {
    const method = options.method || 'GET';
    const url = new URL(api, window.location.href);
    url.searchParams.set('action', action);
    Object.entries(options.params || {}).forEach(([key, value]) => url.searchParams.set(key, String(value)));
    const headers = { Accept: 'application/json' };
    const config = { method, credentials: 'same-origin', headers };
    if (method === 'POST') {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = csrf;
      config.body = JSON.stringify({ action, ...(options.data || {}) });
    }
    const response = await fetch(url.toString(), config);
    let data;
    try { data = await response.json(); } catch (_) { throw new Error('The server returned an unreadable response.'); }
    if (!response.ok || data.success === false) throw new Error(data.error || 'The request could not be completed.');
    return data;
  }

  function localTime(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T') + 'Z');
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  }

  function statusLabel(status) {
    return ({ open: 'Open', pending: 'Pending human support', assigned: 'Assigned', resolved: 'Resolved' })[status] || String(status || 'Open');
  }

  function makeBadge(label, modifier = '') {
    const badge = document.createElement('span');
    badge.className = `sh-support-admin__badge${modifier ? ` sh-support-admin__badge--${modifier}` : ''}`;
    badge.textContent = label;
    return badge;
  }

  async function loadList(page = currentPage) {
    try {
      const data = await request('admin_list', { params: { status: statusFilter, q: queryFilter, page, per_page: 25 } });
      const result = data.data || {};
      currentPage = Number(result.page || 1);
      totalPages = Number(result.pages || 1);
      const rows = Array.isArray(result.rows) ? result.rows : [];
      const fragment = document.createDocumentFragment();
      rows.forEach((row) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `sh-support-admin__conversation${Number(row.unread_for_admin) ? ' is-unread' : ''}`;
        button.dataset.id = String(row.id);
        button.setAttribute('aria-current', Number(row.id) === selectedId ? 'true' : 'false');

        const top = document.createElement('span');
        top.className = 'sh-support-admin__conversation-top';
        const name = document.createElement('strong');
        name.textContent = row.customer_name || 'Guest customer';
        top.append(name);
        const time = document.createElement('time');
        time.dateTime = String(row.last_message_at || row.updated_at || '');
        time.textContent = localTime(row.last_message_at || row.updated_at);
        top.append(time);
        button.append(top);

        const meta = document.createElement('span');
        meta.className = 'sh-support-admin__conversation-meta';
        const subject = document.createElement('span');
        subject.textContent = row.subject || `Conversation #${row.id}`;
        meta.append(subject);
        const assignee = document.createElement('span');
        assignee.textContent = row.assigned_name ? `Assigned: ${row.assigned_name}` : 'Unassigned';
        meta.append(assignee);
        button.append(meta);

        const preview = document.createElement('p');
        preview.className = 'sh-support-admin__conversation-preview';
        preview.textContent = row.last_message || 'No public message yet.';
        button.append(preview);

        const badges = document.createElement('span');
        badges.className = 'sh-support-admin__badge-row';
        badges.append(makeBadge(statusLabel(row.status), row.status === 'pending' ? 'pending' : ''));
        if (Number(row.unread_for_admin)) badges.append(makeBadge('Unread', 'unread'));
        if (Number(row.ai_paused)) badges.append(makeBadge('AI paused'));
        button.append(badges);
        button.addEventListener('click', () => selectConversation(Number(row.id), true));
        fragment.append(button);
      });
      listNode.replaceChildren(fragment);
      listCount.textContent = `${Number(result.total || 0).toLocaleString()} total`;
      listEmpty.hidden = rows.length !== 0;
      pagination.hidden = totalPages <= 1;
      pageLabel.textContent = `Page ${currentPage} of ${totalPages}`;
      pagePrev.disabled = currentPage <= 1;
      pageNext.disabled = currentPage >= totalPages;
      if (data.stats) updateStats(data.stats);
      clearError();
    } catch (error) { showError(error.message); }
  }

  function updateStats(stats) {
    $('[data-stat-conversations]').textContent = Number(stats.conversations || 0).toLocaleString();
    $('[data-stat-unread]').textContent = Number(stats.unread || 0).toLocaleString();
    $('[data-stat-ai]').textContent = Number((stats.today || {}).succeeded || 0).toLocaleString();
    $('[data-stat-fallback]').textContent = Number((stats.today || {}).fallback || 0).toLocaleString();
  }

  function updateAddress(id) {
    const url = new URL(window.location.href);
    if (id > 0) url.searchParams.set('id', String(id));
    else url.searchParams.delete('id');
    if (statusFilter) url.searchParams.set('status', statusFilter); else url.searchParams.delete('status');
    if (queryFilter) url.searchParams.set('q', queryFilter); else url.searchParams.delete('q');
    window.history.replaceState({}, '', url.toString());
  }

  async function selectConversation(id, scrollIntoView) {
    if (busy || id <= 0) return;
    busy = true;
    selectedId = id;
    currentThread = null;
    messageMap.clear();
    eventsMap.clear();
    updateAddress(id);
    threadEmpty.hidden = true;
    threadNode.hidden = false;
    threadSubject.textContent = `Conversation #${id}`;
    messageList.replaceChildren();
    eventsNode.hidden = true;
    clearError();
    try {
      const data = await request('admin_thread', { params: { id } });
      populateThread(data, false);
      if (scrollIntoView && window.matchMedia('(max-width: 980px)').matches) {
        threadNode.scrollIntoView({ block: 'start', behavior: 'smooth' });
      }
      startPolling();
      loadList(currentPage);
    } catch (error) {
      threadNode.hidden = true;
      threadEmpty.hidden = false;
      showError(error.message);
    } finally { busy = false; if (currentThread) renderThread(); }
  }

  function populateThread(data, preserveCursor) {
    const newId = Number((data.conversation || {}).id || 0);
    if (currentThread && Number(currentThread.id) !== newId) {
      messageMap.clear();
      eventsMap.clear();
      preserveCursor = false;
    }
    currentThread = data.conversation || null;
    if (!currentThread) return;
    (data.messages || []).forEach((message) => messageMap.set(Number(message.id), message));
    (data.events || []).forEach((event) => eventsMap.set(Number(event.id), event));
    if (!preserveCursor) olderCursor = Number(data.older_cursor || 0);
    hasMore = Boolean(data.has_more || (preserveCursor && hasMore));
    if (Array.isArray(data.agents)) populateAgents(data.agents);
    renderThread();
  }

  function populateAgents(agents) {
    const selected = currentThread ? String(currentThread.assigned_admin_id || '') : '';
    const fragment = document.createDocumentFragment();
    const unassigned = document.createElement('option');
    unassigned.value = '';
    unassigned.textContent = 'Choose an agent…';
    fragment.append(unassigned);
    agents.forEach((agent) => {
      const option = document.createElement('option');
      option.value = String(agent.id);
      option.textContent = `${agent.name}${Number(agent.id) === adminId ? ' (you)' : ''}`;
      fragment.append(option);
    });
    agentSelect.replaceChildren(fragment);
    agentSelect.value = selected;
  }

  function renderThread() {
    if (!currentThread) return;
    const id = Number(currentThread.id);
    threadEmpty.hidden = true;
    threadNode.hidden = false;
    threadSubject.textContent = currentThread.subject || `Conversation #${id}`;
    const details = [];
    if (currentThread.customer_name) details.push(String(currentThread.customer_name));
    if (currentThread.customer_email) details.push(String(currentThread.customer_email));
    if (currentThread.customer_phone) details.push(String(currentThread.customer_phone));
    if (!currentThread.user_id) details.push('Guest session');
    customerDetails.textContent = details.join(' · ');
    const assignee = currentThread.assigned_name ? `Assigned to ${currentThread.assigned_name}` : 'Unassigned';
    const last = currentThread.last_message_at ? `Last message ${localTime(currentThread.last_message_at)}` : '';
    threadStatus.textContent = [statusLabel(currentThread.status), assignee, Number(currentThread.ai_paused) ? 'AI paused' : 'AI active', last].filter(Boolean).join(' · ');

    const assignedId = Number(currentThread.assigned_admin_id || 0);
    const resolved = currentThread.status === 'resolved';
    const assignedToOther = assignedId > 0 && assignedId !== adminId && !superadmin;
    aiActionButton.textContent = Number(currentThread.ai_paused) ? 'Resume AI' : 'Pause AI & take over';
    aiActionButton.dataset.transition = Number(currentThread.ai_paused) ? 'resume_ai' : 'takeover';
    aiActionButton.disabled = resolved || assignedToOther;
    resolveButton.textContent = resolved ? 'Reopen conversation' : 'Resolve conversation';
    resolveButton.dataset.transition = resolved ? 'reopen' : 'resolve';
    resolveButton.disabled = !resolved && assignedToOther;
    takeoverButton.hidden = resolved || !Number(currentThread.ai_paused) || assignedId === adminId;
    takeoverButton.disabled = resolved || assignedToOther;
    assignButton.disabled = resolved || !agentSelect.value;
    unassignButton.hidden = assignedId <= 0;
    unassignButton.disabled = resolved || assignedToOther;
    replyForm.hidden = resolved;
    replyInput.disabled = resolved;
    replyKind.disabled = resolved;
    replySend.disabled = resolved || busy;
    olderButton.hidden = !hasMore;
    renderEvents();
    renderMessages();
  }

  function renderMessages() {
    const items = Array.from(messageMap.values()).sort((a, b) => Number(a.id) - Number(b.id));
    const fragment = document.createDocumentFragment();
    if (items.length === 0) {
      const empty = document.createElement('li');
      empty.className = 'sh-support-admin__message sh-support-admin__message--system';
      empty.textContent = 'No messages in this conversation yet.';
      fragment.append(empty);
    }
    items.forEach((message) => {
      const sender = String(message.sender || 'system');
      const internal = sender === 'internal_note' || Number(message.is_internal) === 1;
      const allowedClass = ['customer', 'assistant', 'admin', 'system', 'internal_note'].includes(sender) ? sender : 'system';
      const item = document.createElement('li');
      item.className = `sh-support-admin__message sh-support-admin__message--${allowedClass}`;
      const meta = document.createElement('p');
      meta.className = 'sh-support-admin__message-meta';
      const who = document.createElement('strong');
      who.textContent = internal ? 'Internal note · not sent to customer'
        : sender === 'customer' ? 'Customer'
          : sender === 'assistant' ? 'AI / fallback assistant'
            : sender === 'admin' ? (message.admin_name ? `Support agent · ${message.admin_name}` : 'Support agent') : 'System update';
      meta.append(who);
      const time = document.createElement('time');
      time.dateTime = String(message.created_at || '');
      time.textContent = localTime(message.created_at);
      meta.append(time);
      item.append(meta);
      const body = document.createElement('p');
      body.className = 'sh-support-admin__message-body';
      body.textContent = String(message.body || '');
      item.append(body);
      if (sender === 'customer' && message.reply_status === 'pending') {
        const state = document.createElement('p');
        state.className = 'sh-support-admin__message-state';
        state.textContent = 'A response is being prepared. If this remains pending, retry is available to the customer after the request times out.';
        item.append(state);
      }
      fragment.append(item);
    });
    messageList.replaceChildren(fragment);
    olderButton.hidden = !hasMore;
  }

  function renderEvents() {
    const events = Array.from(eventsMap.values()).sort((a, b) => Number(a.id) - Number(b.id));
    eventsNode.replaceChildren();
    if (events.length === 0) { eventsNode.hidden = true; return; }
    eventsNode.hidden = false;
    events.forEach((event) => {
      const row = document.createElement('p');
      row.className = 'sh-support-admin__event';
      const actor = event.actor_type === 'customer' ? 'Customer' : event.admin_name ? `Admin · ${event.admin_name}` : 'System';
      const title = document.createElement('strong');
      title.textContent = `${actor}: ${String(event.event || '').replaceAll('_', ' ')}`;
      row.append(title);
      if (event.reason) row.append(document.createTextNode(` — ${String(event.reason)}`));
      if (event.created_at) row.append(document.createTextNode(` · ${localTime(event.created_at)}`));
      eventsNode.append(row);
    });
  }

  function updateReplyCount() {
    replyCount.textContent = `${Array.from(replyInput.value).length.toLocaleString()} / 2,000`;
    replyInput.style.height = 'auto';
    replyInput.style.height = `${Math.max(replyInput.scrollHeight, 80)}px`;
  }

  async function loadOlder() {
    if (busy || !currentThread || !hasMore || olderCursor <= 0) return;
    busy = true;
    olderButton.disabled = true;
    const oldHeight = document.documentElement.scrollHeight;
    try {
      const data = await request('admin_thread', { params: { id: Number(currentThread.id), before_id: olderCursor } });
      (data.messages || []).forEach((message) => messageMap.set(Number(message.id), message));
      hasMore = Boolean(data.has_more);
      olderCursor = Number(data.older_cursor || 0);
      renderThread();
      window.scrollBy(0, document.documentElement.scrollHeight - oldHeight);
    } catch (error) { showError(error.message); }
    finally { busy = false; olderButton.disabled = false; renderThread(); }
  }

  async function sendReply(event) {
    event.preventDefault();
    if (busy || !currentThread) return;
    const body = replyInput.value.trim();
    if (!body) return;
    if (Array.from(body).length > 2000) { showError('Messages may contain at most 2,000 characters.'); return; }
    busy = true;
    replySend.disabled = true;
    clearError();
    try {
      await request('admin_reply', { method: 'POST', data: {
        id: Number(currentThread.id), body, client_id: makeUuid(), internal: replyKind.value === 'internal',
      } });
      replyInput.value = '';
      updateReplyCount();
      const conversationId = Number(currentThread.id);
      busy = false;
      await selectConversation(conversationId, false);
      await loadList(currentPage);
    } catch (error) { showError(error.message); }
    finally { busy = false; renderThread(); }
  }

  async function transition(action, extra = {}) {
    if (busy || !currentThread) return;
    busy = true;
    clearError();
    try {
      await request('admin_transition', { method: 'POST', data: { id: Number(currentThread.id), transition: action, ...extra } });
      const conversationId = Number(currentThread.id);
      busy = false;
      await selectConversation(conversationId, false);
      await loadList(currentPage);
    } catch (error) { showError(error.message); }
    finally { busy = false; if (currentThread) renderThread(); }
  }

  async function assignAgent() {
    const target = Number(agentSelect.value || 0);
    if (!target) { showError('Choose an authorized support agent first.'); return; }
    await transition('assign', { target_admin_id: target });
  }

  async function poll() {
    if (!currentThread || document.hidden || busy) return;
    const currentId = Number(currentThread.id);
    const messages = Array.from(messageMap.values());
    const events = Array.from(eventsMap.values());
    const afterMessageId = messages.reduce((max, item) => Math.max(max, Number(item.id)), 0);
    const afterEventId = events.reduce((max, item) => Math.max(max, Number(item.id)), 0);
    try {
      const data = await request('admin_poll', { params: { id: currentId, after_message_id: afterMessageId, after_event_id: afterEventId } });
      if (!data.conversation || Number(data.conversation.id) !== currentId) return;
      currentThread = { ...currentThread, ...data.conversation };
      (data.messages || []).forEach((message) => messageMap.set(Number(message.id), message));
      (data.events || []).forEach((item) => eventsMap.set(Number(item.id), item));
      renderThread();
      loadList(currentPage);
    } catch (_) { /* Preserve the current transcript if polling fails. */ }
  }

  function startPolling() {
    if (pollTimer) window.clearInterval(pollTimer);
    pollTimer = window.setInterval(poll, 12000);
  }

  $('[data-support-filter]').addEventListener('submit', (event) => {
    event.preventDefault();
    statusFilter = $('[data-filter-status]').value;
    queryFilter = $('[data-filter-query]').value.trim();
    currentPage = 1;
    const url = new URL(window.location.href);
    if (statusFilter) url.searchParams.set('status', statusFilter); else url.searchParams.delete('status');
    if (queryFilter) url.searchParams.set('q', queryFilter); else url.searchParams.delete('q');
    window.history.replaceState({}, '', url.toString());
    loadList(1);
  });
  pagePrev.addEventListener('click', () => { if (currentPage > 1) loadList(currentPage - 1); });
  pageNext.addEventListener('click', () => { if (currentPage < totalPages) loadList(currentPage + 1); });
  olderButton.addEventListener('click', loadOlder);
  replyForm.addEventListener('submit', sendReply);
  replyInput.addEventListener('input', updateReplyCount);
  replyKind.addEventListener('change', () => {
    const internal = replyKind.value === 'internal';
    replyLabel.textContent = internal ? 'Internal note — visible to support staff only' : 'Reply';
    replySend.lastChild && replySend.lastChild.nodeType === Node.TEXT_NODE
      ? replySend.lastChild.textContent = internal ? ' Save internal note' : ' Send reply' : null;
  });
  assignButton.addEventListener('click', assignAgent);
  unassignButton.addEventListener('click', () => transition('unassign'));
  takeoverButton.addEventListener('click', () => transition('takeover'));
  aiActionButton.addEventListener('click', () => transition(aiActionButton.dataset.transition || 'takeover'));
  resolveButton.addEventListener('click', () => transition(resolveButton.dataset.transition || 'resolve'));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

  updateReplyCount();
  loadList(1).then(() => {
    if (selectedId > 0) selectConversation(selectedId, false);
    startPolling();
  });
})();
