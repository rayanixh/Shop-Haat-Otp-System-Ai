(() => {
  'use strict';
  const root = document.querySelector('[data-support-chat]');
  if (!root) return;

  const api = root.dataset.api || '';
  const csrf = root.dataset.csrf || '';
  const $ = (selector) => root.querySelector(selector);
  const statusNode = $('[data-chat-status]');
  const aiNote = $('[data-ai-note]');
  const errorNode = $('[data-chat-error]');
  const emptyNode = $('[data-chat-empty]');
  const historyNode = $('[data-chat-history]');
  const messageList = $('[data-chat-messages]');
  const chatForm = $('[data-chat-form]');
  const chatInput = $('[data-chat-input]');
  const charCount = $('[data-char-count]');
  const languageSelect = $('[data-language]');
  const openButton = $('[data-chat-open]');
  const newButton = $('[data-chat-new]');
  const olderButton = $('[data-load-older]');
  const handoffButton = $('[data-handoff]');
  const deleteButton = $('[data-chat-delete]');
  const sendButton = $('[data-chat-send]');
  const threadState = $('[data-thread-state]');
  const threadTitle = $('[data-thread-title]');
  const disabledNode = $('[data-chat-disabled]');
  const handoffNote = $('[data-handoff-note]');
  const messageMap = new Map();
  const optimisticSends = new Map();

  const text = {
    en: {
      loading: 'Checking support status…', active: 'Conversation is open', pending: 'Human support was requested. The inbox is not a live-availability indicator.', assigned: 'A support agent has taken over this conversation.', resolved: 'This conversation is resolved.',
      start: 'Start a private conversation to ask about verified products, saved help articles or an order linked to your account/browser session.',
      unavailable: 'Customer chat is currently unavailable. You can still use the contact form below.', disabled: 'Chat is disabled. You can read this saved transcript, but cannot send new messages.',
      sending: 'Sending…', send: 'Send', preparing: 'Preparing a reply…', failed: 'A reply could not be prepared.', retrySend: 'Retry send', sendUnconfirmed: 'Delivery is unconfirmed. Retry safely to check whether this message was saved.', retry: 'Retry reply', paused: 'AI is paused for this conversation.',
      empty: 'No messages yet. Send a message when you are ready.', you: 'You', assistant: 'ShopHaat Assistant', agent: 'Support agent', system: 'Support update',
      open: 'Open', newChat: 'Start a new conversation', delete: 'Delete resolved conversation', deleteConfirm: 'Permanently delete this resolved support conversation and its messages?',
      deleted: 'The conversation was deleted.', handoff: 'Request human support', handoffSent: 'Your request has been recorded. This does not show whether staff are online or promise a reply time.',
      handoffDisabled: 'Human handoff is not enabled. Use the Contact Support form below.', handoffAlready: 'Human review has already been requested or an agent has taken over.',
      resolvedForm: 'This conversation is resolved. Start a new conversation to continue.', waiting: 'Your message is saved. A support agent has taken over; no immediate reply time is shown.',
      chars: 'characters', older: 'Load older messages', loaded: 'Older messages loaded.',
      requestError: 'The request could not be completed. Check your connection and retry.',
      tooLong: 'Messages may contain at most 2,000 characters.', messagePlaceholder: 'Write a message…', language: 'Language',
      status: 'Support chat', timestamp: 'ShopHaat Support', price: 'Price', stock: 'In stock', outOfStock: 'Out of stock',
      aiAvailable: 'AI replies are enabled with the selected provider and model. A live request can fail; the message is preserved and a store fallback is shown.',
      noAI: 'AI generation is unavailable. Saved FAQs, verified catalogue lookup and authorized order lookup may still help.',
    },
    bn: {
      loading: 'সাপোর্টের অবস্থা দেখা হচ্ছে…', active: 'কথোপকথন চালু আছে', pending: 'মানব-সাপোর্টের অনুরোধ করা হয়েছে। ইনবক্সে স্টাফ অনলাইনে আছেন কি না তা দেখা যায় না।', assigned: 'একজন সাপোর্ট এজেন্ট কথোপকথনটি নিয়েছেন।', resolved: 'এই কথোপকথনটি সমাধান করা হয়েছে।',
      start: 'যাচাইকৃত পণ্য, সংরক্ষিত সহায়তা-তথ্য বা আপনার অ্যাকাউন্ট/ব্রাউজার সেশনের অর্ডার নিয়ে ব্যক্তিগতভাবে লিখুন।',
      unavailable: 'এই মুহূর্তে কাস্টমার চ্যাট চালু নেই। নিচের যোগাযোগ ফর্ম ব্যবহার করতে পারেন।', disabled: 'চ্যাট বন্ধ আছে। সংরক্ষিত বার্তা দেখা যাবে, তবে নতুন বার্তা পাঠানো যাবে না।',
      sending: 'পাঠানো হচ্ছে…', send: 'পাঠান', preparing: 'উত্তর তৈরি হচ্ছে…', failed: 'উত্তর তৈরি করা যায়নি।', retrySend: 'আবার পাঠান', sendUnconfirmed: 'বার্তাটি পৌঁছেছে কি না নিশ্চিত নয়। একই পরিচয়ে নিরাপদে আবার চেষ্টা করে যাচাই করুন।', retry: 'আবার চেষ্টা', paused: 'এই কথোপকথনে AI বন্ধ আছে।',
      empty: 'এখনো কোনো বার্তা নেই। প্রস্তুত হলে বার্তা পাঠান।', you: 'আপনি', assistant: 'ShopHaat সহকারী', agent: 'সাপোর্ট এজেন্ট', system: 'সাপোর্ট আপডেট',
      open: 'চ্যাট শুরু করুন', newChat: 'নতুন কথোপকথন শুরু করুন', delete: 'সমাধান হওয়া কথোপকথন মুছুন', deleteConfirm: 'এই সমাধান হওয়া সাপোর্ট কথোপকথন ও বার্তাগুলো স্থায়ীভাবে মুছবেন?',
      deleted: 'কথোপকথনটি মুছে ফেলা হয়েছে।', handoff: 'মানব-সাপোর্টের অনুরোধ', handoffSent: 'আপনার অনুরোধটি সংরক্ষণ করা হয়েছে। স্টাফ অনলাইনে আছেন কি না বা উত্তর দেওয়ার সময় এটি জানায় না।',
      handoffDisabled: 'মানব-সাপোর্ট চালু নেই। নিচের যোগাযোগ ফর্মটি ব্যবহার করুন।', handoffAlready: 'মানব-পর্যালোচনার অনুরোধ করা হয়েছে অথবা একজন এজেন্ট কথোপকথনটি নিয়েছেন।',
      resolvedForm: 'এই কথোপকথন সমাধান করা হয়েছে। চালিয়ে যেতে নতুন কথোপকথন শুরু করুন।', waiting: 'আপনার বার্তা সংরক্ষণ করা হয়েছে। একজন সাপোর্ট এজেন্ট কথোপকথনটি নিয়েছেন; তাৎক্ষণিক উত্তরের সময় দেখানো হয় না।',
      chars: 'অক্ষর', older: 'আগের বার্তা দেখুন', loaded: 'আগের বার্তাগুলো দেখানো হয়েছে।',
      requestError: 'অনুরোধটি সম্পন্ন হয়নি। সংযোগ দেখে আবার চেষ্টা করুন।',
      tooLong: 'বার্তায় সর্বোচ্চ ২,০০০ অক্ষর লেখা যাবে।', messagePlaceholder: 'বার্তা লিখুন…', language: 'ভাষা',
      status: 'সাপোর্ট চ্যাট', timestamp: 'ShopHaat Support', price: 'দাম', stock: 'স্টকে আছে', outOfStock: 'স্টকে নেই',
      aiAvailable: 'নির্বাচিত AI প্রদানকারী ও মডেল দিয়ে উত্তর চালু আছে। সরাসরি অনুরোধ ব্যর্থ হতে পারে; বার্তা সংরক্ষিত থাকবে এবং স্টোরের বিকল্প উত্তর দেখানো হবে।',
      noAI: 'AI উত্তর তৈরি করা যাচ্ছে না। সংরক্ষিত FAQ, যাচাইকৃত পণ্য ও অনুমোদিত অর্ডারের তথ্য পাওয়া যেতে পারে।',
    },
    banglish: {
      loading: 'Support status check hocche…', active: 'Conversation open ache', pending: 'Human support request record hoyeche. Inbox staff online kina seta dekhay na.', assigned: 'Ekjon support agent ei conversation niyechen.', resolved: 'Ei conversation resolve kora hoyeche.',
      start: 'Verified product, saved help information, ba apnar account/browser session-er order niye private message korun.',
      unavailable: 'Ekhon customer chat available nei. Nicher contact form use korte paren.', disabled: 'Chat bondho. Saved message dekhte parben, kintu notun message pathate parben na.',
      sending: 'Pathano hocche…', send: 'Send korun', preparing: 'Reply prepare hocche…', failed: 'Reply prepare kora jayni.', retrySend: 'Abar pathan', sendUnconfirmed: 'Message save hoyeche kina confirm hoyni. Safe vabe abar try kore check korun.', retry: 'Abar try korun', paused: 'Ei conversation-e AI pause kora ache.',
      empty: 'Ekhono kono message nei. Ready hole message pathan.', you: 'Apni', assistant: 'ShopHaat Assistant', agent: 'Support agent', system: 'Support update',
      open: 'Chat shuru korun', newChat: 'Notun conversation shuru korun', delete: 'Resolved conversation delete korun', deleteConfirm: 'Ei resolved support conversation ebong message-gulo permanently delete korben?',
      deleted: 'Conversation delete kora hoyeche.', handoff: 'Human support request korun', handoffSent: 'Apnar request save hoyeche. Eta staff online kina ba reply time promise kore na.',
      handoffDisabled: 'Human handoff enabled nei. Nicher contact form use korun.', handoffAlready: 'Human review request kora hoyeche ba agent conversation niyechen.',
      resolvedForm: 'Ei conversation resolve kora. Continue korte notun conversation shuru korun.', waiting: 'Apnar message save hoyeche. Support agent niyechen; immediate reply time dekhano hoy na.',
      chars: 'character', older: 'Purono message load korun', loaded: 'Purono message load hoyeche.',
      requestError: 'Request complete hoyni. Connection check kore abar try korun.', tooLong: 'Message-e shorboccho 2,000 character thakte parbe.',
      messagePlaceholder: 'Message likhun…', language: 'Language', status: 'Support chat', timestamp: 'ShopHaat Support', price: 'Price', stock: 'Stock ache', outOfStock: 'Stock nei',
      aiAvailable: 'Selected provider o model diye AI reply on ache. Live request fail korte pare; message save thakbe ar store fallback dekhano hobe.',
      noAI: 'AI reply generate hocche na. Saved FAQ, verified catalog, authorized order information help korte pare.',
    },
  };

  let language = ['en', 'bn', 'banglish'].includes(localStorage.getItem('sh_support_language') || '')
    ? localStorage.getItem('sh_support_language') : 'en';
  let conversation = null;
  let support = null;
  let hasMore = false;
  let olderCursor = 0;
  let busy = false;
  let pendingSend = null;
  let pollTimer = null;
  let initialised = false;

  const tr = (key) => (text[language] && text[language][key]) || text.en[key] || key;
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

  function renderMessages() {
    const list = Array.from(messageMap.values()).sort((a, b) => {
      if (Boolean(a.optimistic) !== Boolean(b.optimistic)) return a.optimistic ? 1 : -1;
      if (a.optimistic && b.optimistic) return String(a.created_at || '').localeCompare(String(b.created_at || ''));
      return Number(a.id) - Number(b.id);
    });
    const fragment = document.createDocumentFragment();
    if (list.length === 0) {
      const empty = document.createElement('li');
      empty.className = 'sh-support-chat__message sh-support-chat__message--system';
      empty.textContent = tr('empty');
      fragment.append(empty);
    }
    list.forEach((message) => {
      if (message.is_internal || message.sender === 'internal_note') return;
      const item = document.createElement('li');
      item.className = `sh-support-chat__message sh-support-chat__message--${['customer', 'assistant', 'admin', 'system'].includes(message.sender) ? message.sender : 'system'}`;
      const meta = document.createElement('p');
      meta.className = 'sh-support-chat__message-meta';
      const sender = document.createElement('strong');
      sender.textContent = message.sender === 'customer' ? tr('you')
        : message.sender === 'assistant' ? tr('assistant')
          : message.sender === 'admin' ? tr('agent') : tr('system');
      meta.append(sender);
      if (message.source === 'fallback' && message.sender === 'assistant') {
        const source = document.createElement('span');
        source.textContent = language === 'bn' ? 'স্টোরের বিকল্প উত্তর' : language === 'banglish' ? 'Store fallback' : 'Store fallback';
        meta.append(source);
      }
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
        const state = document.createElement('p');
        state.className = 'sh-support-chat__message-state';
        state.textContent = tr('sending');
        item.append(state);
      } else if (message.sender === 'customer' && message.reply_status === 'pending') {
        const state = document.createElement('p');
        state.className = 'sh-support-chat__message-state';
        state.textContent = tr('preparing');
        item.append(state);
      } else if (message.sender === 'customer' && message.reply_status === 'failed') {
        const state = document.createElement('p');
        state.className = 'sh-support-chat__message-state';
        state.textContent = tr('failed');
        item.append(state);
        if (support && support.enabled && conversation && !conversation.ai_paused && conversation.status !== 'resolved') {
          const retry = document.createElement('button');
          retry.type = 'button';
          retry.className = 'sh-btn sh-btn--sm sh-btn--ghost';
          retry.textContent = tr('retry');
          retry.addEventListener('click', () => retryMessage(Number(message.id), retry));
          item.append(retry);
        }
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
    localStorage.setItem('sh_support_language', next);
    languageSelect.value = next;
    chatInput.placeholder = tr('messagePlaceholder');
    $('[data-empty-copy]').textContent = tr('start');
    openButton.textContent = tr('open');
    newButton.textContent = tr('newChat');
    olderButton.textContent = tr('older');
    handoffButton.textContent = tr('handoff');
    deleteButton.textContent = tr('delete');
    sendButton.lastChild && sendButton.lastChild.nodeType === Node.TEXT_NODE ? sendButton.lastChild.textContent = ` ${tr('send')}` : null;
    updateCharCount();
    renderMessages();
    updateView();
  }

  function updateView() {
    const enabled = support ? support.enabled !== false : true;
    const isResolved = conversation && conversation.status === 'resolved';
    const hasConversation = Boolean(conversation && Number(conversation.id) > 0);
    const canSend = enabled && hasConversation && !isResolved;
    const handoffEnabled = support && support.handoff_enabled;
    const needsHuman = hasConversation && (conversation.status === 'pending' || Number(conversation.assigned_admin_id || 0) > 0 || conversation.ai_paused);

    statusNode.textContent = support
      ? (support.ai_available ? tr('aiAvailable') : tr('noAI'))
      : tr('loading');
    aiNote.textContent = support && support.ai_message ? String(support.ai_message) : '';

    emptyNode.hidden = hasConversation;
    historyNode.hidden = !hasConversation;
    openButton.hidden = !enabled;
    if (!hasConversation) {
      $('[data-empty-copy]').textContent = enabled ? tr('start') : tr('unavailable');
    }
    if (hasConversation) {
      threadTitle.textContent = conversation.subject || `${tr('status')} #${conversation.id}`;
      threadState.textContent = conversation.status === 'resolved' ? tr('resolved')
        : conversation.status === 'pending' ? tr('pending')
          : conversation.status === 'assigned' || Number(conversation.assigned_admin_id || 0) > 0 ? tr('assigned') : tr('active');
      newButton.hidden = !isResolved || !enabled;
      deleteButton.hidden = !isResolved;
      olderButton.hidden = !hasMore;
      handoffButton.hidden = !enabled || !handoffEnabled || isResolved || needsHuman;
      handoffNote.textContent = !handoffEnabled ? tr('handoffDisabled') : needsHuman ? tr('handoffAlready') : '';
      chatForm.hidden = !canSend;
      disabledNode.hidden = canSend;
      disabledNode.textContent = isResolved ? tr('resolvedForm') : !enabled ? tr('disabled') : needsHuman ? tr('waiting') : '';
      chatInput.placeholder = tr('messagePlaceholder');
      sendButton.disabled = busy;
      sendButton.lastChild && sendButton.lastChild.nodeType === Node.TEXT_NODE ? sendButton.lastChild.textContent = ` ${busy ? tr('sending') : tr('send')}` : null;
    } else {
      chatForm.hidden = true;
      disabledNode.hidden = true;
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
    (snapshot.messages || []).forEach((message) => {
      messageMap.set(String(message.id), message);
      const clientId = String(message.client_message_id || '');
      if (message.sender === 'customer' && clientId && optimisticSends.has(clientId)) {
        const pending = optimisticSends.get(clientId);
        messageMap.delete(pending.tempKey);
        optimisticSends.delete(clientId);
        if (pendingSend && pendingSend.id === clientId) pendingSend = null;
      }
    });
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
    const payload = await request('state', { params: { language } });
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
        showError(tr('requestError'));
      }
    } catch (error) {
      renderOptimisticSend(send, 'send_failed');
      showError(error.message || tr('requestError'));
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
      emptyNode.hidden = false;
      showError(tr('deleted'));
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
      const payload = await request('state', { params: { language } });
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

  languageSelect.addEventListener('change', () => {
    setLanguage(languageSelect.value);
    if (!conversation) loadState().catch((error) => showError(error.message || tr('requestError')));
  });
  openButton.addEventListener('click', openConversation);
  newButton.addEventListener('click', openConversation);
  chatForm.addEventListener('submit', sendMessage);
  olderButton.addEventListener('click', loadOlder);
  handoffButton.addEventListener('click', requestHandoff);
  deleteButton.addEventListener('click', deleteConversation);
  chatInput.addEventListener('input', updateCharCount);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

  setLanguage(language);
  updateCharCount();
  statusNode.textContent = tr('loading');
  loadState().then(() => {
    initialised = true;
    startPolling();
  }).catch((error) => {
    showError(error.message || tr('requestError'));
    support = { enabled: false, ai_available: false, handoff_enabled: false };
    updateView();
  });
})();
