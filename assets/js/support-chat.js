/*
 * ShopHaat support chat widget.
 *
 * Plain fetch + bounded polling (5s, only while the panel is open). The
 * panel is part of the page flow — there is no inner scroll box; the page
 * itself scrolls through long conversations. Sends are idempotent: every
 * message carries a client_msg_id the server dedupes, so retries are safe.
 */
(function () {
  'use strict';

  var fab = document.getElementById('sh-schat-fab');
  var sheet = document.getElementById('sh-schat-sheet');
  if (!fab || !sheet) { return; }

  var cfg = { endpoint: '', max: 2000 };
  try { cfg = Object.assign(cfg, JSON.parse(sheet.getAttribute('data-config') || '{}')); } catch (e) {}
  if (!cfg.endpoint) { return; }

  var listEl = document.getElementById('sh-schat-messages');
  var suggestEl = document.getElementById('sh-schat-suggest');
  var form = document.getElementById('sh-schat-form');
  var input = document.getElementById('sh-schat-input');
  var sendBtn = document.getElementById('sh-schat-send');
  var statusEl = document.getElementById('sh-schat-status');
  var subEl = document.getElementById('sh-schat-sub');
  var badgeEl = document.getElementById('sh-schat-badge');
  var handoffBtn = document.getElementById('sh-schat-handoff');
  var defaultSub = subEl.textContent;

  var convId = 0, lastId = 0, started = false, isOpen = false, human = false;
  var pollTimer = null, seen = {}, unread = 0, sentOnce = false;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }

  function newClientId() {
    var s = 'm' + Date.now().toString(36);
    try {
      var b = new Uint8Array(6); window.crypto.getRandomValues(b);
      s += '-' + Array.from(b).map(function (x) { return x.toString(16).padStart(2, '0'); }).join('');
    } catch (e) { s += '-' + Math.floor(Math.random() * 1e9).toString(36); }
    return s;
  }

  function post(action, fields) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('csrf_token', window.SH_CSRF || '');
    Object.keys(fields || {}).forEach(function (k) { body.set(k, fields[k]); });
    return fetch(cfg.endpoint, {
      method: 'POST', body: body, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'bad_response' }; }).then(function (data) {
        if (!r.ok || !data.ok) { var err = new Error(data.error || ('HTTP ' + r.status)); err.code = data.error || ''; err.status = r.status; throw err; }
        return data;
      });
    });
  }

  function errorText(err) {
    switch (err && err.code) {
      case 'rate_limited': return 'Too many messages in a short time — please wait a moment. / অনুগ্রহ করে একটু অপেক্ষা করুন।';
      case 'too_long': return 'That message is too long. Please shorten it and send again.';
      case 'support_disabled': return 'Support chat is currently unavailable. Please try again later.';
      default: return 'Could not send right now. Check your connection and use Retry — your message is kept.';
    }
  }

  function setStatus(text, isError) {
    statusEl.textContent = text || '';
    statusEl.classList.toggle('is-error', !!isError);
  }

  function timeOf(at) {
    var d = new Date(String(at || '').replace(' ', 'T'));
    if (isNaN(d.getTime())) { return ''; }
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function isLinkable(line) {
    if (!line || /\s/.test(line)) { return false; }
    try {
      var u = new URL(line, window.location.href);
      return (u.protocol === 'http:' || u.protocol === 'https:') && u.origin === window.location.origin && /\.php(\?|$)/.test(u.pathname + u.search);
    } catch (e) { return false; }
  }

  function bodyNodes(body) {
    var frag = document.createDocumentFragment();
    String(body || '').split('\n').forEach(function (line, i) {
      if (i > 0) { frag.appendChild(document.createElement('br')); }
      var trimmed = line.trim();
      if (isLinkable(trimmed)) {
        var a = el('a', 'sh-schat-link');
        a.href = trimmed;
        a.textContent = /product\.php/.test(trimmed) ? 'View product' : trimmed;
        frag.appendChild(a);
      } else {
        frag.appendChild(document.createTextNode(line));
      }
    });
    return frag;
  }

  function renderMessage(m) {
    if (m.id && seen[m.id]) { return null; }
    if (m.id) { seen[m.id] = true; lastId = Math.max(lastId, m.id); }
    if (m.kind === 'notice') {
      var n = el('div', 'sh-schat-notice');
      n.appendChild(bodyNodes(m.body));
      listEl.appendChild(n);
      return n;
    }
    var wrap = el('div', 'sh-schat-msg is-' + m.kind);
    var who = m.kind === 'customer' ? 'You' : (m.kind === 'agent' ? 'Support agent' : (m.ai ? 'AI assistant' : 'Support'));
    var meta = el('div', 'sh-schat-meta');
    meta.appendChild(el('span', 'sh-schat-who', who));
    var t = timeOf(m.at);
    if (t) { meta.appendChild(el('time', 'sh-schat-time', t)); }
    var bubble = el('div', 'sh-schat-bubble');
    bubble.appendChild(bodyNodes(m.body));
    wrap.appendChild(meta); wrap.appendChild(bubble);
    listEl.appendChild(wrap);
    return wrap;
  }

  function renderAll(messages) {
    (messages || []).forEach(renderMessage);
  }

  function setBadge() {
    badgeEl.hidden = unread <= 0;
    badgeEl.textContent = String(Math.min(99, unread));
  }

  function applyConversation(data) {
    if (data.conversation) {
      convId = data.conversation.id || convId;
      human = !!data.conversation.human;
      subEl.textContent = human ? 'Our support team will reply here. The AI assistant is paused for this chat.' : defaultSub;
      handoffBtn.hidden = human;
    }
    if (Array.isArray(data.suggestions) && !sentOnce) {
      suggestEl.textContent = '';
      data.suggestions.forEach(function (s) {
        var b = el('button', 'sh-schat-chip', s);
        b.type = 'button';
        b.addEventListener('click', function () { input.value = s; form.requestSubmit(); });
        suggestEl.appendChild(b);
      });
    }
  }

  function keepInputVisible() {
    // The page scrolls (no inner chat scroller) — keep the composer in view.
    try { form.scrollIntoView({ block: 'nearest' }); } catch (e) {}
  }

  function showTyping() {
    hideTyping();
    var wrap = el('div', 'sh-schat-msg is-assistant is-typing');
    wrap.id = 'sh-schat-typing';
    var bubble = el('div', 'sh-schat-bubble');
    bubble.appendChild(el('span', 'sh-schat-dots', '···'));
    bubble.appendChild(el('span', 'sh-sr-only', 'Support is checking the store data…'));
    wrap.appendChild(bubble);
    listEl.appendChild(wrap);
    keepInputVisible();
  }

  function hideTyping() {
    var t = document.getElementById('sh-schat-typing');
    if (t) { t.remove(); }
  }

  function start() {
    if (started) { return; }
    started = true;
    setStatus('Opening chat…');
    post('start', {}).then(function (data) {
      applyConversation(data);
      renderAll(data.messages);
      setStatus('');
      keepInputVisible();
    }).catch(function (err) {
      started = false;
      setStatus(errorText(err), true);
    });
  }

  function poll() {
    if (!isOpen || !convId) { return; }
    post('poll', { conversation_id: convId, after_id: lastId }).then(function (data) {
      var had = (data.messages || []).length > 0;
      applyConversation(data);
      renderAll(data.messages);
      if (had && document.hidden) { unread += data.messages.length; setBadge(); }
      if (had && !document.hidden) { keepInputVisible(); }
    }).catch(function () { /* keep quiet; next poll retries */ });
  }

  function startPolling() {
    stopPolling();
    pollTimer = window.setInterval(poll, 5000);
  }

  function stopPolling() {
    if (pollTimer) { window.clearInterval(pollTimer); pollTimer = null; }
  }

  function open() {
    isOpen = true;
    unread = 0; setBadge();
    sheet.style.top = (window.scrollY + 10) + 'px';
    sheet.hidden = false;
    fab.setAttribute('aria-expanded', 'true');
    start();
    startPolling();
    keepInputVisible();
    input.focus();
  }

  function close() {
    isOpen = false;
    sheet.hidden = true;
    fab.setAttribute('aria-expanded', 'false');
    stopPolling();
    fab.focus();
  }

  function send(text, clientId, pendingEl) {
    sendBtn.disabled = true;
    showTyping();
    return post('send', {
      conversation_id: convId, message: text, client_msg_id: clientId, after_id: lastId
    }).then(function (data) {
      hideTyping();
      if (pendingEl) { pendingEl.remove(); }
      applyConversation(data);
      renderAll(data.messages);
      setStatus('');
      keepInputVisible();
    }).catch(function (err) {
      hideTyping();
      setStatus(errorText(err), true);
      if (pendingEl) {
        pendingEl.classList.add('is-failed');
        var bubble = pendingEl.querySelector('.sh-schat-bubble');
        if (bubble && !bubble.querySelector('.sh-schat-retry')) {
          var retry = el('button', 'sh-schat-retry', 'Retry');
          retry.type = 'button';
          retry.addEventListener('click', function () { send(text, clientId, pendingEl); });
          bubble.appendChild(document.createElement('br'));
          bubble.appendChild(retry);
        }
      }
    }).then(function () { sendBtn.disabled = false; });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || !convId) { return; }
    if (text.length > cfg.max) { setStatus('That message is too long (max ' + cfg.max + ' characters).', true); return; }
    sentOnce = true;
    suggestEl.textContent = '';
    var pending = renderMessage({ id: 0, kind: 'customer', body: text, at: new Date().toISOString() });
    if (pending) { pending.classList.add('is-pending'); }
    var clientId = newClientId();
    input.value = '';
    setStatus('');
    send(text, clientId, pending);
    input.focus();
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });

  document.getElementById('sh-schat-new').addEventListener('click', function () {
    setStatus('Starting a new conversation…');
    post('new', {}).then(function (data) {
      listEl.textContent = ''; seen = {}; lastId = 0; sentOnce = false;
      applyConversation(data);
      renderAll(data.messages);
      setStatus('');
      input.focus();
    }).catch(function (err) { setStatus(errorText(err), true); });
  });

  handoffBtn.addEventListener('click', function () {
    if (!convId) { return; }
    setStatus('Connecting you with the support team…');
    post('handoff', { conversation_id: convId, after_id: lastId }).then(function (data) {
      applyConversation(data);
      renderAll(data.messages);
      setStatus('');
      keepInputVisible();
    }).catch(function (err) { setStatus(errorText(err), true); });
  });

  fab.addEventListener('click', function () { isOpen ? close() : open(); });
  document.getElementById('sh-schat-close').addEventListener('click', close);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen) { close(); }
  });
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { poll(); }
  });
})();
