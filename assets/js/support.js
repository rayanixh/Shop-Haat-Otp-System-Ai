(() => {
  'use strict';
  const root = document.querySelector('[data-support-chat]');
  if (!root) return;

  const api = root.dataset.api || '';
  const csrf = root.dataset.csrf || '';
  const $ = (selector) => root.querySelector(selector);
  const errorNode = $('[data-chat-error]');
  const emptyNode = $('[data-chat-empty]');
  const historyNode = $('[data-chat-history]');
  const messageList = $('[data-chat-messages]');
  const chatForm = $('[data-chat-form]');
  const chatInput = $('[data-chat-input]');
  const charCount = $('[data-char-count]');
  const openButton = $('[data-chat-open]');
  const newButton = $('[data-chat-new]');
  const olderButton = $('[data-load-older]');
  const handoffButton = $('[data-handoff]');
  const deleteButton = $('[data-chat-delete]');
  const sendButton = $('[data-chat-send]');
  const threadState = $('[data-thread-state]');
  const threadTitle = $('[data-thread-title]');
  const disabledNode = $('[data-chat-disabled]');
  const actionsNode = $('[data-chat-actions]');
  const unavailableNode = $('[data-chat-unavailable]');
  const messageMap = new Map();
  const optimisticSends = new Map();

  const text = {
    en: {
      active: 'Conversation open', pending: 'Human support requested', assigned: 'A support agent has taken over', resolved: 'Conversation resolved',
      unavailable: 'Chat is temporarily unavailable.', disabled: 'Chat is unavailable.',
      sending: 'Replying…', send: 'Send', preparing: 'Replying…', failed: "Sorry, I couldn't reply right now. Please try again.",
      retrySend: 'Retry send', sendUnconfirmed: 'Message not confirmed.', retry: 'Retry',
      you: 'You', assistant: 'ShopHaat Assistant', agent: 'Support agent', system: 'Support update',
      conversation: 'Conversation', newChat: 'Start a new conversation', delete: 'Delete resolved conversation',
      deleteConfirm: 'Permanently delete this resolved support conversation and its messages?',
      handoff: 'Request human support', resolvedForm: 'This conversation is resolved.',
      waiting: 'Your message is saved for human support.', chars: 'characters', older: 'Load older messages',
      requestError: 'Sorry, something went wrong. Please try again.', tooLong: 'Messages may contain at most 2,000 characters.',
      messagePlaceholder: 'Write a message…', price: 'Price', stock: 'In stock', outOfStock: 'Out of stock',
      open: 'Start a chat',
    },
    bn: {
      active: 'কথোপকথন চালু', pending: 'মানব-সাপোর্টের অনুরোধ করা হয়েছে', assigned: 'একজন সাপোর্ট এজেন্ট কথোপকথনটি নিয়েছেন', resolved: 'কথোপকথন সমাধান হয়েছে',
      unavailable: 'এই মুহূর্তে চ্যাট চালু নেই।', disabled: 'চ্যাট চালু নেই।',
      sending: 'উত্তর দেওয়া হচ্ছে…', send: 'পাঠান', preparing: 'উত্তর দেওয়া হচ্ছে…', failed: 'দুঃখিত, এই মুহূর্তে উত্তর দিতে পারছি না। অনুগ্রহ করে আবার চেষ্টা করুন।',
      retrySend: 'আবার পাঠান', sendUnconfirmed: 'বার্তাটি নিশ্চিত করা যায়নি।', retry: 'আবার চেষ্টা',
      you: 'আপনি', assistant: 'ShopHaat সহকারী', agent: 'সাপোর্ট এজেন্ট', system: 'সাপোর্ট আপডেট',
      conversation: 'কথোপকথন', newChat: 'নতুন কথোপকথন শুরু করুন', delete: 'সমাধান হওয়া কথোপকথন মুছুন',
      deleteConfirm: 'এই সমাধান হওয়া সাপোর্ট কথোপকথন ও বার্তাগুলো স্থায়ীভাবে মুছবেন?',
      handoff: 'মানব-সাপোর্ট চান', resolvedForm: 'এই কথোপকথন সমাধান হয়েছে।',
      waiting: 'আপনার বার্তাটি মানব-সাপোর্টের জন্য সংরক্ষিত হয়েছে।', chars: 'অক্ষর', older: 'আগের বার্তা দেখুন',
      requestError: 'দুঃখিত, একটি সমস্যা হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।', tooLong: 'বার্তায় সর্বোচ্চ ২,০০০ অক্ষর লেখা যাবে।',
      messagePlaceholder: 'বার্তা লিখুন…', price: 'দাম', stock: 'স্টকে আছে', outOfStock: 'স্টকে নেই',
      open: 'চ্যাট শুরু করুন',
    },
    banglish: {
      active: 'Conversation open', pending: 'Human support request kora hoyeche', assigned: 'Ekjon support agent conversation niyechen', resolved: 'Conversation resolve hoyeche',
      unavailable: 'Ekhon chat available nei.', disabled: 'Chat available nei.',
      sending: 'Reply hocche…', send: 'Send', preparing: 'Reply hocche…', failed: 'Sorry, ekhon reply dite parchi na. Please abar try korun.',
      retrySend: 'Abar pathan', sendUnconfirmed: 'Message confirm hoyni.', retry: 'Abar try korun',
      you: 'Apni', assistant: 'ShopHaat Assistant', agent: 'Support agent', system: 'Support update',
      conversation: 'Conversation', newChat: 'Notun conversation shuru korun', delete: 'Resolved conversation delete korun',
      deleteConfirm: 'Ei resolved support conversation ebong message-gulo permanently delete korben?',
      handoff: 'Human support chan', resolvedForm: 'Ei conversation resolve hoyeche.',
      waiting: 'Apnar message human support-er jonno save hoyeche.', chars: 'character', older: 'Purono message load korun',
      requestError: 'Sorry, ekta problem hoyeche. Please abar try korun.', tooLong: 'Message-e shorboccho 2,000 character thakte parbe.',
      messagePlaceholder: 'Message likhun…', price: 'Price', stock: 'Stock ache', outOfStock: 'Stock nei',
      open: 'Chat shuru korun',
    },
  };

  let language = 'en';
  let conversation = null;
  let support = null;
  let hasMore = false;
  let olderCursor = 0;
  let busy = false;
  let pendingSend = null;
  let pollTimer = null;

  const tr = (key) => (text[language] && text[language][key]) || text.en[key] || key;

  function detectLanguage(value) {
    const sample = String(value || '');
    if ([...sample].some((character) => {
      const codepoint = character.codePointAt(0);
      return codepoint >= 0x0980 && codepoint <= 0x09FF;
    })) return 'bn';
    const words = sample.toLowerCase().match(/[a-z]+/g) || [];
    const banglishTerms = new Set(['ami', 'amar', 'apni', 'apnar', 'kemon', 'koto', 'ache', 'ase', 'lagbe', 'chai', 'pabo', 'hobe', 'korbo', 'korben', 'den', 'diben', 'niben', 'eta', 'oi', 'ki', 'naaki', 'bolen', 'janan', 'bhai', 'vai']);
    if (words.some((word) => banglishTerms.has(word)) || /\b(?:order\s+ta|dam\s+ta|price\s+koto)\b/i.test(sample)) return 'banglish';
    return 'en';
  }
  const uuid = () => {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const h = Array.from(bytes, (n) => n.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
  };

  function showError(message) {
    errorNode.textContent = message || tr('requestError');
    errorNode.hidden = false;
  }
  function clearError() { errorNode.textContent = ''; errorNode.hidden = true; }

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
    let payload;
    try { payload = await response.json(); }
    catch (_) { throw new Error(tr('requestError')); }
    if (!response.ok || payload.success === false) {
      if (payload.login_url) { showError(payload.error || tr('requestError')); }
      throw new Error(payload.error || tr('requestError'));
    }
    if (payload.ok === false) throw new Error(payload.error || tr('requestError'));
    return payload;
  }

  function localTime(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T') + 'Z');
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat(language === 'bn' ? 'bn-BD' : 'en-BD', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  }

  function safeSameOrigin(value) {
    try {
      const url = new URL(String(value), window.location.href);
      return url.origin === window.location.origin ? url.href : '';
    } catch (_) { return ''; }
  }

  function appendProducts(parent, products) {
    if (!Array.isArray(products) || products.length === 0) return;
    const group = document.createElement('div');
    group.className = 'sh-support-chat__product-list';
    products.slice(0, 5).forEach((product) => {
      const href = safeSameOrigin(product.url);
      if (!href) return;
      const link = document.createElement('a');
      link.className = 'sh-support-chat__product';
      link.href = href;
      const imageSrc = safeSameOrigin(product.image);
      if (imageSrc) {
        const image = document.createElement('img');
        image.src = imageSrc;
        image.alt = '';
        image.loading = 'lazy';
        link.append(image);
      }
      const details = document.createElement('span');
      details.className = 'sh-support-chat__product-info';
      const name = document.createElement('span');
      name.className = 'sh-support-chat__product-name';
      name.textContent = String(product.name || '');
      details.append(name);
      const price = document.createElement('span');
      price.className = 'sh-support-chat__product-price';
      price.textContent = `${tr('price')}: ${String(product.price || '')}`;
      details.append(price);
      const stock = document.createElement('span');
      stock.className = 'sh-support-chat__product-stock';
      stock.textContent = Number(product.stock) > 0 ? `${tr('stock')}: ${Number(product.stock)}` : tr('outOfStock');
      details.append(stock);
      link.append(details);
      group.append(link);
    });
    if (group.childElementCount > 0) parent.append(group);
  }

  function replyRequestIsStale(message) {
    const value = String(message.reply_started_at || '');
    if (!value) return false;
    const startedAt = Date.parse(value.replace(' ', 'T') + 'Z');
    return Number.isFinite(startedAt) && Date.now() - startedAt >= 180000;
  }

  function appendReplyRetry(parent, message) {
    if (!support || !support.enabled || !conversation || conversation.ai_paused
      || Number(conversation.assigned_admin_id || 0) > 0 || conversation.status === 'resolved') return;
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'sh-btn sh-btn--sm sh-btn--ghost';
    retry.textContent = tr('retry');
    retry.addEventListener('click', () => retryMessage(Number(message.id), retry));
    parent.append(retry);
  }

  function renderMessages() {
    const list = Array.from(messageMap.values()).sort((a, b) => {
      if (Boolean(a.optimistic) !== Boolean(b.optimistic)) return a.optimistic ? 1 : -1;
      if (a.optimistic && b.optimistic) return String(a.created_at || '').localeCompare(String(b.created_at || ''));
      return Number(a.id) - Number(b.id);
    });
    const fragment = document.createDocumentFragment();
    list.forEach((message) => {
      if (message.is_internal || message.sender === 'internal_note') return;
      const item = document.createElement('li');
      const displaySender = message.sender === 'assistant' && message.source === 'fallback' ? 'system' : message.sender;
      item.className = `sh-support-chat__message sh-support-chat__message--${['customer', 'assistant', 'admin', 'system'].includes(displaySender) ? displaySender : 'system'}`;
      const meta = document.createElement('p');
      meta.className = 'sh-support-chat__message-meta';
      const sender = document.createElement('strong');
      sender.textContent = displaySender === 'customer' ? tr('you')
        : displaySender === 'assistant' ? tr('assistant')
          : displaySender === 'admin' ? tr('agent') : tr('system');
      meta.append(sender);
      const time = document.createElement('time');
      time.dateTime = String(message.created_at || '');
      time.textContent = localTime(message.created_at);
      meta.append(time);
      item.append(meta);
      const body = document.createElement('p');
      body.className = 'sh-support-chat__message-body';
      body.textContent = String(message.body || '');
      item.append(body);
      appendProducts(item, message.products);
      if (message.sender === 'customer' && message.optimistic && message.reply_status === 'send_failed') {
        const state = document.createElement('p');
        state.className = 'sh-support-chat__message-state';
        state.textContent = tr('sendUnconfirmed');
        item.append(state);
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'sh-btn sh-btn--sm sh-btn--ghost';
        retry.textContent = tr('retrySend');
        retry.addEventListener('click', () => retryOptimisticSend(String(message.client_message_id || ''), retry));
        item.append(retry);
      } else if (message.sender === 'customer' && message.optimistic) {
        appendMessageState(item, 'sending', true);
      } else if (message.sender === 'customer' && message.reply_status === 'pending') {
        if (replyRequestIsStale(message)) {
          appendMessageState(item, 'failed');
          appendReplyRetry(item, message);
        } else {
          appendMessageState(item, 'preparing', true);
        }
      } else if (message.sender === 'customer' && message.reply_status === 'failed') {
        appendMessageState(item, 'failed');
        appendReplyRetry(item, message);
      }
      fragment.append(item);
    });
    messageList.replaceChildren(fragment);
  }

  function updateCharCount() {
    const n = Array.from(chatInput.value).length;
    charCount.textContent = `${n.toLocaleString(language === 'bn' ? 'bn-BD' : 'en-BD')} / 2,000 ${tr('chars')}`;
    chatInput.style.height = 'auto';
    chatInput.style.height = `${Math.max(chatInput.scrollHeight, 70)}px`;
  }

  function setLanguage(next) {
    if (!['en', 'bn', 'banglish'].includes(next)) return;
    language = next;
    chatInput.placeholder = tr('messagePlaceholder');
    openButton.textContent = tr('open');
    newButton.textContent = tr('newChat');
    olderButton.textContent = tr('older');
    handoffButton.textContent = tr('handoff');
    deleteButton.textContent = tr('delete');
    if (sendButton.lastChild && sendButton.lastChild.nodeType === Node.TEXT_NODE) {
      sendButton.lastChild.textContent = ` ${busy ? tr('sending') : tr('send')}`;
    }
    updateCharCount();
  }

  function appendMessageState(parent, key, loading = false) {
    const state = document.createElement('p');
    state.className = 'sh-support-chat__message-state';
    state.setAttribute('role', 'status');
    if (loading) {
      const spinner = document.createElement('span');
      spinner.className = 'sh-support-chat__spinner';
      spinner.setAttribute('aria-hidden', 'true');
      state.append(spinner, document.createTextNode(tr(key)));
    } else {
      state.textContent = tr(key);
    }
    parent.append(state);
  }

  function updateView() {
    const enabled = support ? support.enabled !== false : true;
    const isResolved = conversation && conversation.status === 'resolved';
    const hasConversation = Boolean(conversation && Number(conversation.id) > 0);
    const canSend = enabled && hasConversation && !isResolved;
    const handoffEnabled = Boolean(support && support.handoff_enabled);
    const needsHuman = Boolean(hasConversation && (conversation.status === 'pending'
      || Number(conversation.assigned_admin_id || 0) > 0 || conversation.ai_paused));

    emptyNode.hidden = hasConversation;
    historyNode.hidden = !hasConversation;
    openButton.hidden = !enabled;
    unavailableNode.hidden = enabled || hasConversation;
    unavailableNode.textContent = tr('unavailable');

    if (hasConversation) {
      threadTitle.textContent = conversation.subject || `${tr('conversation')} #${conversation.id}`;
      threadState.textContent = conversation.status === 'resolved' ? tr('resolved')
        : conversation.status === 'pending' ? tr('pending')
          : conversation.status === 'assigned' || Number(conversation.assigned_admin_id || 0) > 0 ? tr('assigned') : tr('active');
      newButton.hidden = !isResolved || !enabled;
      deleteButton.hidden = !isResolved;
      olderButton.hidden = !hasMore;
      handoffButton.hidden = !enabled || !handoffEnabled || isResolved || needsHuman;
      actionsNode.hidden = handoffButton.hidden && deleteButton.hidden;
      chatForm.hidden = !canSend;
      disabledNode.hidden = canSend;
      disabledNode.textContent = isResolved ? tr('resolvedForm') : !enabled ? tr('disabled') : needsHuman ? tr('waiting') : '';
      chatInput.placeholder = tr('messagePlaceholder');
      sendButton.disabled = busy;
      if (sendButton.lastChild && sendButton.lastChild.nodeType === Node.TEXT_NODE) {
        sendButton.lastChild.textContent = ` ${busy ? tr('sending') : tr('send')}`;
      }
    } else {
      chatForm.hidden = true;
      disabledNode.hidden = true;
      actionsNode.hidden = true;
      handoffButton.hidden = true;
      deleteButton.hidden = true;
      newButton.hidden = true;
      olderButton.hidden = true;
    }
    renderMessages();
  }

  function acceptSnapshot(snapshot, options = {}) {
    if (!snapshot || snapshot.ok === false || snapshot.success === false) throw new Error(snapshot && snapshot.error ? snapshot.error : tr('requestError'));
    support = snapshot.support || support;
    const nextConversation = snapshot.conversation || null;
    const previousId = Number((conversation || {}).id || 0);
    const nextId = Number((nextConversation || {}).id || 0);
    if (previousId !== nextId) {
      messageMap.clear();
      hasMore = false;
      olderCursor = 0;
      pendingSend = null;
      optimisticSends.clear();
    }
    conversation = nextConversation;
    const snapshotMessages = snapshot.messages || [];
    snapshotMessages.forEach((message) => {
      messageMap.set(String(message.id), message);
      const clientId = String(message.client_message_id || '');
      if (message.sender === 'customer' && clientId && optimisticSends.has(clientId)) {
        const pending = optimisticSends.get(clientId);
        messageMap.delete(pending.tempKey);
        optimisticSends.delete(clientId);
        if (pendingSend && pendingSend.id === clientId) pendingSend = null;
      }
    });
    const latestCustomer = [...snapshotMessages].reverse().find((message) => message.sender === 'customer' && !message.is_internal);
    setLanguage(latestCustomer ? detectLanguage(latestCustomer.body) : (nextConversation ? language : 'en'));
    if (!options.keepCursor) olderCursor = Number(snapshot.older_cursor || 0);
    hasMore = Boolean(snapshot.has_more || (options.keepCursor && hasMore));
    if (nextConversation === null) {
      messageMap.clear();
      optimisticSends.clear();
      pendingSend = null;
    }
    clearError();
    updateView();
  }

  async function loadState() {
    const payload = await request('state');
    acceptSnapshot(payload);
  }

  async function openConversation() {
    if (busy) return;
    busy = true;
    openButton.disabled = true;
    newButton.disabled = true;
    clearError();
    try {
      const payload = await request('open', { method: 'POST', data: {} });
      acceptSnapshot(payload);
      if (conversation) chatInput.focus();
      startPolling();
    } catch (error) { showError(error.message || tr('requestError')); }
    finally { busy = false; openButton.disabled = false; newButton.disabled = false; updateView(); }
  }

  function renderOptimisticSend(send, state = 'sending') {
    messageMap.set(send.tempKey, {
      id: send.tempKey,
      optimistic: true,
      sender: 'customer',
      source: 'manual',
      body: send.body,
      client_message_id: send.id,
      reply_status: state,
      created_at: send.createdAt,
      products: [],
    });
    renderMessages();
    const last = messageList.lastElementChild;
    if (last && typeof last.scrollIntoView === 'function') last.scrollIntoView({ block: 'end', behavior: 'smooth' });
  }

  async function transmitOptimisticSend(send) {
    if (busy || !conversation || !send) return;
    busy = true;
    renderOptimisticSend(send, 'sending');
    clearError();
    updateView();
    try {
      const payload = await request('send', {
        method: 'POST',
        data: { id: Number(conversation.id), body: send.body, client_id: send.id },
      });
      const snapshot = payload.snapshot || payload;
      const confirmed = Array.isArray(snapshot.messages)
        && snapshot.messages.some((message) => String(message.client_message_id || '') === send.id);
      acceptSnapshot(snapshot);
      if (confirmed) {
        if (chatInput.value.trim() === send.body) {
          chatInput.value = '';
          updateCharCount();
        }
        const last = messageList.lastElementChild;
        if (last && typeof last.scrollIntoView === 'function') last.scrollIntoView({ block: 'end', behavior: 'smooth' });
      } else {
        renderOptimisticSend(send, 'send_failed');
      }
    } catch (_) {
      // The message row carries the recoverable state and retry action; avoid
      // duplicating the same transport failure in a page-level banner.
      renderOptimisticSend(send, 'send_failed');
    } finally {
      busy = false;
      updateView();
    }
  }

  async function sendMessage(event) {
    event.preventDefault();
    if (busy || !conversation) return;
    const body = chatInput.value.trim();
    if (!body) return;
    setLanguage(detectLanguage(body));
    if (Array.from(body).length > 2000) { showError(tr('tooLong')); return; }
    if (!pendingSend || pendingSend.body !== body) {
      const id = uuid();
      pendingSend = {
        id,
        body,
        tempKey: `optimistic:${id}`,
        createdAt: new Date().toISOString().replace('T', ' ').replace(/\.\d{3}Z$/, ''),
      };
      optimisticSends.set(id, pendingSend);
    }
    await transmitOptimisticSend(pendingSend);
  }

  async function retryOptimisticSend(clientId, button) {
    const send = optimisticSends.get(String(clientId || ''));
    if (!send || busy || !conversation) return;
    if (button) button.disabled = true;
    await transmitOptimisticSend(send);
  }

  async function retryMessage(messageId, button) {
    if (busy || !conversation) return;
    busy = true;
    if (button) button.disabled = true;
    clearError();
    updateView();
    try {
      const payload = await request('retry', { method: 'POST', data: { id: Number(conversation.id), message_id: messageId } });
      acceptSnapshot(payload.snapshot || payload);
    } catch (error) { showError(error.message || tr('requestError')); }
    finally { busy = false; updateView(); }
  }

  async function requestHandoff() {
    if (busy || !conversation) return;
    busy = true;
    handoffButton.disabled = true;
    clearError();
    try {
      const payload = await request('handoff', { method: 'POST', data: { id: Number(conversation.id), client_id: uuid() } });
      acceptSnapshot(payload.snapshot || payload);
    } catch (error) { showError(error.message || tr('requestError')); }
    finally { busy = false; updateView(); }
  }

  async function deleteConversation() {
    if (busy || !conversation || !window.confirm(tr('deleteConfirm'))) return;
    busy = true;
    deleteButton.disabled = true;
    try {
      await request('delete', { method: 'POST', data: { id: Number(conversation.id) } });
      conversation = null;
      messageMap.clear();
      hasMore = false;
      olderCursor = 0;
      await loadState();
    } catch (error) { showError(error.message || tr('requestError')); }
    finally { busy = false; updateView(); }
  }

  async function loadOlder() {
    if (busy || !conversation || !hasMore || olderCursor <= 0) return;
    busy = true;
    olderButton.disabled = true;
    const oldHeight = document.documentElement.scrollHeight;
    try {
      const payload = await request('messages', { params: { id: Number(conversation.id), before_id: olderCursor } });
      (payload.messages || []).forEach((message) => messageMap.set(String(message.id), message));
      hasMore = Boolean(payload.has_more);
      olderCursor = Number(payload.older_cursor || 0);
      updateView();
      const newHeight = document.documentElement.scrollHeight;
      window.scrollBy(0, newHeight - oldHeight);
    } catch (error) { showError(error.message || tr('requestError')); }
    finally { busy = false; olderButton.disabled = false; updateView(); }
  }

  async function poll() {
    if (!conversation || document.hidden || busy) return;
    try {
      const payload = await request('state');
      if (payload.conversation && Number(payload.conversation.id) === Number(conversation.id)) {
        acceptSnapshot(payload, { keepCursor: true });
      } else if (!payload.conversation) {
        conversation = null;
        messageMap.clear();
        acceptSnapshot(payload);
      }
    } catch (_) { /* Keep the already-rendered transcript available offline. */ }
  }

  function startPolling() {
    if (pollTimer) window.clearInterval(pollTimer);
    pollTimer = window.setInterval(poll, 15000);
  }

  openButton.addEventListener('click', openConversation);
  newButton.addEventListener('click', openConversation);
  chatForm.addEventListener('submit', sendMessage);
  olderButton.addEventListener('click', loadOlder);
  handoffButton.addEventListener('click', requestHandoff);
  deleteButton.addEventListener('click', deleteConversation);
  chatInput.addEventListener('input', updateCharCount);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

  setLanguage('en');
  updateCharCount();
  loadState().then(() => {
    startPolling();
  }).catch((error) => {
    showError(error.message || tr('requestError'));
    support = { enabled: false, handoff_enabled: false };
    updateView();
  });
})();
