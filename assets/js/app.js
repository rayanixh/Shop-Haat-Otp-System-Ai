/* ==========================================================================
   ShopHaat — front-end behaviour (vanilla JS, no dependencies)
   ========================================================================== */
(function () {
  'use strict';

  var BASE = (window.SH_BASE || '/');
  var CSRF = (window.SH_CSRF || '');

  function url(p) { return BASE + String(p).replace(/^\//, ''); }
  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }

  /* ---------- Shared navigation scroll lock ---------------------------
     The drawers lock only the page root/body pair, snapshotting their exact
     inline style attributes and the current horizontal/vertical position.
     A Set makes nested/re-entrant menu lifecycles safe; no touchmove handler
     is installed, so native scrolling remains available inside each menu. */
  var scrollLockOwners = new Set();
  var scrollLockSnapshot = null;
  var openNavigationMenus = new Map();
  var menuFocusableSelector = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

  function saveStyleAttribute(el) {
    return { present: el.hasAttribute('style'), value: el.getAttribute('style') };
  }
  function restoreStyleAttribute(el, saved) {
    if (!el || !saved) { return; }
    if (saved.present) { el.setAttribute('style', saved.value || ''); }
    else { el.removeAttribute('style'); }
  }
  function pageScrollPosition() {
    var root = document.documentElement;
    var body = document.body;
    var x = typeof window.scrollX === 'number' ? window.scrollX : (window.pageXOffset || 0);
    var y = typeof window.scrollY === 'number' ? window.scrollY : (window.pageYOffset || 0);
    if (!x && root && root.scrollLeft) { x = root.scrollLeft; }
    if (!x && body && body.scrollLeft) { x = body.scrollLeft; }
    if (!y && root && root.scrollTop) { y = root.scrollTop; }
    if (!y && body && body.scrollTop) { y = body.scrollTop; }
    return { x: x, y: y };
  }
  function lockPageScroll(owner) {
    if (!owner || scrollLockOwners.has(owner)) { return; }
    if (scrollLockOwners.size === 0) {
      var root = document.documentElement;
      var body = document.body;
      if (!root || !body) { return; }
      var position = pageScrollPosition();
      scrollLockSnapshot = {
        root: root,
        body: body,
        rootStyle: saveStyleAttribute(root),
        bodyStyle: saveStyleAttribute(body),
        x: position.x,
        y: position.y
      };
      root.style.setProperty('overflow', 'hidden', 'important');
      body.style.setProperty('position', 'fixed', 'important');
      body.style.setProperty('top', (-position.y) + 'px', 'important');
      body.style.setProperty('left', (-position.x) + 'px', 'important');
      body.style.setProperty('right', 'auto', 'important');
      body.style.setProperty('width', '100%', 'important');
    }
    scrollLockOwners.add(owner);
  }
  function unlockPageScroll(owner) {
    if (!owner || !scrollLockOwners.delete(owner) || scrollLockOwners.size > 0 || !scrollLockSnapshot) { return; }
    var saved = scrollLockSnapshot;
    scrollLockSnapshot = null;
    restoreStyleAttribute(saved.body, saved.bodyStyle);
    restoreStyleAttribute(saved.root, saved.rootStyle);
    // Force this one restoration to be immediate even if the page normally
    // uses scroll-behavior:smooth; the original style is put back afterwards.
    saved.root.style.setProperty('scroll-behavior', 'auto', 'important');
    try { window.scrollTo(saved.x, saved.y); } catch (e) { /* Older embedded browsers may not expose scrollTo. */ }
    restoreStyleAttribute(saved.root, saved.rootStyle);
  }
  function focusWithoutScrolling(el) {
    if (!el || typeof el.focus !== 'function') { return; }
    try { el.focus({ preventScroll: true }); }
    catch (e) { try { el.focus(); } catch (ignore) { /* disconnected or non-focusable */ } }
  }
  function trapNavigationTab(ev, root) {
    var items = $$(menuFocusableSelector, root).filter(function (el) {
      return !el.disabled && !el.hasAttribute('hidden') && el.getAttribute('aria-hidden') !== 'true'
        && !(el.closest && el.closest('[hidden]'));
    });
    if (!items.length) { ev.preventDefault(); focusWithoutScrolling(root); return; }
    var first = items[0];
    var last = items[items.length - 1];
    var active = document.activeElement;
    if (ev.shiftKey && (active === first || active === root || !root.contains(active))) {
      ev.preventDefault(); focusWithoutScrolling(last);
    } else if (!ev.shiftKey && (active === last || !root.contains(active))) {
      ev.preventDefault(); focusWithoutScrolling(first);
    }
  }
  document.addEventListener('keydown', function (ev) {
    if (!openNavigationMenus.size) { return; }
    var entries = Array.from(openNavigationMenus.values());
    var activeMenu = entries[entries.length - 1];
    if (ev.key === 'Escape') {
      ev.preventDefault();
      activeMenu.close(true);
    } else if (ev.key === 'Tab') {
      trapNavigationTab(ev, activeMenu.focusRoot);
    }
  });
  if (window.MutationObserver && document.documentElement) {
    var navigationRemovalObserver = new window.MutationObserver(function () {
      openNavigationMenus.forEach(function (menu, element) {
        if (!element.isConnected) { menu.close(false); }
      });
    });
    navigationRemovalObserver.observe(document.documentElement, { childList: true, subtree: true });
  }
  window.addEventListener('pagehide', function () {
    Array.from(openNavigationMenus.values()).forEach(function (menu) { menu.close(false); });
  });

  /* ---------- Session / CSRF recovery -------------------------------- */
  function currentReturnPath() {
    return window.location.pathname + window.location.search + window.location.hash;
  }
  function safeLoginUrl(payload) {
    var fallback = url('login.php?redirect=' + encodeURIComponent(currentReturnPath()));
    if (!payload || typeof payload.login_url !== 'string' || !payload.login_url) { return fallback; }
    try {
      var candidate = new URL(payload.login_url, window.location.href);
      return candidate.origin === window.location.origin ? candidate.href : fallback;
    } catch (e) { return fallback; }
  }
  function handleSessionExpired(payload) {
    if (!payload || (!payload.auth_required && !payload.session_expired)) { return false; }
    var destination = safeLoginUrl(payload);
    // A stale form on Login itself should receive a new token rather than add
    // redirect parameters forever. Other pages retain their safe return path.
    if (/\/login\.php$/i.test(window.location.pathname)) { window.location.reload(); }
    else { window.location.assign(destination); }
    return true;
  }
  function handleCsrfFailure(payload) {
    if (!payload || (!payload.csrf_failed && !payload.refresh_required)) { return false; }
    // Never retry a state-changing request automatically. A reload obtains a
    // fresh token and lets the customer decide whether to submit again.
    window.setTimeout(function () { window.location.reload(); }, 30);
    return true;
  }
  window.shHandleSessionExpired = handleSessionExpired;
  window.shHandleCsrfFailure = handleCsrfFailure;

  /* ---------- Toasts -------------------------------------------------- */
  var ICONS = {
    success: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.8 10A10 10 0 1 1 17 3.34"/><path d="m9 11 3 3L22 4"/></svg>',
    error: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
    info: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>'
  };

  function toast(message, type) {
    type = type || 'info';
    var box = $('#sh-toasts');
    if (!box) { return; }
    var el = document.createElement('div');
    el.className = 'sh-toast sh-toast--' + type;
    el.innerHTML = (ICONS[type] || ICONS.info) + '<span></span>';
    el.querySelector('span').textContent = message;
    box.appendChild(el);
    setTimeout(function () {
      el.style.transition = 'opacity .25s';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 260);
    }, 3200);
  }
  window.shToast = toast;

  /* ---------- API helper ---------------------------------------------- */
  function api(endpoint, data) {
    var body = new FormData();
    body.append('csrf_token', CSRF);
    Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
    return fetch(url('api/' + endpoint), {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF }
    }).then(function (r) {
      return r.text().then(function (t) {
        var j;
        try { j = JSON.parse(t); }
        catch (e) { throw new Error('The server returned an unexpected response.'); }
        if (handleSessionExpired(j)) {
          var expired = new Error(j.error || 'Your session has expired.');
          expired.sessionExpired = true;
          throw expired;
        }
        if (handleCsrfFailure(j)) {
          var stale = new Error(j.error || 'This form has expired.');
          stale.csrfFailed = true;
          throw stale;
        }
        if (!r.ok && !j.error) { throw new Error('Request failed (' + r.status + ').'); }
        return j;
      });
    });
  }
  window.shApi = api;

  function setCartCount(n) {
    $$('[data-cart-count]').forEach(function (el) {
      el.textContent = n;
      if (n > 0) { el.removeAttribute('hidden'); } else { el.setAttribute('hidden', ''); }
    });
  }

  /* ---------- Cart actions -------------------------------------------- */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-add-cart]');
    if (btn) {
      ev.preventDefault();
      if (btn.disabled) { return; }
      var qtyInput = document.getElementById(btn.getAttribute('data-qty-source') || '');
      var qty = qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1;
      btn.disabled = true;
      api('cart.php', { action: 'add', product_id: btn.getAttribute('data-add-cart'), quantity: qty })
        .then(function (r) {
          if (r.success) {
            setCartCount(r.count);
            toast(r.message || 'Added to your cart.', 'success');
            if (btn.hasAttribute('data-then-checkout')) { window.location.href = url('checkout.php'); }
          } else { toast(r.error || 'Could not add this item.', 'error'); }
        })
        .catch(function (e) { toast(e.message, 'error'); })
        .finally(function () { btn.disabled = false; });
      return;
    }

    var wish = ev.target.closest('[data-wishlist]');
    if (wish) {
      ev.preventDefault();
      api('cart.php', { action: 'wishlist', product_id: wish.getAttribute('data-wishlist') })
        .then(function (r) {
          if (r.success) {
            wish.setAttribute('aria-pressed', r.in_wishlist ? 'true' : 'false');
            toast(r.message, 'success');
          } else if (r.auth_required) {
            window.location.href = url('login.php?redirect=' + encodeURIComponent(location.pathname + location.search));
          } else { toast(r.error || 'Action failed.', 'error'); }
        })
        .catch(function (e) { toast(e.message, 'error'); });
      return;
    }

    var upd = ev.target.closest('[data-cart-update]');
    if (upd) {
      ev.preventDefault();
      var row = upd.closest('[data-cart-row]');
      var input = row ? $('[data-qty-input]', row) : null;
      var delta = parseInt(upd.getAttribute('data-cart-update'), 10);
      var current = input ? parseInt(input.value, 10) || 1 : 1;
      var next = current + delta;
      if (next < 1) { next = 1; }
      if (input) { input.value = next; }
      cartUpdate(row.getAttribute('data-cart-row'), next, row);
      return;
    }

    var rm = ev.target.closest('[data-cart-remove]');
    if (rm) {
      ev.preventDefault();
      var pid = rm.getAttribute('data-cart-remove');
      rm.disabled = true;
      api('cart.php', { action: 'remove', product_id: pid })
        .then(function (r) {
          if (r.success) { location.reload(); }
          else { toast(r.error || 'Could not remove the item.', 'error'); rm.disabled = false; }
        })
        .catch(function (e) { toast(e.message, 'error'); rm.disabled = false; });
      return;
    }

    var clr = ev.target.closest('[data-cart-clear]');
    if (clr) {
      ev.preventDefault();
      if (!confirm('Remove all items from your cart?')) { return; }
      api('cart.php', { action: 'clear' })
        .then(function (r) { if (r.success) { location.reload(); } })
        .catch(function (e) { toast(e.message, 'error'); });
      return;
    }

    /* ---------- Show / hide secret inputs ------------------------------ */
    var reveal = ev.target.closest('[data-reveal]');
    if (reveal) {
      ev.preventDefault();
      var secret = document.getElementById(reveal.getAttribute('data-reveal'));
      if (secret) {
        var show = secret.type === 'password';
        secret.type = show ? 'text' : 'password';
        reveal.classList.toggle('is-on', show);
        reveal.setAttribute('aria-pressed', show ? 'true' : 'false');
      }
      return;
    }

    /* ---------- Copy to clipboard ------------------------------------- */
    var copy = ev.target.closest('[data-copy]');
    if (copy) {
      ev.preventDefault();
      var text = copy.getAttribute('data-copy');
      var done = function () {
        var label = $('[data-copy-label]', copy);
        var old = label ? label.textContent : '';
        if (label) { label.textContent = 'Copied'; }
        copy.classList.add('sh-copy--done');
        toast('Copied: ' + text, 'success');
        setTimeout(function () {
          if (label) { label.textContent = old; }
          copy.classList.remove('sh-copy--done');
        }, 1800);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done).catch(function () { legacyCopy(text, done); });
      } else { legacyCopy(text, done); }
      return;
    }
  });

  function legacyCopy(text, cb) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); cb(); }
    catch (e) { toast('Copy failed. Please copy manually: ' + text, 'error'); }
    ta.remove();
  }

  function cartUpdate(productId, qty, row) {
    api('cart.php', { action: 'update', product_id: productId, quantity: qty })
      .then(function (r) {
        if (r.success) { location.reload(); }
        else { toast(r.error || 'Could not update the quantity.', 'error'); }
      })
      .catch(function (e) { toast(e.message, 'error'); });
  }

  document.addEventListener('change', function (ev) {
    var input = ev.target.closest('[data-qty-input]');
    if (input) {
      var row = input.closest('[data-cart-row]');
      if (row) {
        var v = parseInt(input.value, 10);
        if (isNaN(v) || v < 1) { v = 1; input.value = 1; }
        cartUpdate(row.getAttribute('data-cart-row'), v, row);
      }
    }
  });

  /* ---------- Quantity stepper (product page) -------------------------- */
  $$('[data-qty-step]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var wrap = btn.closest('.sh-qty');
      var input = wrap ? $('input', wrap) : null;
      if (!input) { return; }
      var max = parseInt(input.getAttribute('max'), 10) || 99;
      var min = parseInt(input.getAttribute('min'), 10) || 1;
      var v = (parseInt(input.value, 10) || min) + parseInt(btn.getAttribute('data-qty-step'), 10);
      input.value = Math.max(min, Math.min(max, v));
    });
  });

  /* ---------- Search suggestions --------------------------------------- */
  var searchInput = $('#sh-search-input');
  var suggestBox = $('#sh-suggest');
  if (searchInput && suggestBox) {
    var timer = null;
    var lastQ = '';
    searchInput.addEventListener('input', function () {
      var q = searchInput.value.trim();
      clearTimeout(timer);
      if (q.length < 2) { suggestBox.hidden = true; return; }
      timer = setTimeout(function () {
        if (q === lastQ) { return; }
        lastQ = q;
        fetch(url('api/search.php?q=' + encodeURIComponent(q)), {
          credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
          .then(function (r) { return r.json(); })
          .then(function (r) {
            if (!r.success || !r.items) { suggestBox.hidden = true; return; }
            if (!r.items.length) {
              suggestBox.innerHTML = '<p class="sh-suggest__empty">No products matched your search.</p>';
              suggestBox.hidden = false;
              return;
            }
            suggestBox.innerHTML = r.items.map(function (it) {
              // Attribute values come from the server, but they are escaped here
              // as well: an unescaped quote in a product slug or image path would
              // otherwise be able to close the attribute and add a handler.
              return '<a class="sh-suggest__item" href="' + esc(it.url) + '">' +
                '<img class="sh-suggest__img" src="' + esc(it.image) + '" alt="" loading="lazy">' +
                '<span class="sh-suggest__name"></span>' +
                '<span class="sh-suggest__price">' + esc(it.price) + '</span></a>';
            }).join('');
            $$('.sh-suggest__name', suggestBox).forEach(function (el, i) { el.textContent = r.items[i].name; });
            suggestBox.hidden = false;
          })
          .catch(function () { suggestBox.hidden = true; });
      }, 220);
    });
    document.addEventListener('click', function (ev) {
      if (!ev.target.closest('#sh-search')) { suggestBox.hidden = true; }
    });
    searchInput.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { suggestBox.hidden = true; }
    });
  }

  /* ---------- Category mega menu --------------------------------------- */
  var catBtn = $('[data-catmenu-toggle]');
  var catMenu = $('#sh-catmenu');
  if (catBtn && catMenu) {
    catBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = catMenu.hidden;
      catMenu.hidden = !open;
      catBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (ev) {
      if (!ev.target.closest('.sh-nav__cats')) {
        catMenu.hidden = true;
        catBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* ---------- Storefront mobile navigation drawer --------------------- */
  var drawer = $('#sh-drawer');
  if (drawer) {
    var drawerPanel = $('.sh-drawer__panel', drawer) || drawer;
    var drawerClose = $('[data-drawer-close]', drawerPanel);
    var drawerOpen = false;
    var drawerTrigger = null;

    function setDrawerTriggers(open) {
      $$('[data-drawer-open]').forEach(function (button) {
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
      });
    }
    function closeStorefrontDrawer(restoreFocus) {
      if (!drawerOpen) { return; }
      drawerOpen = false;
      drawer.hidden = true;
      setDrawerTriggers(false);
      openNavigationMenus.delete(drawer);
      unlockPageScroll(drawer);
      var trigger = drawerTrigger;
      drawerTrigger = null;
      if (restoreFocus !== false && trigger && trigger.isConnected) { focusWithoutScrolling(trigger); }
    }
    function openStorefrontDrawer(trigger) {
      if (drawerOpen) { return; }
      drawerTrigger = trigger || document.activeElement;
      lockPageScroll(drawer);
      drawerOpen = true;
      drawer.hidden = false;
      setDrawerTriggers(true);
      openNavigationMenus.set(drawer, { close: closeStorefrontDrawer, focusRoot: drawerPanel });
      focusWithoutScrolling(drawerClose || drawerPanel);
    }

    $$('[data-drawer-open]').forEach(function (button) {
      button.addEventListener('click', function () { openStorefrontDrawer(button); });
    });
    $$('[data-drawer-close]', drawer).forEach(function (button) {
      button.addEventListener('click', function () { closeStorefrontDrawer(true); });
    });
    window.addEventListener('resize', function () {
      if (drawerOpen && window.innerWidth > 900) { closeStorefrontDrawer(false); }
    });
  }

  /* ---------- Homepage hero carousel ------------------------------------ */
  /*
   * Button-free crossfade. The rules it has to keep:
   *   - the incoming slide fades in over the outgoing one, which stays fully
   *     opaque until the fade has finished, so no frame can show the container
   *     (that is what used to read as a blank/white flash);
   *   - every slide is already in the DOM with its image decoded, so switching
   *     never waits for a download;
   *   - the next slide's image is warmed in advance;
   *   - autoplay stops while the tab is hidden or the carousel is scrolled out
   *     of view, and never runs at all under prefers-reduced-motion;
   *   - a slide whose image fails to load keeps its gradient plate and text.
   */
  var carousel = $('[data-carousel]');
  if (carousel) {
    var slides = $$('.sh-slide', carousel);
    var dots = $$('.sh-carousel__dot', carousel);
    var motionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var interval = parseInt(carousel.getAttribute('data-carousel-interval') || '5200', 10);
    if (!(interval >= 4000 && interval <= 6000)) { interval = 5200; }
    var fade = parseInt(carousel.getAttribute('data-carousel-fade') || '700', 10);
    if (!(fade >= 600 && fade <= 1000)) { fade = 700; }
    carousel.style.setProperty('--sh-slide-fade', fade + 'ms');
    var idx = 0, auto = null, settle = null, visible = true, warm = {};

    function reduced() { return !!(motionQuery && motionQuery.matches); }
    function at(i) { return slides[((i % slides.length) + slides.length) % slides.length]; }

    /* Warm the next slide's image so the fade never waits on the network. */
    function preload(i) {
      var img = $('.sh-slide__img', at(i));
      var src = img ? img.getAttribute('src') : null;
      if (!src || warm[src]) { return; }
      warm[src] = true;
      var warmer = new Image();
      warmer.decoding = 'async';
      warmer.src = src;
    }

    function rest(slide) {
      slide.classList.remove('sh-slide--on', 'sh-slide--in');
      slide.setAttribute('aria-hidden', 'true');
    }

    function show(next, first) {
      var to = at(next);
      if (!to || (to === slides[idx] && !first)) { return; }
      var from = slides[idx];
      if (settle) { clearTimeout(settle); settle = null; }

      /* Anything that is neither the current nor the incoming slide is at rest. */
      slides.forEach(function (s) { if (s !== from && s !== to) { rest(s); } });

      if (reduced() || first) {
        /* Reduced motion: an instant cut, no zoom, nothing moving. */
        if (from && from !== to) { rest(from); }
        to.classList.remove('sh-slide--in');
        to.classList.add('sh-slide--on');
        to.removeAttribute('aria-hidden');
      } else {
        to.classList.add('sh-slide--in');
        to.removeAttribute('aria-hidden');
        /* The outgoing slide stays painted until the fade ends, but it is no
           longer the slide being read out: the change is announced at once. */
        if (from && from !== to) { from.setAttribute('aria-hidden', 'true'); }
        void to.offsetWidth;             /* commit opacity:0 so the fade runs */
        to.classList.add('sh-slide--on');
        settle = setTimeout(function () {
          if (from && from !== to) { rest(from); }
          to.classList.remove('sh-slide--in');
          settle = null;
        }, fade + 120);
      }

      idx = slides.indexOf(to);
      dots.forEach(function (d, n) {
        d.classList.toggle('sh-carousel__dot--on', n === idx);
        if (n === idx) { d.setAttribute('aria-current', 'true'); } else { d.removeAttribute('aria-current'); }
      });
      preload(idx + 1);
    }

    function stop() { if (auto) { clearInterval(auto); auto = null; } }
    function start() {
      stop();
      if (slides.length > 1 && !reduced() && visible && !document.hidden) {
        auto = setInterval(function () { show(idx + 1); }, interval);
      }
    }

    slides.forEach(function (slide) {
      var img = $('.sh-slide__img', slide);
      if (!img) { return; }
      img.addEventListener('error', function () {
        /* Missing or broken file: keep the plate, the heading and the text. */
        slide.classList.add('sh-slide--noimg');
        img.removeAttribute('src');
      });
    });

    dots.forEach(function (d, n) {
      d.addEventListener('click', function () { show(n); start(); });
    });

    carousel.addEventListener('mouseenter', stop);
    carousel.addEventListener('mouseleave', start);
    carousel.addEventListener('focusin', stop);
    carousel.addEventListener('focusout', start);

    var swipeX = 0, swiping = false;
    carousel.addEventListener('touchstart', function (e) {
      swipeX = e.touches[0].clientX; swiping = true; stop();
    }, { passive: true });
    carousel.addEventListener('touchend', function (e) {
      var dx = e.changedTouches[0].clientX - swipeX;
      if (swiping && Math.abs(dx) > 45) { show(idx + (dx < 0 ? 1 : -1)); }
      swiping = false;
      start();
    }, { passive: true });
    carousel.addEventListener('touchcancel', function () { swiping = false; start(); }, { passive: true });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { stop(); } else { start(); }
    });

    /* Off-screen carousels stop as well: nothing rotates where nobody looks. */
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        visible = entries[0].intersectionRatio > 0.15;
        if (visible) { start(); } else { stop(); }
      }, { threshold: [0, 0.15, 1] }).observe(carousel);
    }

    if (motionQuery) {
      var onMotionChange = function () { show(idx, true); start(); };
      if (motionQuery.addEventListener) { motionQuery.addEventListener('change', onMotionChange); }
      else if (motionQuery.addListener) { motionQuery.addListener(onMotionChange); }
    }

    show(0, true);
    start();
  }

  /* ---------- Countdown timers ----------------------------------------- */
  $$('[data-countdown]').forEach(function (el) {
    var end = new Date(el.getAttribute('data-countdown').replace(' ', 'T')).getTime();
    if (isNaN(end)) { return; }
    var parts = {
      d: $('[data-cd-d]', el), h: $('[data-cd-h]', el),
      m: $('[data-cd-m]', el), s: $('[data-cd-s]', el)
    };
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function tick() {
      var left = end - Date.now();
      if (left <= 0) {
        Object.keys(parts).forEach(function (k) { if (parts[k]) { parts[k].textContent = '00'; } });
        clearInterval(iv);
        return;
      }
      var s = Math.floor(left / 1000);
      if (parts.d) { parts.d.textContent = pad(Math.floor(s / 86400)); }
      if (parts.h) { parts.h.textContent = pad(Math.floor(s / 3600) % 24); }
      if (parts.m) { parts.m.textContent = pad(Math.floor(s / 60) % 60); }
      if (parts.s) { parts.s.textContent = pad(s % 60); }
    }
    tick();
    var iv = setInterval(tick, 1000);
  });

  /* ---------- Product gallery ------------------------------------------ */
  var gallery = $('[data-gallery]');
  if (gallery) {
    var main = $('[data-gallery-main]', gallery);
    $$('[data-gallery-thumb]', gallery).forEach(function (t) {
      t.addEventListener('click', function () {
        if (main) { main.src = t.getAttribute('data-gallery-thumb'); }
        $$('[data-gallery-thumb]', gallery).forEach(function (x) { x.classList.remove('sh-gallery__thumb--on'); });
        t.classList.add('sh-gallery__thumb--on');
      });
    });
  }

  /* ---------- Tabs ------------------------------------------------------ */
  $$('[data-tabs]').forEach(function (wrap) {
    var btns = $$('[data-tab]', wrap);
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        var name = b.getAttribute('data-tab');
        btns.forEach(function (x) { x.classList.toggle('sh-tabs__btn--on', x === b); });
        $$('[data-tab-panel]', wrap).forEach(function (p) {
          p.hidden = p.getAttribute('data-tab-panel') !== name;
        });
      });
    });
  });

  /* ---------- Mobile product-filter drawer ---------------------------- */
  var filters = $('#sh-filters');
  if (filters) {
    var filterScrim = $('.sh-filters__scrim', filters.parentElement || document);
    var filterClose = $('[data-filters-close]', filters);
    var filterDrawerOpen = false;
    var filterDrawerTrigger = null;

    function setFilterTriggers(open) {
      $$('[data-filters-open]').forEach(function (button) {
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.setAttribute('aria-label', open ? 'Close filters' : 'Open filters');
      });
    }
    function closeFilterDrawer(restoreFocus) {
      if (!filterDrawerOpen) { return; }
      filterDrawerOpen = false;
      filters.classList.remove('sh-filters--open');
      filters.removeAttribute('role');
      filters.removeAttribute('aria-modal');
      if (filterScrim) { filterScrim.hidden = true; }
      setFilterTriggers(false);
      openNavigationMenus.delete(filters);
      unlockPageScroll(filters);
      var trigger = filterDrawerTrigger;
      filterDrawerTrigger = null;
      if (restoreFocus !== false && trigger && trigger.isConnected) { focusWithoutScrolling(trigger); }
    }
    function openFilterDrawer(trigger) {
      if (filterDrawerOpen) { return; }
      filterDrawerTrigger = trigger || document.activeElement;
      lockPageScroll(filters);
      filterDrawerOpen = true;
      filters.setAttribute('role', 'dialog');
      filters.setAttribute('aria-modal', 'true');
      filters.classList.add('sh-filters--open');
      if (filterScrim) { filterScrim.hidden = false; }
      setFilterTriggers(true);
      openNavigationMenus.set(filters, { close: closeFilterDrawer, focusRoot: filters });
      focusWithoutScrolling(filterClose || filters);
    }

    $$('[data-filters-open]').forEach(function (button) {
      button.addEventListener('click', function () { openFilterDrawer(button); });
    });
    $$('[data-filters-close]', filters).forEach(function (button) {
      button.addEventListener('click', function () { closeFilterDrawer(true); });
    });
    if (filterScrim) { filterScrim.addEventListener('click', function () { closeFilterDrawer(true); }); }
    window.addEventListener('resize', function () {
      if (filterDrawerOpen && window.innerWidth > 900) { closeFilterDrawer(false); }
    });
  }
  var sortSelects = $$('[data-sort-select]');
  sortSelects.forEach(function (sel) {
    sel.addEventListener('change', function () {
      var u = new URL(window.location.href);
      u.searchParams.set('sort', sel.value);
      u.searchParams.delete('page');
      window.location.href = u.toString();
    });
  });

  /* ---------- Payment method selection --------------------------------- */
  $$('[data-pay-option]').forEach(function (opt) {
    opt.addEventListener('click', function () {
      $$('[data-pay-option]').forEach(function (o) { o.classList.remove('sh-pay-option--on'); });
      opt.classList.add('sh-pay-option--on');
      var radio = $('input[type=radio]', opt);
      if (radio) { radio.checked = true; }
    });
  });

  /* ---------- Admin mobile navigation sidebar ------------------------ */
  var adminShell = $('.sh-admin');
  var adminSidebar = $('.sh-admin-sidebar', adminShell || document);
  if (adminShell && adminSidebar) {
    var adminBurger = $('[data-admin-burger]', adminShell);
    var adminScrim = $('.sh-admin-scrim', adminShell);
    var adminClose = $('[data-admin-nav-close]', adminSidebar);
    var adminNavOpen = false;
    var adminNavTrigger = null;

    function setAdminNavTriggers(open) {
      if (!adminBurger) { return; }
      adminBurger.setAttribute('aria-expanded', open ? 'true' : 'false');
      adminBurger.setAttribute('aria-label', open ? 'Close admin navigation' : 'Open admin navigation');
    }
    function closeAdminNavigation(restoreFocus) {
      if (!adminNavOpen) { return; }
      adminNavOpen = false;
      adminShell.classList.remove('is-nav-open');
      adminSidebar.classList.remove('sh-admin-sidebar--open');
      if (adminScrim) { adminScrim.hidden = true; }
      setAdminNavTriggers(false);
      openNavigationMenus.delete(adminSidebar);
      unlockPageScroll(adminSidebar);
      var trigger = adminNavTrigger;
      adminNavTrigger = null;
      if (restoreFocus !== false && trigger && trigger.isConnected) { focusWithoutScrolling(trigger); }
    }
    function openAdminNavigation() {
      if (adminNavOpen) { return; }
      adminNavTrigger = adminBurger || document.activeElement;
      lockPageScroll(adminSidebar);
      adminNavOpen = true;
      adminShell.classList.add('is-nav-open');
      if (adminScrim) { adminScrim.hidden = false; }
      setAdminNavTriggers(true);
      openNavigationMenus.set(adminSidebar, { close: closeAdminNavigation, focusRoot: adminSidebar });
      focusWithoutScrolling(adminClose || adminSidebar);
    }
    if (adminBurger) {
      adminBurger.addEventListener('click', function () {
        if (adminNavOpen) { closeAdminNavigation(true); }
        else { openAdminNavigation(); }
      });
    }
    if (adminClose) { adminClose.addEventListener('click', function () { closeAdminNavigation(true); }); }
    if (adminScrim) { adminScrim.addEventListener('click', function () { closeAdminNavigation(true); }); }
    window.addEventListener('resize', function () {
      if (adminNavOpen && window.innerWidth > 1024) { closeAdminNavigation(false); }
    });
  }

  /* ---------- Confirm-before-submit ------------------------------------ */
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    var msg = form.getAttribute('data-confirm');
    if (msg && !confirm(msg)) { ev.preventDefault(); return; }
    var submit = form.querySelector('[type=submit]');
    if (submit && !form.hasAttribute('data-no-lock')) {
      setTimeout(function () { submit.disabled = true; submit.style.opacity = '.6'; }, 10);
      setTimeout(function () { submit.disabled = false; submit.style.opacity = ''; }, 12000);
    }
  });
  $$('[data-confirm-click]').forEach(function (el) {
    el.addEventListener('click', function (ev) {
      if (!confirm(el.getAttribute('data-confirm-click'))) { ev.preventDefault(); }
    });
  });

  /* ---------- Unsaved-changes guard (never saves) -----------------------
     Typing, pasting, changing a field or tabbing away must NEVER write to
     the database: the only thing that saves an admin form is its explicit
     Save / Create / Update button. This guard is the safety net for that
     rule. It only shows a small "Unsaved changes" note and warns before
     leaving; it never submits, debounces, fetches or stores anything.

     Coverage is deliberate and uniform so no edit screen can be missed:
       - any form carrying data-dirty-guard (including script-handled forms
         such as the AI product review), plus
       - inside the admin panel, every POST form that holds at least one
         editable field. One-click action forms (delete / toggle / refresh:
         hidden fields only) are not edit forms and stay unguarded, and a
         form can opt out with data-no-dirty-guard.
     Read-only navigation controls (page/provider pickers, GET filter
     selects) never mark a form dirty by themselves; if the administrator
     has edited other fields first, leaving through them still warns. */
  (function () {
    var NAV_CONTROLS = '[data-navigate-on-change],[data-navigate-prefix],'
      + '[data-seo-page-picker],[data-provider-select],'
      + '[data-submit-form-on-change],[data-sort-select]';
    var EDITABLE = 'input:not([type="hidden"]):not([type="submit"])'
      + ':not([type="button"]):not([type="image"]):not([type="reset"]),select,textarea';

    var candidates = $$('form[data-dirty-guard]');
    if (document.querySelector('.sh-admin')) {
      $$('form').forEach(function (form) {
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
        if (form.hasAttribute('data-no-dirty-guard')) { return; }
        if (!form.querySelector(EDITABLE)) { return; }
        if (candidates.indexOf(form) === -1) { candidates.push(form); }
      });
    }

    candidates.forEach(function (form) {
      var note = form.querySelector('[data-dirty-note]');
      if (!note) {
        // Same pill the product/category editors render server-side, placed
        // next to the button that actually saves.
        note = document.createElement('span');
        note.className = 'sh-dirty';
        note.setAttribute('data-dirty-note', '');
        note.hidden = true;
        note.textContent = 'Unsaved changes';
        var submit = form.querySelector('[type="submit"]');
        if (submit && submit.parentNode) {
          submit.parentNode.insertBefore(note, submit.nextSibling);
        } else {
          form.appendChild(note);
        }
      }
      var dirty = false;
      function mark(ev) {
        if (dirty) { return; }
        var target = ev && ev.target;
        if (target && target.matches && target.matches(NAV_CONTROLS)) { return; }
        dirty = true;
        if (note) { note.hidden = false; }
      }
      function clean() {
        dirty = false;
        if (note) { note.hidden = true; }
      }
      form.addEventListener('input', mark);
      form.addEventListener('change', mark);
      // Registered on the document after the confirm handler, so a submission
      // the administrator cancels still counts as unsaved.
      document.addEventListener('submit', function (ev) {
        if (ev.target !== form || ev.defaultPrevented) { return; }
        clean();
      });
      // Script-handled forms (the AI product review) preventDefault() their
      // submit and save over fetch instead; they announce a successful save
      // (or a discard) with this event so the guard can stand down. A failed
      // save never fires it, so the entries stay marked unsaved.
      document.addEventListener('sh:form-clean', function (ev) {
        if (ev.target === form) { clean(); }
      });
      window.addEventListener('beforeunload', function (ev) {
        if (!dirty) { return; }
        ev.preventDefault();
        ev.returnValue = '';
        return '';
      });
    });
  })();

  /* ---------- Textareas grow with their content --------------------------
     No field hides text behind an internal scrollbar: textareas are sized to
     their content (CSS field-sizing does this natively where supported; this
     is the fallback, and the hook programmatic fills call afterwards). The
     page scrolls; fields do not. */
  function growTextarea(el) {
    if (!el || el.tagName !== 'TEXTAREA') return;
    if (!el.offsetParent && el.getClientRects().length === 0) return;
    el.style.overflowY = 'hidden';
    el.style.height = 'auto';
    var extra = el.offsetHeight - el.clientHeight;
    el.style.height = (el.scrollHeight + extra) + 'px';
  }
  window.shGrowTextarea = growTextarea;
  function growAllTextareas(root) {
    $$('textarea', root || document).forEach(growTextarea);
  }
  window.shGrowTextareas = growAllTextareas;
  growAllTextareas();
  document.addEventListener('input', function (ev) { growTextarea(ev.target); });
  document.addEventListener('focusin', function (ev) { growTextarea(ev.target); });

  /* ---------- Modals ---------------------------------------------------- */
  document.addEventListener('click', function (ev) {
    var open = ev.target.closest('[data-modal-open]');
    if (open) {
      ev.preventDefault();
      var m = document.getElementById(open.getAttribute('data-modal-open'));
      if (m) {
        m.style.top = window.scrollY + 'px';
        m.hidden = false;
        Object.keys(open.dataset).forEach(function (k) {
          if (k.indexOf('fill') === 0) {
            var target = m.querySelector('[data-fill-' + k.slice(4).toLowerCase() + ']');
            if (target) {
              if ('value' in target) { target.value = open.dataset[k]; growTextarea(target); }
              else { target.textContent = open.dataset[k]; }
            }
          }
        });
        growAllTextareas(m);
      }
    }
    var close = ev.target.closest('[data-modal-close]');
    if (close) {
      var box = close.closest('.sh-modal');
      if (box) { box.hidden = true; }
    }
  });

  /* ---------- Lazy image fallback --------------------------------------- */
  document.addEventListener('error', function (ev) {
    var img = ev.target;
    if (img.tagName === 'IMG' && img.hasAttribute('data-ai-provider-logo')) {
      img.hidden = true;
      var logoFallback = img.parentElement && $('.sh-aiprov__mark-fallback', img.parentElement);
      if (logoFallback) { logoFallback.hidden = false; }
      return;
    }
    if (img.tagName === 'IMG' && !img.dataset.fallbackApplied) {
      img.dataset.fallbackApplied = '1';
      img.src = url('assets/images/placeholder.svg');
    }
  }, true);
})();

/* ===================================================================
   ADMIN — notification channel test buttons
   Wires [data-test-channel] to api/notifications.php and reports the
   real provider result. Never claims success on its own.
   =================================================================== */
(function () {
  /* Select-on-focus fields (the provider webhook URL) — a small convenience that
     keeps the markup free of inline handlers, which Content-Security-Policy blocks. */
  document.addEventListener('focusin', function (ev) {
    var el = ev.target;
    if (el && el.matches && el.matches('[data-select-on-focus]') && typeof el.select === 'function') {
      el.select();
    }
  });

  var buttons = document.querySelectorAll('[data-test-channel]');
  if (!buttons.length) return;

  var CSRF = window.SH_CSRF || '';
  var BASE = window.SH_BASE || '/';

  buttons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var channel = btn.getAttribute('data-test-channel');
      var out = document.getElementById(channel === 'telegram' ? 'tg-result' : 'test-result');
      var original = btn.innerHTML;

      btn.disabled = true;
      btn.innerHTML = 'Sending…';
      if (out) { out.textContent = ''; out.removeAttribute('data-state'); }

      var body = new FormData();
      body.append('csrf_token', CSRF);
      body.append('channel', channel);

      fetch(BASE + 'api/notifications.php', {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF }
      })
        .then(function (r) { return r.text(); })
        .then(function (t) {
          var j;
          try { j = JSON.parse(t); }
          catch (e) { throw new Error('Unexpected server response.'); }
          if (window.shHandleSessionExpired && window.shHandleSessionExpired(j)) { return; }
          if (window.shHandleCsrfFailure && window.shHandleCsrfFailure(j)) { return; }

          var ok = !!j.success;
          var msg = ok
            ? (j.message || 'Test message sent successfully.')
            : (j.error || 'The test could not be sent.');

          if (out) {
            out.textContent = msg;
            out.style.color = ok ? '#1a7f4b' : '#c62828';
            out.style.fontWeight = '600';
          }
          if (window.shToast) window.shToast(msg, ok ? 'success' : 'error');
        })
        .catch(function (err) {
          var msg = err && err.message ? err.message : 'Network error.';
          if (out) { out.textContent = msg; out.style.color = '#c62828'; out.style.fontWeight = '600'; }
          if (window.shToast) window.shToast(msg, 'error');
        })
        .finally(function () {
          btn.disabled = false;
          btn.innerHTML = original;
        });
    });
  });
})();

/* ===================================================================
   ADMIN — homepage promo editor
   Live image preview before saving + overlay strength readout.
   =================================================================== */
(function () {
  /* Preview the chosen file before it is uploaded. */
  /* The preview target may be the old .sh-promoprev wrapper (with its own
     caption element) or a plain <img> as the category editor uses. Both are
     supported so no existing admin screen changes behaviour. */
  document.querySelectorAll('[data-image-preview]').forEach(function (input) {
    var target = document.getElementById(input.getAttribute('data-image-preview'));
    if (!target) return;
    var box = target.tagName === 'IMG' ? target.parentNode : target;
    var img = target.tagName === 'IMG' ? target : target.querySelector('img');
    var cap = (box ? box.querySelector('[data-preview-caption]') : null)
      || document.getElementById(input.getAttribute('data-image-preview') + '-cap');
    var placeholder = img ? (img.getAttribute('data-placeholder-src') || '') : '';
    var original = img ? img.getAttribute('src') : '';

    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) {
        if (img) img.src = original;
        if (cap) cap.textContent = 'Current image';
        box.hidden = !original;
        return;
      }
      if (!/^image\//.test(file.type)) {
        if (window.shToast) window.shToast('That file is not an image.', 'error');
        input.value = '';
        return;
      }
      var url = URL.createObjectURL(file);
      if (img) {
        img.classList.remove('is-placeholder');
        img.onload = function () { URL.revokeObjectURL(url); };
        img.src = url;
      }
      var kb = file.size >= 1048576
        ? (file.size / 1048576).toFixed(1) + ' MB'
        : Math.round(file.size / 1024) + ' KB';
      if (cap) cap.textContent = 'New image — ' + kb + ' (not saved yet)';
      if (box) box.hidden = false;
    });

    // "Remove the current image" shows the neutral fallback straight away, so
    // the operator sees what the storefront will show before saving.
    var remove = document.querySelector('[data-image-remove="' + input.getAttribute('data-image-preview') + '"]');
    if (remove && img) {
      var syncRemoval = function () {
        if (remove.checked && !(input.files && input.files[0])) {
          img.classList.add('is-placeholder');
          img.src = placeholder || original;
          if (cap) cap.textContent = placeholder ? 'No image — placeholder shown' : 'Image will be removed';
        } else if (!remove.checked && !(input.files && input.files[0])) {
          img.classList.remove('is-placeholder');
          img.src = original;
          if (cap) cap.textContent = 'Current image';
        }
      };
      remove.addEventListener('change', syncRemoval);
      input.addEventListener('change', function () { if (remove.checked) { remove.checked = false; } });
    }
  });

  /* Overlay strength: show the live value next to the slider. */
  var range = document.querySelector('[data-overlay-range]');
  var out = document.querySelector('[data-overlay-out]');
  if (range && out) {
    range.addEventListener('input', function () { out.textContent = range.value; });
  }
})();

/* ===================================================================
   ADMIN — AI Auto Work
   Every button below performs a real server-side request to api/ai.php.
   Nothing is simulated: a failure shows the provider's actual message.
   =================================================================== */
(function () {
  var root = document.querySelector('[data-ai-product],[data-ai-category],[data-ai-blog-generate],[data-ai-test],[data-ai-bulk-start],[data-ai-route-provider],[data-ai-providers]');
  if (!root && !document.querySelector('[data-ai-block]')) return;

  var CSRF = window.SH_CSRF || '';
  var BASE = window.SH_BASE || '/';
  var store = {};            // last generated payload per task

  function api(fields, opts) {
    var body = new FormData();
    body.append('csrf_token', CSRF);
    Object.keys(fields).forEach(function (k) {
      var v = fields[k];
      if (Array.isArray(v)) { v.forEach(function (i) { body.append(k + '[]', i); }); }
      else { body.append(k, v); }
    });
    var init = {
      method: 'POST', body: body, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    };
    if (opts && opts.signal) { init.signal = opts.signal; }
    return fetch(BASE + 'api/ai.php', init).then(function (r) {
      return r.text().then(function (t) {
        var j;
        try { j = JSON.parse(t); }
        catch (e) { throw new Error('The server returned an unexpected response.'); }
        if (window.shHandleSessionExpired && window.shHandleSessionExpired(j)) {
          var expired = new Error(j.error || 'Your session has expired.');
          expired.sessionExpired = true;
          throw expired;
        }
        if (window.shHandleCsrfFailure && window.shHandleCsrfFailure(j)) {
          var stale = new Error(j.error || 'This form has expired.');
          stale.csrfFailed = true;
          throw stale;
        }
        if (!j.success) {
          var failed = new Error(j.error || 'The request failed.');
          failed.payload = j;
          throw failed;
        }
        return j;
      });
    });
  }

  /* Provider selects (task routing, fallback, bulk): enable the model box and
     fill its datalist with that provider's capability-matching models. */
  document.querySelectorAll('[data-ai-route-provider]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var input = document.getElementById(sel.getAttribute('data-target'));
      if (!input) return;
      var pid = parseInt(sel.value || '0', 10);
      var list = document.getElementById(input.getAttribute('list'));
      input.disabled = pid <= 0;
      if (pid <= 0) { input.value = ''; if (list) list.innerHTML = ''; return; }
      api({ action: 'provider_models', provider_id: pid, kind: sel.getAttribute('data-kind') || 'text' })
        .then(function (j) {
          if (!list) return;
          list.innerHTML = j.models.map(function (m) {
            return '<option value="' + esc(m.id) + '">' + esc(m.name) + '</option>';
          }).join('');
        })
        .catch(function () { if (list) list.innerHTML = ''; });
    });
  });

  function refId() {
    var el = document.querySelector('[data-ai-product]') || document.querySelector('[data-ai-category]');
    if (!el) return 0;
    return parseInt(el.getAttribute('data-ai-product') || el.getAttribute('data-ai-category') || '0', 10);
  }

  function busy(btn, on, label) {
    if (!btn) return;
    if (on) {
      // Store the idle label once. A second busy() call must never record the
      // "Testing…" label as the thing to restore — that is how a button ends
      // up stuck showing it forever.
      if (!btn.dataset.prev) btn.dataset.prev = btn.innerHTML;
      btn.innerHTML = label || 'Generating…';
      btn.disabled = true;
    } else {
      if (btn.dataset.prev) { btn.innerHTML = btn.dataset.prev; delete btn.dataset.prev; }
      btn.disabled = false;
    }
  }

  /* ---- Provider connection tests -------------------------------------
     One button, one card, one explicit state. The request is aborted after
     45 seconds so the label can never stay on "Testing…"; every path (ok,
     provider failure, network failure, timeout) writes a visible result and
     re-enables the button. The server-side probes allow 12-15 seconds per
     call, so even a two-step probe (read-only check + one tiny reply) stays
     well inside a typical PHP time limit and finishes before the browser
     gives up — the stored result then matches what is shown. */
  function testToneClass(tone) {
    return tone === 'ok' ? 'is-ok' : (tone === 'bad' ? 'is-bad' : 'is-warn');
  }
  function testBadgeClass(tone) {
    return tone === 'ok' ? 'sh-badge--ok' : (tone === 'bad' ? 'sh-badge--bad' : 'sh-badge--warn');
  }
  function showTestResult(card, data) {
    if (!card) return;
    var tone = data.tone || (data.ok ? 'ok' : 'bad');
    var state = data.state || (data.ok ? 'Connected' : 'Test failed');
    var chip = card.querySelector('[data-ai-test-chip]');
    if (chip) { chip.textContent = state; chip.className = 'sh-badge ' + testBadgeClass(tone); }
    var when = card.querySelector('[data-ai-test-time]');
    if (when) { when.textContent = 'just now'; }
    var note = card.querySelector('[data-ai-test-note]');
    if (note) { note.textContent = data.message || ''; note.hidden = !data.message; }
    var next = card.querySelector('[data-ai-test-next]');
    if (next) { next.textContent = data.next || ''; next.hidden = !data.next; }
    var live = card.querySelector('[data-ai-test-result]');
    if (live) {
      live.hidden = false;
      live.className = 'sh-aiprov__live ' + testToneClass(tone);
      live.textContent = (data.status ? 'HTTP ' + data.status + ' · ' : '') + (data.message || '');
    }
    var stateEl = card.querySelector('[data-ai-test-state]') || card;
    stateEl.setAttribute('data-ai-test-state', data.code || (data.ok ? 'connected' : 'failed'));
  }
  function runProviderTest(btn) {
    var pid = btn.getAttribute('data-provider') || '';
    var card = btn.closest('[data-ai-card]') || document.querySelector('[data-ai-card="' + pid + '"]');
    if (!pid || btn.getAttribute('data-testing') === '1') { return; }
    btn.setAttribute('data-testing', '1');

    var ctrl = (typeof window.AbortController === 'function') ? new window.AbortController() : null;
    var timedOut = false;
    var timer = window.setTimeout(function () {
      timedOut = true;
      if (ctrl) { ctrl.abort(); }
    }, 45000);

    busy(btn, true, 'Testing…');
    // Say what is happening inside the card as well, so a slow provider reads
    // as "in progress" instead of looking stuck.
    var live = card ? card.querySelector('[data-ai-test-result]') : null;
    if (live) {
      live.hidden = false;
      live.className = 'sh-aiprov__live is-warn';
      live.textContent = 'Testing — one or two read-only requests. This usually takes a few seconds.';
    }
    api({ action: 'test_connection', provider_id: pid }, ctrl ? { signal: ctrl.signal } : null)
      .then(function (j) {
        showTestResult(card, j);
      })
      .catch(function (err) {
        var aborted = timedOut || (err && err.name === 'AbortError');
        showTestResult(card, aborted
          ? { ok: false, code: 'timeout', tone: 'warn', state: 'Timed out', status: 0,
              message: 'The server did not answer within 45 seconds.', next: 'Retry. If it keeps timing out, check the provider base URL and the server log.' }
          : { ok: false, code: 'network_error', tone: 'warn', state: 'Network error', status: 0,
              message: (err && err.message) || 'The test request failed.', next: 'Check your connection, then retry.' });
      })
      .finally(function () {
        window.clearTimeout(timer);
        btn.removeAttribute('data-testing');
        busy(btn, false);
      });
  }
  window.shAiTestProvider = runProviderTest;

  /* A page restored from the browser's back/forward cache keeps the DOM
     exactly as it was frozen — including a button that was disabled in the
     middle of a test. Nothing runs on restore, so without this the card
     would sit on "Testing…" forever. Stand any such button down; the stored
     result from the server is what the card already shows. */
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) { return; }
    document.querySelectorAll('[data-ai-test][data-testing]').forEach(function (b) {
      b.removeAttribute('data-testing');
      busy(b, false);
      var c = b.closest('[data-ai-card]');
      var l = c ? c.querySelector('[data-ai-test-result]') : null;
      if (l) { l.hidden = true; l.textContent = ''; }
    });
  });

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* Render a generated payload into its block. */
  function render(task, data) {
    var block = document.querySelector('[data-ai-block="' + task + '"]');
    if (!block) return;
    var out = block.querySelector('[data-ai-output]');
    var html = '';

    if (task === 'title')            html = '<p>' + esc(data.title) + '</p>';
    else if (task === 'short')       html = '<p>' + esc(data.short_description) + '</p>';
    else if (task === 'description') html = data.description || '';
    else if (task === 'image_prompt')html = '<p>' + esc(data.prompt) + '</p>';
    else if (task === 'tags') {
      html = '<div class="sh-aitaglist">' + (data.tags || []).map(function (t) {
        return '<span class="sh-aitag">' + esc(t) + '</span>';
      }).join('') + '</div>';
    } else if (task === 'seo' || task === 'category_content') {
      if (data.description) html += '<p><strong>Description:</strong> ' + esc(data.description) + '</p>';
      html += '<p><strong>Meta title:</strong> ' + esc(data.meta_title) + '</p>';
      html += '<p><strong>Meta description:</strong> ' + esc(data.meta_description) + '</p>';
      if (data.focus_keyword) html += '<p><strong>Focus keyword:</strong> ' + esc(data.focus_keyword) + '</p>';
      html += '<p><strong>Keywords:</strong> ' + esc(data.keywords) + '</p>';
    } else if (task === 'category') {
      html = '<p><strong>Suggested:</strong> ' + esc(data.suggested) + '</p>'
           + '<p><strong>Confidence:</strong> ' + esc(data.confidence) + '%</p>'
           + '<p>' + esc(data.reason) + '</p>';
      if (!data.exists) {
        html += '<p class="sh-airesult sh-airesult--warn">This category does not exist yet. '
              + 'Saving will do nothing — use “Create category” to add it first.</p>'
              + '<button class="sh-btn sh-btn--sm" data-ai-create-category>Create “' + esc(data.suggested) + '”</button>';
      }
    }
    out.innerHTML = html || '<em>Empty response.</em>';
    block.hidden = false;
    store[task] = data;
    block.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function generate(task, btn, extra) {
    var fields = { action: 'generate', task: task, ref_id: refId() };
    Object.keys(extra || {}).forEach(function (k) { fields[k] = extra[k]; });
    busy(btn, true);
    return api(fields).then(function (j) {
      render(task, j.data);
      if (window.shToast) {
        window.shToast('Generated. Review, then press Save.', 'success');
      }
      return j;
    }).catch(function (err) {
      if (window.shToast) window.shToast(err.message, 'error');
    }).finally(function () { busy(btn, false); });
  }

  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-ai-generate],[data-ai-regenerate],[data-ai-save],[data-ai-copy],'
      + '[data-ai-generate-all],[data-ai-create-category],[data-ai-test],[data-ai-blog-generate],'
      + '[data-ai-blog-save],[data-ai-image-generate],[data-ai-image-use],[data-ai-bulk-start],[data-ai-retry]');
    if (!el) return;

    /* ---- single field generation ---- */
    var task = el.getAttribute('data-ai-generate') || el.getAttribute('data-ai-regenerate');
    if (task) {
      ev.preventDefault();
      var extra = {};
      if (task === 'image') {
        var pf = document.querySelector('[data-ai-image-prompt]');
        if (pf) extra.prompt = pf.value;
      }
      generate(task, el, extra);
      return;
    }

    /* ---- generate every field in sequence (never in parallel) ---- */
    if (el.hasAttribute('data-ai-generate-all')) {
      ev.preventDefault();
      var queue = ['title', 'short', 'description', 'tags', 'seo'];
      busy(el, true, 'Working…');
      (function next(i) {
        if (i >= queue.length) { busy(el, false); if (window.shToast) window.shToast('All fields generated.', 'success'); return; }
        generate(queue[i], null).then(function () { next(i + 1); });
      })(0);
      return;
    }

    /* ---- copy ---- */
    if (el.hasAttribute('data-ai-copy')) {
      ev.preventDefault();
      var box = el.closest('.sh-aiblock').querySelector('[data-ai-output]');
      var text = box ? (box.innerText || '').trim() : '';
      if (navigator.clipboard && text) {
        navigator.clipboard.writeText(text).then(function () {
          if (window.shToast) window.shToast('Copied.', 'success');
        });
      }
      return;
    }

    /* ---- save to the database ---- */
    var saveTask = el.getAttribute('data-ai-save');
    if (saveTask) {
      ev.preventDefault();
      if (!store[saveTask]) { if (window.shToast) window.shToast('Generate something first.', 'error'); return; }
      busy(el, true, 'Saving…');
      api({ action: 'save', task: saveTask, ref_id: refId(), data: JSON.stringify(store[saveTask]) })
        .then(function (j) {
          if (window.shToast) window.shToast(j.message || 'Saved.', 'success');
          setTimeout(function () { location.reload(); }, 900);
        })
        .catch(function (err) { if (window.shToast) window.shToast(err.message, 'error'); })
        .finally(function () { busy(el, false); });
      return;
    }

    /* ---- create a suggested category ---- */
    if (el.hasAttribute('data-ai-create-category')) {
      ev.preventDefault();
      var d = store['category'];
      if (!d) return;
      busy(el, true, 'Creating…');
      api({ action: 'create_category', name: d.suggested, ref_id: refId() })
        .then(function (j) {
          if (window.shToast) window.shToast(j.message, 'success');
          setTimeout(function () { location.reload(); }, 900);
        })
        .catch(function (err) { if (window.shToast) window.shToast(err.message, 'error'); })
        .finally(function () { busy(el, false); });
      return;
    }

    /* ---- settings: test connection ---- */
    if (el.hasAttribute('data-ai-test')) {
      ev.preventDefault();
      runProviderTest(el);
      return;
    }

    /* ---- blog ---- */
    if (el.hasAttribute('data-ai-blog-generate')) {
      ev.preventDefault();
      var get = function (k) { var f = document.querySelector('[data-ai-blog="' + k + '"]'); return f ? f.value : ''; };
      if (!get('topic').trim()) { if (window.shToast) window.shToast('Enter a topic first.', 'error'); return; }
      busy(el, true, 'Writing…');
      api({ action: 'generate', task: 'blog', ref_id: 0, topic: get('topic'),
            audience: get('audience'), keywords: get('keywords'), length: get('length') || '700' })
        .then(function (j) {
          store['blog'] = j.data;
          var box = document.querySelector('[data-ai-blog-preview]');
          var wrap = document.querySelector('[data-ai-blog-output]');
          if (box) {
            box.innerHTML = '<h3>' + esc(j.data.title) + '</h3>'
              + '<p><em>' + esc(j.data.excerpt) + '</em></p>' + (j.data.content || '')
              + '<hr><p><strong>Meta title:</strong> ' + esc(j.data.meta_title) + '</p>'
              + '<p><strong>Meta description:</strong> ' + esc(j.data.meta_description) + '</p>'
              + '<p><strong>Keywords:</strong> ' + esc(j.data.keywords) + '</p>';
          }
          if (wrap) wrap.hidden = false;
          if (window.shToast) window.shToast('Draft ready. Review it before saving.', 'success');
        })
        .catch(function (err) { if (window.shToast) window.shToast(err.message, 'error'); })
        .finally(function () { busy(el, false); });
      return;
    }

    var blogSave = el.getAttribute('data-ai-blog-save');
    if (blogSave) {
      ev.preventDefault();
      if (!store['blog']) { if (window.shToast) window.shToast('Generate a post first.', 'error'); return; }
      busy(el, true, 'Saving…');
      var f = { action: 'save_blog', data: JSON.stringify(store['blog']) };
      if (blogSave === 'publish') f.publish = '1';
      api(f).then(function (j) {
          if (window.shToast) window.shToast(j.message, 'success');
          setTimeout(function () { location.reload(); }, 1200);
        })
        .catch(function (err) { if (window.shToast) window.shToast(err.message, 'error'); })
        .finally(function () { busy(el, false); });
      return;
    }

    /* ---- image ---- */
    if (el.hasAttribute('data-ai-image-generate')) {
      ev.preventDefault();
      var pf2 = document.querySelector('[data-ai-image-prompt]');
      var tgt = document.querySelector('[data-ai-image-target]');
      var tv = tgt && tgt.value ? tgt.value.split('|') : ['', ''];
      if (tgt && !tgt.value && tgt.options[0] && tgt.options[0].disabled) {
        if (window.shToast) window.shToast('This provider/model does not support image generation. Choose a compatible provider first.', 'error');
        return;
      }
      busy(el, true, 'Generating image…');
      api({ action: 'generate', task: 'image', ref_id: refId(), prompt: pf2 ? pf2.value : '', provider_id: tv[0] || '', model: tv[1] || '' })
        .then(function (j) {
          store['image'] = j.data;
          var img = document.querySelector('[data-ai-image-preview]');
          var wrap = document.querySelector('[data-ai-image-output]');
          if (img) img.src = j.url;
          if (wrap) wrap.hidden = false;
          if (pf2 && !pf2.value.trim() && j.data.prompt) { pf2.value = j.data.prompt; growTextarea(pf2); }
          var viaMsg = j.via && j.via.provider ? ' (' + j.via.provider + ' · ' + j.via.model + (j.via.fallback ? ', fallback' : '') + ')' : '';
          if (window.shToast) window.shToast('Image generated' + viaMsg + '. It is not attached until you confirm.', 'success');
        })
        .catch(function (err) { if (window.shToast) window.shToast(err.message, 'error'); })
        .finally(function () { busy(el, false); });
      return;
    }

    if (el.hasAttribute('data-ai-image-use')) {
      ev.preventDefault();
      if (!store['image']) return;
      if (!confirm('Replace the current product image with this generated one?')) return;
      busy(el, true, 'Saving…');
      api({ action: 'save', task: 'image', ref_id: refId(), data: JSON.stringify(store['image']) })
        .then(function () {
          if (window.shToast) window.shToast('Product image updated.', 'success');
          setTimeout(function () { location.reload(); }, 900);
        })
        .catch(function (err) { if (window.shToast) window.shToast(err.message, 'error'); })
        .finally(function () { busy(el, false); });
      return;
    }

    /* ---- bulk ---- */
    if (el.hasAttribute('data-ai-bulk-start')) { ev.preventDefault(); bulkStart(el); return; }
    if (el.hasAttribute('data-ai-retry')) { ev.preventDefault(); bulkRetry(el); return; }
  });

  /* ---------- bulk queue ---------- */
  var batchId = null;

  function selectedProducts() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-ai-product-check]:checked'))
      .map(function (c) { return c.value; });
  }
  function selectedTasks() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-ai-task]:checked'))
      .map(function (c) { return c.value; });
  }
  function updateCount() {
    var n = selectedProducts().length;
    var el = document.querySelector('[data-ai-bulk-count]');
    if (el) el.textContent = n + ' product' + (n === 1 ? '' : 's') + ' selected';
  }
  document.addEventListener('change', function (ev) {
    if (ev.target.matches('[data-ai-product-check]')) updateCount();
  });
  var selAll = document.querySelector('[data-ai-select-all]');
  var selNone = document.querySelector('[data-ai-select-none]');
  if (selAll) selAll.addEventListener('click', function () {
    document.querySelectorAll('[data-ai-product-check]').forEach(function (c) { c.checked = true; });
    updateCount();
  });
  if (selNone) selNone.addEventListener('click', function () {
    document.querySelectorAll('[data-ai-product-check]').forEach(function (c) { c.checked = false; });
    updateCount();
  });

  function setProgress(p) {
    var wrap = document.querySelector('[data-ai-progress]');
    var fill = document.querySelector('[data-ai-progress-fill]');
    var text = document.querySelector('[data-ai-progress-text]');
    var retry = document.querySelector('[data-ai-retry]');
    if (!wrap) return;
    wrap.hidden = false;
    if (fill) fill.style.width = (p.percent || 0) + '%';
    if (text) {
      text.textContent = 'Processing ' + (p.completed + p.failed) + ' / ' + p.total
        + '  ·  ' + p.completed + ' completed, ' + p.failed + ' failed';
    }
    if (retry) retry.hidden = !(p.finished && p.failed > 0);
  }

  function bulkStart(btn) {
    var products = selectedProducts();
    var tasks = selectedTasks();
    if (!products.length) { if (window.shToast) window.shToast('Select at least one product.', 'error'); return; }
    if (!tasks.length) { if (window.shToast) window.shToast('Select at least one task.', 'error'); return; }
    var jobs = products.length * tasks.length;
    if (!confirm('This will send ' + jobs + ' AI request' + (jobs === 1 ? '' : 's')
        + ' and may cost money. Results are written straight to the products. Continue?')) return;

    busy(btn, true, 'Queueing…');
    var bp = document.querySelector('[data-ai-bulk-provider]');
    var bm = document.querySelector('[data-ai-bulk-model]');
    api({ action: 'bulk_queue', products: products, tasks: tasks, provider_id: bp ? bp.value : '0', model: bm && !bm.disabled ? bm.value : '' })
      .then(function (j) {
        batchId = j.batch_id;
        setProgress({ total: j.total, completed: 0, failed: 0, percent: 0, finished: false });
        return pump();
      })
      .catch(function (err) { if (window.shToast) window.shToast(err.message, 'error'); })
      .finally(function () { busy(btn, false); });
  }

  /* Process a few jobs per request so nothing times out and calls stay serialised. */
  function pump() {
    if (!batchId) return Promise.resolve();
    return api({ action: 'bulk_process', batch_id: batchId }).then(function (p) {
      setProgress(p);
      if (!p.finished) { return new Promise(function (r) { setTimeout(r, 400); }).then(pump); }
      if (window.shToast) {
        window.shToast('Batch finished: ' + p.completed + ' completed, ' + p.failed + ' failed.',
          p.failed ? 'error' : 'success');
      }
    }).catch(function (err) {
      if (window.shToast) window.shToast(err.message, 'error');
    });
  }

  function bulkRetry(btn) {
    if (!batchId) return;
    busy(btn, true, 'Retrying…');
    api({ action: 'bulk_retry', batch_id: batchId })
      .then(function (p) { setProgress(p); return pump(); })
      .catch(function (err) { if (window.shToast) window.shToast(err.message, 'error'); })
      .finally(function () { busy(btn, false); });
  }

  updateCount();

  /* A provider saved with "Save & test" redirects with ?run_test=1. Run that
     one test automatically; the button stays available either way. */
  var auto = document.querySelector('[data-ai-test-autorun]');
  if (auto) {
    var autoId = auto.getAttribute('data-ai-test-autorun');
    var autoBtn = document.querySelector('[data-ai-test][data-provider="' + autoId + '"]');
    if (autoBtn && !autoBtn.disabled) { setTimeout(function () { autoBtn.click(); }, 120); }
  }
})();

/* ===================================================================
   Phone OTP verification (signup/login modal) + signup "Connect".
   Self-contained IIFE that runs on EVERY page (storefront included).
   Uses window.shApi / window.shToast from the main app script.
   =================================================================== */
(function () {
  var BASE = window.SH_BASE || '/';
  function url(p) { return BASE + String(p).replace(/^\//, ''); }
  function api(endpoint, data) { return window.shApi(endpoint, data); }

  /* ---------- Phone OTP verification (signup/login modal) -------------- */
  var otpModal = document.querySelector('[data-otp-modal]');
  var otp = document.querySelector('[data-otp]');
  if (otp && otpModal) {
    var otpPurpose = otp.getAttribute('data-purpose');
    var otpLength = parseInt(otp.getAttribute('data-length'), 10) || 6;
    var otpCooldown = parseInt(otp.getAttribute('data-cooldown'), 10) || 0;
    var sendBtn = otp.querySelector('[data-otp-send]');
    var sendLabel = otp.querySelector('[data-otp-send-label]');
    var verifyBtn = otp.querySelector('[data-otp-verify]');
    var verifyLabel = otp.querySelector('[data-otp-verify-label]');
    var verifyLabelText = verifyLabel ? verifyLabel.textContent : '';
    var boxes = Array.prototype.slice.call(otp.querySelectorAll('[data-otp-digit]'));
    var errBox = otp.querySelector('[data-otp-error]');
    var countdownTimer = null;

    function openModal() {
      otpModal.style.top = window.scrollY + 'px';
      otpModal.removeAttribute('hidden');
      document.body.classList.add('sh-modal-open');
      if (boxes[0]) { boxes[0].focus(); }
    }
    function closeModal() {
      otpModal.setAttribute('hidden', '');
      document.body.classList.remove('sh-modal-open');
    }

    function showError(msg) {
      if (!errBox) return;
      errBox.querySelector('span').textContent = msg;
      errBox.removeAttribute('hidden');
    }
    function clearError() { if (errBox) { errBox.setAttribute('hidden', ''); } }

    function codeValue() {
      return boxes.map(function (b) { return (b.value || '').replace(/\D/g, ''); }).join('');
    }
    function clearBoxes() { boxes.forEach(function (b) { b.value = ''; }); }

    function startCountdown(seconds) {
      if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
      if (!sendBtn || seconds <= 0) return;
      var left = seconds;
      sendBtn.disabled = true;
      var tick = function () {
        if (sendLabel) { sendLabel.textContent = 'Resend in ' + left + 's'; }
        if (left <= 0) {
          clearInterval(countdownTimer);
          countdownTimer = null;
          sendBtn.disabled = false;
          if (sendLabel) { sendLabel.textContent = 'Resend code'; }
          return;
        }
        left -= 1;
      };
      tick();
      countdownTimer = setInterval(tick, 1000);
    }

    function otpSend() {
      clearError();
      if (sendBtn) { sendBtn.disabled = true; }
      if (sendLabel) { sendLabel.textContent = 'Sending…'; }
      api('otp.php', { action: 'send', purpose: otpPurpose })
        .then(function (r) {
          if (!r.success) {
            showError(r.error || 'Could not send the code.');
            if (sendBtn) { sendBtn.disabled = false; }
            if (sendLabel) { sendLabel.textContent = 'Resend code'; }
            return;
          }
          clearBoxes();
          if (boxes[0]) { boxes[0].focus(); }
          // startCountdown() keeps the resend button disabled until the cooldown ends.
          startCountdown(otpCooldown > 0 ? otpCooldown : 60);
          if (window.shToast) { window.shToast(r.message || 'Code sent.', 'success'); }
        })
        .catch(function (e) {
          showError(e.message);
          if (sendBtn) { sendBtn.disabled = false; }
          if (sendLabel) { sendLabel.textContent = 'Resend code'; }
        });
    }

    function otpVerify() {
      clearError();
      var code = codeValue();
      if (code.length < otpLength) { showError('Enter the ' + otpLength + '-digit code.'); return; }
      if (verifyBtn) { verifyBtn.disabled = true; }
      if (verifyLabel) { verifyLabel.textContent = 'Verifying…'; }
      api('otp.php', { action: 'verify', purpose: otpPurpose, code: code })
        .then(function (r) {
          if (!r.success) { showError(r.error || 'Incorrect code.'); return; }
          if (r.redirect) { window.location.href = url(r.redirect); return; }
          if (window.shToast) { window.shToast('Phone verified.', 'success'); }
        })
        .catch(function (e) { showError(e.message); })
        .finally(function () {
          if (verifyBtn) { verifyBtn.disabled = false; }
          if (verifyLabel) { verifyLabel.textContent = verifyLabelText; }
        });
    }

    boxes.forEach(function (b, i) {
      b.addEventListener('input', function () {
        b.value = b.value.replace(/\D/g, '').slice(0, 1);
        if (b.value && i < boxes.length - 1) { boxes[i + 1].focus(); }
      });
      b.addEventListener('keydown', function (ev) {
        if (ev.key === 'Backspace' && !b.value && i > 0) { boxes[i - 1].focus(); }
        if (ev.key === 'Enter') { ev.preventDefault(); otpVerify(); }
      });
      b.addEventListener('paste', function (ev) {
        ev.preventDefault();
        var txt = ((ev.clipboardData || window.clipboardData).getData('text') || '').replace(/\D/g, '').slice(0, otpLength);
        if (!txt) { return; }
        for (var k = 0; k < otpLength; k++) { boxes[k].value = txt[k] || ''; }
        (boxes[Math.min(txt.length, otpLength) - 1] || boxes[0]).focus();
      });
    });

    if (sendBtn) { sendBtn.addEventListener('click', otpSend); }
    if (verifyBtn) { verifyBtn.addEventListener('click', otpVerify); }
    Array.prototype.forEach.call(otpModal.querySelectorAll('[data-otp-modal-close]'), function (el) {
      el.addEventListener('click', closeModal);
    });

    if (otp.getAttribute('data-already-sent') === '1') {
      openModal();
      startCountdown(otpCooldown > 0 ? otpCooldown : 60);
    }

    // Expose a small API so the signup "Connect" button can open the modal
    // only after the backend confirms the OTP was requested (no page reload).
    window.shOtpModal = {
      open: openModal,
      close: closeModal,
      setPhone: function (phone, masked) {
        otp.setAttribute('data-phone', phone || '');
        var label = otp.querySelector('.sh-otp__phone-label strong');
        if (label && masked) { label.textContent = masked; }
      },
      clearBoxes: clearBoxes,
      startCooldown: function (seconds) { startCountdown(seconds); }
    };
  }

  /* ---------- Signup phone "Connect" (AJAX, no page reload) -------------- */
  var signupForm = document.querySelector('[data-signup-phone-form]');
  if (signupForm) {
    var connectBtn = signupForm.querySelector('[data-signup-connect]');
    var connectLabel = signupForm.querySelector('[data-signup-connect-label]');
    var signupError = signupForm.querySelector('[data-signup-error]');
    var nameInput = signupForm.querySelector('input[name="name"]');
    var phoneInput = signupForm.querySelector('input[name="phone"]');
    var termsBox = signupForm.querySelector('input[name="terms"]');
    var redirectInput = signupForm.querySelector('input[name="redirect"]');

    function signupShowError(msg) {
      if (!signupError) { return; }
      signupError.querySelector('span').textContent = msg;
      signupError.removeAttribute('hidden');
    }
    function signupClearError() { if (signupError) { signupError.setAttribute('hidden', ''); } }

    signupForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      signupClearError();

      var name = nameInput ? nameInput.value.trim() : '';
      var phone = phoneInput ? phoneInput.value.trim() : '';

      if (!termsBox || !termsBox.checked) { signupShowError('You must accept the terms and conditions.'); return; }
      if (!name) { signupShowError('Enter your full name.'); if (nameInput) { nameInput.focus(); } return; }
      if (name.length < 2) { signupShowError('Full name must be at least 2 characters.'); if (nameInput) { nameInput.focus(); } return; }
      if (name.length > 110) { signupShowError('Full name must be 110 characters or fewer.'); return; }
      if (!phone) { signupShowError('Enter your mobile number to continue.'); if (phoneInput) { phoneInput.focus(); } return; }

      if (connectBtn) { connectBtn.disabled = true; }
      if (connectLabel) { connectLabel.textContent = 'Sending code…'; }

      api('otp.php', {
        action: 'signup',
        purpose: 'signup',
        name: name,
        phone: phone,
        redirect: redirectInput ? redirectInput.value : '',
        terms: '1'
      }).then(function (r) {
        if (!r.success) { signupShowError(r.error || 'Could not send the code. Please try again.'); return; }
        signupClearError();
        if (window.shOtpModal) {
          window.shOtpModal.setPhone(r.phone, r.masked);
          window.shOtpModal.clearBoxes();
          window.shOtpModal.open();
          window.shOtpModal.startCooldown(r.cooldown > 0 ? r.cooldown : 60);
        } else {
          signupForm.submit(); // no modal available: fall back to the server flow
        }
      }).catch(function (e) {
        signupShowError(e.message || 'Could not send the code. Please try again.');
      }).finally(function () {
        if (connectBtn) { connectBtn.disabled = false; }
        if (connectLabel) { connectLabel.textContent = 'Continue'; }
      });
    });
  }

})();

/* Admin tables: copy each <th> text onto its cells so rows can stack into
   static blocks on phones (see .sh-admin-body .sh-table media query). */
(function () {
  if (!document.querySelector('.sh-admin')) { return; }
  document.querySelectorAll('.sh-admin .sh-table').forEach(function (table) {
    var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    if (!heads.length) { return; }
    table.querySelectorAll('tbody tr').forEach(function (tr) {
      if (tr.classList.contains('sh-table--empty')) { return; }
      Array.prototype.forEach.call(tr.children, function (td, i) {
        if (heads[i] && !td.hasAttribute('data-th')) { td.setAttribute('data-th', heads[i]); }
      });
    });
  });
})();

/* ===================================================================
   PAGE TRANSITION (customer website + admin panel)
   Sequence: click → page fades out + overlay fades in → navigate →
   (new page) overlay already visible from <head> hold → overlay fades out
   → page fades in. Only plain same-origin <a> clicks are intercepted;
   forms, AJAX, modals, dropdowns, downloads and external links are ignored.
   The overlay is always cleaned up: pageshow (bfcache), visibility, error,
   and hard timeouts, plus a pure-CSS release if JS never runs.
   =================================================================== */
(function () {
  var body = document.body;
  var docEl = document.documentElement;

  /* ---- settings page helpers (work even when transitions are off) ---- */
  var sel = document.querySelector('[data-transition-duration]');
  var custom = document.querySelector('[data-transition-custom]');
  if (sel && custom) sel.addEventListener('change', function () { custom.hidden = sel.value !== 'custom'; });
  var bgType = document.querySelector('[data-tr-bg-type]');
  if (bgType) {
    var syncBg = function () {
      document.querySelectorAll('[data-tr-when]').forEach(function (el) {
        el.hidden = el.getAttribute('data-tr-when').split(' ').indexOf(bgType.value) === -1;
      });
      var l = document.querySelector('[data-tr-c1-label]');
      if (l) l.textContent = bgType.value === 'gradient' ? 'Gradient colour 1' : 'Background colour';
    };
    bgType.addEventListener('change', syncBg);
  }
  var mediaMode = document.querySelector('[data-tr-media-mode]');
  var mediaFile = document.querySelector('[data-tr-media-file]');
  if (mediaMode && mediaFile) mediaMode.addEventListener('change', function () { mediaFile.hidden = mediaMode.value === 'none'; });
  document.querySelectorAll('[data-tr-picker]').forEach(function (pick) {
    var hex = document.getElementById(pick.getAttribute('data-tr-picker'));
    if (!hex) return;
    pick.addEventListener('input', function () { hex.value = pick.value; pick.parentNode.style.background = pick.value; });
    hex.addEventListener('input', function () {
      var v = hex.value.trim(); if (v && v[0] !== '#') v = '#' + v;
      if (/^#[0-9a-f]{6}$/i.test(v)) { pick.value = v; pick.parentNode.style.background = v; }
    });
  });

  if (!body) return;
  var overlay = document.getElementById('sh-pt');
  var enabled = body.getAttribute('data-transition') === '1' && !!overlay;
  var page = document.querySelector('.sh-admin-main') || document.querySelector('main.sh-main') || document.querySelector('main');
  if (page) page.classList.add('sh-pt-page');

  var duration = parseInt(body.getAttribute('data-transition-duration') || '450', 10);
  if (!(duration >= 150 && duration <= 5000)) duration = 450;
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var timers = [];
  var navigating = false;
  /*
   * Every transition owns a generation. A page load arms safety timers, and a
   * click that comes after them must not be cancelled by them: each deferred
   * callback only acts while its own generation is still the current one. That
   * is what stops a stale "release the page" timer from aborting a navigation
   * that started half a second ago (which silently swallowed the click).
   */
  var gen = 0;

  function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }
  function clearTimers() { timers.forEach(clearTimeout); timers = []; }
  function setPageMs(ms) { if (page) page.style.setProperty('--sh-pt-page-ms', ms + 'ms'); }
  function owned(g) { return g === undefined || g === gen; }
  /** setTimeout that only runs while generation g still owns the overlay. */
  function later(fn, ms, g) {
    var id = setTimeout(function () { if (owned(g)) { fn(); } }, ms);
    timers.push(id);
    return id;
  }

  function hide(g) {
    if (!owned(g)) return;
    clearTimers();
    navigating = false;
    // The <head> fallback must never fire after the real reveal has run.
    if (window.__shPtFallback) { clearTimeout(window.__shPtFallback); window.__shPtFallback = null; }
    docEl.classList.remove('sh-pt-hold');
    docEl.classList.remove('sh-pt-enter');
    if (overlay) { overlay.classList.remove('is-hold'); overlay.classList.remove('is-on'); }
    if (page) page.classList.remove('is-leaving');
  }
  function show(ms, g) {
    if (!overlay) return;
    overlay.classList.add('is-on');
    if (page) page.classList.add('is-leaving');
    later(hide, (ms || duration) + 8000, g); // safety net
  }
  function release(outMs, g) {
    // Overlay is visible (held); fade it out and bring the page in. Used by the
    // arrival, initial-load and settings-preview paths alike.
    if (!overlay || !owned(g)) return;
    if (window.__shPtFallback) { clearTimeout(window.__shPtFallback); window.__shPtFallback = null; }
    docEl.classList.add('sh-pt-enter');
    setPageMs(outMs);
    overlay.classList.remove('is-hold');
    docEl.classList.remove('sh-pt-hold');
    // Next frame: allow the transition to run from the visible state.
    requestAnimationFrame(function () { requestAnimationFrame(function () {
      overlay.classList.remove('is-on');
      if (page) page.classList.remove('is-leaving');
      later(function () { docEl.classList.remove('sh-pt-enter'); }, outMs + 50, g);
    }); });
  }

  /* ---- settings page "Play transition" preview ---- */
  var test = document.querySelector('[data-transition-test]');
  if (test && overlay) {
    test.addEventListener('click', function () {
      var ms = parseInt(test.getAttribute('data-transition-test-duration'), 10) || duration;
      var bg = test.getAttribute('data-transition-test-bg') || '';
      var media = test.getAttribute('data-transition-test-media') || '';
      var fade = test.getAttribute('data-transition-test-fade') || 'smooth';
      var saved = { cls: overlay.className, style: overlay.getAttribute('style'), html: overlay.innerHTML };
      overlay.className = 'sh-pt sh-pt--' + fade + (test.getAttribute('data-transition-test-dark') === '1' ? ' sh-pt--dark' : ' sh-pt--light');
      if (bg) overlay.style.setProperty('--sh-pt-bg', bg);
      overlay.innerHTML = '<div class="sh-pt__bg"></div>' + (media
        ? '<div class="sh-pt__media"><img alt="" decoding="async"></div>'
        : '<div class="sh-pt__mark"><span></span><span></span><span></span></div>');
      if (media) overlay.querySelector('img').setAttribute('src', media);
      setPageMs(clamp(ms * .45, 160, 600));
      var testGen = ++gen;
      show(ms, testGen);
      later(function () {
        release(clamp(ms * .5, 240, 800), testGen);
        later(function () {
          overlay.className = saved.cls; overlay.setAttribute('style', saved.style || ''); overlay.innerHTML = saved.html;
        }, 900, testGen);
      }, ms, testGen);
    });
  }

  if (!enabled) { docEl.classList.remove('sh-pt-hold'); return; }

  /* ---- arrival / initial load: finish what the <head> snippet started ----
     The <head> snippet holds the overlay for every kind of page load and tells
     us which one this is:

       __shPtArrived  an internal navigation — timed from the moment of the click
       __shPtCold     a first visit, refresh, bookmark, direct URL or new tab —
                      timed from the start of the document itself

     Only one is ever set, each is read once, and a stale marker can never hold
     the page. Nothing here is required for the overlay to disappear: the
     <head> snippet armed a fallback timer and the stylesheet carries a
     CSS-only release, so a failure in this file still reveals the page. */
  var arrived = window.__shPtArrived || null;
  if (!arrived) {
    // Defensive: if the <head> snippet was blocked (e.g. by a policy), the
    // single-use marker may still be sitting in sessionStorage.
    try {
      var raw = sessionStorage.getItem('sh-pt');
      sessionStorage.removeItem('sh-pt');
      if (raw) { arrived = JSON.parse(raw); }
    } catch (e) { arrived = null; }
  }
  var cold = window.__shPtCold || null;
  window.__shPtArrived = null;
  window.__shPtCold = null;
  if (window.__shPtFallback) { clearTimeout(window.__shPtFallback); window.__shPtFallback = null; }

  var outMs = clamp(duration * 0.5, 240, 800);
  var hold = arrived || cold;
  if (docEl.classList.contains('sh-pt-hold') && !reduced) {
    var loadGen = ++gen;
    overlay.classList.add('is-hold');
    overlay.classList.add('is-on');
    // Respect the configured total time without ever waiting on the page
    // itself: the page has already parsed here, so this is the remainder only.
    var startedAt = hold && hold.t ? hold.t : Date.now();
    var total = hold && hold.d ? hold.d : duration;
    var remaining = clamp(total - (Date.now() - startedAt), 0, duration);
    later(function () { release(outMs, loadGen); }, remaining, loadGen);
    later(function () { hide(loadGen); }, remaining + outMs + 4000, loadGen);
  } else {
    // Reduced motion, or a hold that was never applied. Deliberately no content
    // animation: animating a page that is already painted is what produced the
    // old flash of a fully rendered page on first load.
    hide();
  }

  // Back/forward cache restores the DOM with the overlay still "on".
  window.addEventListener('pageshow', function (ev) { if (ev.persisted || navigating) hide(); });
  window.addEventListener('pagehide', clearTimers);
  window.addEventListener('error', hide);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && !navigating) hide(); });

  function eligible(a, ev) {
    if (!a || ev.defaultPrevented || ev.button !== 0) return false;
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return false;
    if (a.target && a.target !== '_self') return false;
    if (a.hasAttribute('download') || a.hasAttribute('data-no-transition')) return false;
    if (a.getAttribute('role') === 'button' || a.hasAttribute('data-modal') || a.hasAttribute('data-modal-open') || a.hasAttribute('data-toggle') || a.hasAttribute('data-dropdown')) return false;
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel|sms|whatsapp):/i.test(href)) return false;
    var url;
    try { url = new URL(a.href, location.href); } catch (e) { return false; }
    if (url.origin !== location.origin) return false;
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return false;
    if (/logout\.php$/i.test(url.pathname)) return false;
    if (/\.(jpe?g|png|gif|webp|svg|pdf|zip|csv|xlsx?|docx?|mp4|mp3)$/i.test(url.pathname)) return false;
    if (/\/auth\/google\//i.test(url.pathname)) return false; // OAuth redirects leave the site
    return url;
  }

  document.addEventListener('click', function (ev) {
    var a = ev.target.closest ? ev.target.closest('a[href]') : null;
    var url = eligible(a, ev);
    if (!url) return;
    ev.preventDefault();
    if (navigating) return;
    if (reduced) { location.href = url.href; return; }
    navigating = true;
    // Leave phase: page fades out while the overlay fades in, then navigate.
    // The remaining visible time is served on the next page (see arrival).
    // Owning a fresh generation retires the page-load timers above, so a click
    // is never cancelled by the reveal that brought this page in.
    var clickGen = ++gen;
    var leaveMs = clamp(duration * 0.45, 160, 600);
    setPageMs(leaveMs);
    try { sessionStorage.setItem('sh-pt', JSON.stringify({ t: Date.now(), d: duration })); } catch (e) {}
    show(duration, clickGen);
    later(function () { location.href = url.href; }, leaveMs, clickGen);
    // If navigation is blocked (e.g. beforeunload cancel), recover.
    later(function () { try { sessionStorage.removeItem('sh-pt'); } catch (e) {} hide(clickGen); }, leaveMs + 6000, clickGen);
  });
})();

/* ===================================================================
   ADMIN — Customer Verification card: fills missing courier / IP data
   after the page has loaded (never during checkout) and handles the
   "Refresh" buttons. Server does all API work; nothing sensitive here.
   =================================================================== */
(function () {
  var box = document.querySelector('[data-cv-body]');
  if (!box) return;
  var busy = false;
  function load(refresh) {
    if (busy) return;
    busy = true;
    var fd = new FormData();
    fd.append('id', box.getAttribute('data-id'));
    fd.append('refresh', refresh || '');
    fd.append('csrf_token', window.SH_CSRF || '');
    if (refresh) {
      var cur = box.querySelector('[data-cv]');
      if (cur) cur.style.opacity = '.55';
    }
    fetch((window.SH_BASE || '/') + 'admin/verification-fetch.php', {
      method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) {
      // fetch follows the admin guard's redirect. Send the browser there rather
      // than placing a login document inside the verification card.
      if (r.status === 401 || /\/admin\/login\.php(?:$|[?#])/i.test(r.url || '')) {
        window.location.assign(r.url || ((window.SH_BASE || '/') + 'admin/login.php'));
        return '';
      }
      if (r.headers.get('X-ShopHaat-CSRF-Refresh') === '1') {
        window.location.reload();
        return '';
      }
      return r.text();
    }).then(function (html) {
      if (html && html.indexOf('data-cv') !== -1) box.innerHTML = html;
      else if (refresh && html) window.shToast && window.shToast('Verification could not be refreshed.', 'error');
    }).catch(function () {
      if (refresh && window.shToast) window.shToast('Verification could not be refreshed.', 'error');
    }).finally(function () {
      busy = false;
      var cur = box.querySelector('[data-cv]');
      if (cur) cur.style.opacity = '';
    });
  }
  var first = box.querySelector('[data-cv]');
  if (first && first.getAttribute('data-cv-pending') === '1') load('');
  document.querySelectorAll('[data-cv-refresh]').forEach(function (b) {
    b.addEventListener('click', function () { load(b.getAttribute('data-cv-refresh')); });
  });
})();

/* Admin notification bell dropdown (no popups, pure toggle) */
(function () {
  var bell = document.querySelector('[data-admin-bell]');
  if (!bell) { return; }
  var btn = bell.querySelector('[data-admin-bell-toggle]');
  var menu = bell.querySelector('.sh-admin-bell__menu');
  if (!btn || !menu) { return; }
  btn.addEventListener('click', function (ev) {
    ev.preventDefault();
    var open = menu.hasAttribute('hidden');
    if (open) { menu.removeAttribute('hidden'); } else { menu.setAttribute('hidden', ''); }
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.addEventListener('click', function (ev) {
    if (!bell.contains(ev.target)) { menu.setAttribute('hidden', ''); btn.setAttribute('aria-expanded', 'false'); }
  });
})();

/* Admin: success flashes as toasts; responsive table -> card labels */
(function () {
  var data = document.getElementById('sh-admin-toasts');
  if (data && window.shToast) {
    try { JSON.parse(data.textContent || '[]').forEach(function (m) { window.shToast(m, 'success'); }); } catch (e) {}
  }
  if (!document.querySelector('.sh-admin-body')) { return; }
  document.querySelectorAll('.sh-admin-body table.sh-table').forEach(function (table) {
    if (table.hasAttribute('data-no-cards')) { return; }
    var head = table.tHead;
    if (!head || !head.rows.length) { return; }
    var labels = Array.prototype.map.call(head.rows[0].cells, function (th) { return (th.textContent || '').trim(); });
    if (!labels.length) { return; }
    Array.prototype.forEach.call(table.tBodies, function (body) {
      Array.prototype.forEach.call(body.rows, function (row) {
        if (row.classList.contains('sh-table--empty')) { return; }
        Array.prototype.forEach.call(row.cells, function (td, i) {
          if (labels[i] && !td.hasAttribute('data-label')) { td.setAttribute('data-label', labels[i]); }
        });
      });
    });
  });
})();

/* ==========================================================================
   CSP-safe replacements for former inline event handlers.

   The Content Security Policy restricts script execution to this file plus a
   per-request nonce, so `onclick="…"` / `onchange="…"` attributes are no longer
   allowed anywhere. These delegated listeners keep the original behaviour:
   they run in the capture phase so the action happens before the element's own
   handlers, exactly like the attribute did.
   ========================================================================== */
(function () {
  'use strict';

  /* <input readonly data-select-on-click> — click selects the whole value so it
     can be copied, matching the previous onclick="this.select()". */
  document.addEventListener('click', function (ev) {
    var el = ev.target && ev.target.closest ? ev.target.closest('[data-select-on-click]') : null;
    if (el && typeof el.select === 'function') { el.select(); }
  }, true);

  /* <select data-navigate-on-change> — go to the chosen option's value. */
  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (!el || !el.hasAttribute || !el.hasAttribute('data-navigate-on-change')) { return; }
    if (el.value) { window.location.href = el.value; }
  }, true);

  /* <select data-navigate-prefix="…" data-navigate-suffix="…"> — build the
     target URL from the selected value (used for provider/SEO type pickers). */
  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (!el || !el.hasAttribute || !el.hasAttribute('data-navigate-prefix')) { return; }
    var target = el.getAttribute('data-navigate-prefix') + String(el.value)
               + (el.getAttribute('data-navigate-suffix') || '');
    if (el.value) { window.location.href = target; }
  }, true);

  /* <select data-submit-form-on-change> — submit the surrounding GET filter
     form. Admin write forms never carry this attribute (see the regression
     test); only the read-only AI model list uses it. */
  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (!el || !el.hasAttribute || !el.hasAttribute('data-submit-form-on-change')) { return; }
    var form = el.form || (el.closest ? el.closest('form') : null);
    if (form) { form.submit(); }
  }, true);
})();
