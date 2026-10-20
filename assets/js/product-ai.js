/**
 * Add / Edit Product — "Generate Complete Product with AI".
 *
 * This script ONLY fills the existing form. It never submits it, never saves,
 * and never touches price, compare-at price, stock, stock status, flash-sale or
 * visibility controls. The administrator still has to press Save Product.
 *
 * All AI work happens server-side (api/ai.php → action=product_draft); the API
 * key never reaches this file or any response it reads.
 */
(function () {
  'use strict';

  var panel = document.querySelector('[data-product-ai]');
  if (!panel) { return; }

  var ENDPOINT = panel.getAttribute('data-ai-endpoint') || '';
  var VISION = panel.getAttribute('data-ai-vision') === '1';
  var CSRF = window.SH_CSRF || (document.querySelector('input[name="csrf_token"]') || {}).value || '';
  var TIMEOUT_MS = 180000;

  var runBtn = panel.querySelector('[data-ai-draft-run]');
  var runAllBtn = panel.querySelector('[data-ai-draft-run-all]');
  var statusEl = panel.querySelector('[data-ai-draft-status]');
  var resultEl = panel.querySelector('[data-ai-draft-result]');
  var listEl = panel.querySelector('[data-ai-draft-list]');
  var metaEl = panel.querySelector('[data-ai-draft-meta]');
  var notesEl = panel.querySelector('[data-ai-draft-notes]');
  if (!runBtn) { return; }

  var busy = false;

  function $(sel) { return document.querySelector(sel); }

  function setStatus(text, tone) {
    if (!statusEl) { return; }
    statusEl.textContent = text || '';
    statusEl.className = 'sh-aidraft__status' + (tone ? ' sh-aidraft__status--' + tone : '');
  }

  function toast(msg, tone) {
    if (typeof window.shToast === 'function') { window.shToast(msg, tone || 'error'); }
  }

  function known() {
    var out = {};
    panel.querySelectorAll('[data-ai-known]').forEach(function (el) {
      var v = (el.value || '').trim();
      if (v !== '') { out[el.getAttribute('data-ai-known')] = v; }
    });
    // Brand and specifications already exist on the form: read them instead of
    // asking the administrator to type the same thing twice.
    var brand = $('#p-brand');
    if (brand && brand.selectedIndex > 0) {
      var txt = (brand.options[brand.selectedIndex].text || '').trim();
      if (txt !== '') { out.brand = txt; }
    }
    var specs = $('#p-spec');
    if (specs && (specs.value || '').trim() !== '') { out.specs = specs.value.trim(); }
    return out;
  }

  function buildBody(field) {
    var body = new FormData();
    body.append('csrf_token', CSRF);
    body.append('action', 'product_draft');
    body.append('name', ($('#p-name') || {}).value || '');
    body.append('short_description', ($('#p-short') || {}).value || '');
    body.append('known', JSON.stringify(known()));
    var idEl = $('#p-id');
    body.append('product_id', idEl ? idEl.value || '0' : '0');
    if (field) { body.append('field', field); }

    // The image is only attached when the configured model can actually read
    // it. The server checks again — this is about not uploading bytes nobody
    // will look at, not about trusting the flag.
    var fileEl = $('#p-img');
    var file = VISION && fileEl && fileEl.files ? fileEl.files[0] : null;
    if (file) { body.append('image', file, file.name); }
    return body;
  }

  function post(field) {
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = controller ? window.setTimeout(function () { controller.abort(); }, TIMEOUT_MS) : null;

    return fetch(ENDPOINT, {
      method: 'POST',
      body: buildBody(field),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      signal: controller ? controller.signal : undefined
    }).then(function (r) {
      return r.text().then(function (t) {
        var j;
        try { j = JSON.parse(t); }
        catch (e) { throw new Error('The server returned an unexpected response. Nothing was changed.'); }
        if (window.shHandleSessionExpired && window.shHandleSessionExpired(j)) {
          throw new Error(j.error || 'Your session has expired. Sign in again.');
        }
        if (window.shHandleCsrfFailure && window.shHandleCsrfFailure(j)) {
          throw new Error(j.error || 'This form has expired. Reload the page and try again.');
        }
        if (!j.success) { throw new Error(j.error || 'The AI request failed.'); }
        return j;
      });
    }).catch(function (err) {
      if (err && err.name === 'AbortError') {
        throw new Error('The AI request timed out. Nothing was changed — please try again.');
      }
      throw err;
    }).then(function (j) {
      if (timer) { window.clearTimeout(timer); }
      return j;
    }, function (err) {
      if (timer) { window.clearTimeout(timer); }
      throw err;
    });
  }

  /* ------------------------------------------------------------------ *
   * Applying values to the real form
   * ------------------------------------------------------------------ */

  var SEO_FIELDS = [
    ['meta_title', '[data-seo-field="title"]'],
    ['meta_description', '[data-seo-field="description"]'],
    ['focus_keyword', '[data-seo-field="focus"]'],
    ['related_phrases', '[data-seo-field="phrases"]'],
    ['keywords', '[data-seo-field="kw"]']
  ];

  function setInput(sel, value) {
    var el = $(sel);
    if (!el || value === undefined || value === null || value === '') { return false; }
    var v = String(value);
    if (el.tagName === 'SELECT') {
      var ok = Array.prototype.some.call(el.options, function (o) { return o.value === v; });
      if (!ok) { return false; }
    }
    if (el.value === v) { return true; }
    el.value = v;
    // seo.js listens for these: the search/social preview updates with the text
    // the admin can now see in the fields.
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }

  function apply(data, only) {
    var done = [];
    var skipped = [];

    function want(k) { return !only || only === k || (only === 'seo' && k === 'seo'); }
    function mark(k, label, ok, reason) {
      (ok ? done : skipped).push(reason ? label + ' — ' + reason : label);
    }

    if (want('title') && data.title) { mark('title', 'Title', setInput('#p-name', data.title)); }
    if (want('short') && data.short_description) { mark('short', 'Short description', setInput('#p-short', data.short_description)); }
    if (want('description') && data.description) { mark('description', 'Full description', setInput('#p-desc', data.description)); }
    if (want('specifications') && data.specifications) { mark('specifications', 'Specifications', setInput('#p-spec', data.specifications)); }

    if (want('category') && data.category_name) {
      if (data.category_id) {
        mark('category', 'Category', setInput('#p-cat', String(data.category_id)));
      } else {
        mark('category', 'Category', false, '“' + data.category_name + '” is not one of your categories');
      }
    }

    if (want('brand') && data.brand_name) {
      if (data.brand_id) {
        mark('brand', 'Brand', setInput('#p-brand', String(data.brand_id)));
      } else {
        mark('brand', 'Brand', false, '“' + data.brand_name + '” is not one of your brands');
      }
    }

    if (want('sku') && data.sku) {
      if (data.sku_unique === false) {
        mark('sku', 'SKU', false, 'that code is already used by another product');
      } else {
        mark('sku', 'SKU', setInput('#p-sku', data.sku));
      }
    }

    if (want('seo')) {
      var applied = 0;
      SEO_FIELDS.forEach(function (pair) {
        if (data[pair[0]] && setInput(pair[1], data[pair[0]])) { applied++; }
      });
      if (applied > 0) { done.push('SEO fields (' + applied + ')'); }
    }

    return { done: done, skipped: skipped };
  }

  /* ------------------------------------------------------------------ *
   * Result list
   * ------------------------------------------------------------------ */

  var ROWS = [
    { key: 'title', label: 'Product title', val: function (d) { return d.title; } },
    { key: 'short', label: 'Short description', val: function (d) { return d.short_description; } },
    { key: 'description', label: 'Full description', val: function (d) { return d.description; }, html: true },
    { key: 'specifications', label: 'Specifications', val: function (d) { return d.specifications; } },
    { key: 'category', label: 'Category', val: function (d) {
      if (!d.category_name) { return ''; }
      return d.category_id ? d.category_name : d.category_name + ' (not an existing category)';
    } },
    { key: 'brand', label: 'Brand', val: function (d) {
      if (!d.brand_name) { return ''; }
      return d.brand_id ? d.brand_name : d.brand_name + ' (not an existing brand)';
    } },
    { key: 'sku', label: 'SKU suggestion', val: function (d) {
      if (!d.sku) { return ''; }
      return d.sku + (d.sku_unique === false ? ' — already used, not applied' : ' — not used by another product');
    } },
    { key: 'seo', label: 'SEO fields', val: function (d) {
      var n = 0;
      SEO_FIELDS.forEach(function (p) { if (d[p[0]]) { n++; } });
      return n > 0 ? n + ' field' + (n === 1 ? '' : 's') + ' generated' : '';
    } },
    { key: 'image_alt', label: 'Image alt text (suggestion)', val: function (d) { return d.image_alt; } }
  ];

  function plain(html) {
    var d = document.createElement('div');
    d.innerHTML = html || '';
    return (d.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function render(data, only) {
    if (!resultEl || !listEl) { return; }
    listEl.innerHTML = '';

    ROWS.forEach(function (row) {
      if (only && only !== row.key && !(only === 'seo' && row.key === 'seo')) { return; }
      var raw = row.val(data) || '';
      var li = document.createElement('li');
      li.className = 'sh-aidraft__row' + (raw ? '' : ' sh-aidraft__row--empty');

      var name = document.createElement('span');
      name.className = 'sh-aidraft__name';
      name.textContent = row.label;

      var val = document.createElement('span');
      val.className = 'sh-aidraft__value';
      var text = row.html ? plain(raw) : String(raw);
      val.textContent = text === '' ? 'Not provided by the AI — the field keeps its current value.' : text;
      if (text.length > 160) { val.textContent = text.slice(0, 157) + '…'; }

      var actions = document.createElement('span');
      actions.className = 'sh-aidraft__actions';
      if (row.key !== 'image_alt') {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'sh-btn sh-btn--sm sh-btn--ghost';
        btn.setAttribute('data-ai-draft-field', row.key);
        btn.textContent = 'Regenerate this field';
        actions.appendChild(btn);
      }
      if (row.key === 'image_alt' && raw) {
        var copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'sh-btn sh-btn--sm sh-btn--ghost';
        copy.setAttribute('data-ai-copy-alt', raw);
        copy.textContent = 'Copy';
        actions.appendChild(copy);
      }

      li.appendChild(name);
      li.appendChild(val);
      li.appendChild(actions);
      listEl.appendChild(li);
    });

    if (metaEl) {
      var bits = [];
      if (data && data.__via) { bits.push('Generated by ' + data.__via); }
      bits.push(data && data.__imageUsed
        ? 'The product image was used as a visual reference.'
        : (data && data.__imageSupplied
            ? 'The image was NOT analysed (the model cannot read images).'
            : 'No image was sent.'));
      bits.push('Review every field, then save the product yourself.');
      metaEl.textContent = bits.join(' ');
    }

    if (notesEl) {
      var notes = (data && data.notes) || [];
      if (notes.length) {
        notesEl.hidden = false;
        notesEl.textContent = 'Check before saving: ' + notes.join(' · ');
      } else {
        notesEl.hidden = true;
        notesEl.textContent = '';
      }
    }

    resultEl.hidden = false;
  }

  /* ------------------------------------------------------------------ *
   * Running
   * ------------------------------------------------------------------ */

  function setBusy(on, message) {
    busy = on;
    panel.setAttribute('aria-busy', on ? 'true' : 'false');
    panel.querySelectorAll('[data-ai-draft-run], [data-ai-draft-run-all], [data-ai-draft-field]').forEach(function (b) {
      b.disabled = on;
    });
    if (runBtn) {
      runBtn.classList.toggle('is-busy', on);
      if (on) {
        runBtn.dataset.label = runBtn.textContent;
        runBtn.textContent = 'Generating…';
      } else if (runBtn.dataset.label) {
        runBtn.textContent = runBtn.dataset.label;
      }
    }
    if (on) { setStatus(message || 'Generating… this can take up to a minute.', 'busy'); }
  }

  function run(field) {
    if (busy) { return; }                       // no duplicate submissions
    if ((($('#p-name') || {}).value || '').trim() === '') {
      toast('Enter the product name first — it is the only required input.', 'error');
      var nameEl = $('#p-name');
      if (nameEl) { nameEl.focus(); }
      return;
    }

    setBusy(true, field ? 'Regenerating this field…' : 'Generating the product draft…');
    if (!field && resultEl) { resultEl.hidden = true; }

    post(field).then(function (j) {
      var data = j.data || {};
      var applied = apply(data, field);
      data.__via = j.via && j.via.provider ? j.via.provider + (j.via.model ? ' · ' + j.via.model : '') : '';
      data.__imageUsed = !!j.image_used;
      data.__imageSupplied = !!j.image_supplied;
      render(data, field);

      var msg;
      if (field) {
        msg = applied.done.length
          ? 'Field regenerated. Review it, then save the product.'
          : 'The AI returned nothing usable for this field — the form was not changed.';
      } else {
        msg = 'Draft ready: ' + (applied.done.length ? applied.done.join(', ') : 'nothing to fill') + '.';
      }
      if (applied.skipped.length) { msg += ' Not applied: ' + applied.skipped.join('; ') + '.'; }
      setStatus(msg, 'ok');
      toast(field ? 'Field regenerated for review.' : 'Product draft generated for review.', 'success');
    }).catch(function (err) {
      // Nothing stale is left behind: the result panel is closed and the message
      // says the form was not changed.
      if (resultEl) { resultEl.hidden = true; }
      setStatus((err && err.message ? err.message : 'The AI request failed.') + ' Nothing was changed.', 'error');
      toast((err && err.message ? err.message : 'The AI request failed.'), 'error');
    }).then(function () {
      setBusy(false);
    });
  }

  runBtn.addEventListener('click', function () { run(''); });
  if (runAllBtn) { runAllBtn.addEventListener('click', function () { run(''); }); }

  // Per-field regenerate + copy the alt-text suggestion.
  panel.addEventListener('click', function (ev) {
    var regen = ev.target.closest('[data-ai-draft-field]');
    if (regen) {
      run(regen.getAttribute('data-ai-draft-field'));
      return;
    }
    var copy = ev.target.closest('[data-ai-copy-alt]');
    if (copy) {
      var text = copy.getAttribute('data-ai-copy-alt') || '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { toast('Alt text copied.', 'success'); });
      }
    }
  });
})();
