/*
 * Support conversation live updates (admin).
 * Bounded polling against the same page (format=json) — no WebSocket, and it
 * pauses automatically when the tab is hidden. New messages are appended in
 * page flow (no inner scroll box).
 */
(function () {
  'use strict';
  var thread = document.getElementById('sh-sup-thread');
  if (!thread) { return; }
  var convId = thread.getAttribute('data-conv');
  var after = parseInt(thread.getAttribute('data-after') || '0', 10) || 0;
  var note = document.getElementById('sh-sup-pollnote');
  var stateEl = document.getElementById('sh-sup-state');
  var timer = null;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function fmtTime(at) {
    var d = new Date(String(at || '').replace(' ', 'T'));
    if (isNaN(d.getTime())) { return esc(at); }
    return d.toLocaleDateString([], { day: '2-digit', month: 'short' }) + ', ' +
      d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function append(m) {
    var cls = m.visibility === 'internal' ? 'is-internal'
      : m.sender === 'customer' ? 'is-customer'
      : m.sender === 'admin' ? 'is-admin'
      : m.sender === 'ai' ? 'is-ai' : 'is-system';
    var div = document.createElement('div');
    div.className = 'sh-supmsg ' + cls;
    var meta = '<div class="sh-supmeta"><strong>' + esc(m.who) + '</strong>'
      + (m.visibility === 'internal' ? ' · Internal note (customer cannot see)' : '')
      + ' · ' + esc(fmtTime(m.at))
      + (m.meta ? '<br>' + esc(m.meta) : '') + '</div>';
    div.innerHTML = meta + '<div class="sh-supbody">' + esc(m.body) + '</div>';
    thread.appendChild(div);
    after = Math.max(after, m.id || 0);
  }

  function poll() {
    if (document.hidden) { return; }
    fetch('support-conversation.php?format=json&id=' + encodeURIComponent(convId) + '&after_id=' + after, {
      credentials: 'same-origin', headers: { 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (!data || !data.ok) { return; }
      (data.messages || []).forEach(append);
      if (data.state && stateEl) {
        stateEl.innerHTML = (data.state.ai_paused
          ? '<span class="sh-badge sh-badge--warn">Human handling · AI paused</span>'
          : '<span class="sh-badge sh-badge--ok">AI active</span>')
          + ' <span class="sh-badge">' + esc(data.state.status.charAt(0).toUpperCase() + data.state.status.slice(1)) + '</span>';
      }
    }).catch(function () { /* next poll retries silently */ });
  }

  timer = window.setInterval(poll, 6000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
  if (note) { note.textContent = 'Live: checking for new customer messages every few seconds while this tab is visible.'; }
})();
