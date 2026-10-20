/**
 * AI Auto Work — "Generate Full Product".
 *
 * One product name in, one reviewed draft out, one Save Product click.
 *
 *   POST api/ai.php action=product_draft  → text + generated featured image
 *   POST api/ai.php action=save_product   → the single transaction that writes
 *                                           products + product_tags +
 *                                           product_images and returns the real
 *                                           saved id
 *
 * Everything is validated again on the server. This file never claims a save
 * succeeded on its own: it shows what the server answered, including the field
 * errors and the real error message when the transaction was rolled back.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-ai-create]');
  if (!root) { return; }

  var ENDPOINT = root.getAttribute('data-endpoint') || 'api/ai.php';
  var CSRF = window.SH_CSRF || (document.querySelector('input[name="csrf_token"]') || {}).value || '';

  var startForm = root.querySelector('[data-ai-create-start]');
  var reviewForm = root.querySelector('[data-ai-create-review]');
  var savedPanel = root.querySelector('[data-ai-create-saved]');
  var runBtn = root.querySelector('[data-ai-create-run]');
  var saveBtn = root.querySelector('[data-ai-save-product]');
  var genStatus = root.querySelector('[data-ai-create-status]');
  var saveStatus = root.querySelector('[data-ai-save-status]');
  var notice = root.querySelector('[data-ai-create-notice]');
  var notesBox = root.querySelector('[data-ai-notes]');
  var imageWrap = root.querySelector('[data-ai-image]');
  var imageEmpty = root.querySelector('[data-ai-image-empty]');
  var imageNote = root.querySelector('[data-ai-image-note]');
  var imageFile = root.querySelector('[data-ai-image-file]');
  var upload = root.querySelector('[data-ai-upload]');
  var galleryBox = root.querySelector('[data-ai-gallery]');
  var catNote = root.querySelector('[data-ai-category-note]');

  var draft = null;          // last server draft (token included)
  var generating = false;
  var saving = false;
  var uploadedFile = null;   // a file the admin picked for this draft

  function field(name) { return root.querySelector('[data-f="' + name + '"]'); }

  function setStatus(el, text, tone) {
    if (!el) { return; }
    el.textContent = text || '';
    el.className = 'sh-aicreate__status' + (tone ? ' sh-aicreate__status--' + tone : '');
  }

  function showNotice(kind, html) {
    if (!notice) { return; }
    notice.hidden = !html;
    notice.className = 'sh-aicreate__notice' + (kind ? ' sh-aicreate__notice--' + kind : '');
    notice.innerHTML = html || '';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function post(fields) {
    var body = new FormData();
    body.append('csrf_token', CSRF);
    Object.keys(fields).forEach(function (k) {
      var v = fields[k];
      if (v instanceof File) { body.append(k, v, v.name); }
      else if (v !== null && v !== undefined) { body.append(k, v); }
    });
    return fetch(ENDPOINT, {
      method: 'POST', body: body, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) {
      return r.text().then(function (t) {
        var j;
        try { j = JSON.parse(t); }
        catch (e) { throw new Error('The server returned an unexpected response. Nothing was saved.'); }
        if (window.shHandleSessionExpired && window.shHandleSessionExpired(j)) {
          throw new Error(j.error || 'Your session has expired. Sign in again.');
        }
        if (window.shHandleCsrfFailure && window.shHandleCsrfFailure(j)) {
          throw new Error(j.error || 'This form has expired. Reload the page and try again.');
        }
        if (!j.success) {
          var err = new Error(j.error || 'The request failed.');
          err.fields = j.fields || null;
          throw err;
        }
        return j;
      });
    });
  }

  /* ---------------------------------------------------------------- render */

  function applyDraft(j) {
    draft = j.data || {};
    var d = draft;

    // Only real form fields are written, and every one is editable afterwards.
    ['name', 'slug', 'sku', 'category_id', 'brand_id',
     'short_description', 'description', 'specifications', 'features',
     'meta_title', 'meta_description', 'focus_keyword', 'related_phrases',
     'keywords', 'image_alt'].forEach(function (k) {
      var el = field(k);
      if (!el) { return; }
      var v = d[k];
      el.value = v == null ? '' : String(v);
      el.classList.remove('sh-input--error');
    });

    var tagsEl = field('tags');
    if (tagsEl) { tagsEl.value = (d.tags || []).join(', '); }

    // The catalogue's own rules: the AI only ever matched an EXISTING category.
    if (catNote) {
      if (d.category_id) {
        catNote.hidden = true;
        catNote.textContent = '';
      } else if (d.category_name) {
        catNote.hidden = false;
        catNote.textContent = 'No existing category matches “' + d.category_name + '”. '
          + 'Choose the closest real category, or create it first.';
      } else {
        catNote.hidden = false;
        catNote.textContent = 'The AI did not find a suitable category. Choose one.';
      }
    }

    // Notes the model flagged for a human to check.
    var notes = d.notes || [];
    if (notesBox) {
      notesBox.hidden = notes.length === 0;
      notesBox.innerHTML = notes.length
        ? '<strong>Check before saving</strong><ul>' + notes.map(function (n) {
            return '<li>' + esc(n) + '</li>';
          }).join('') + '</ul>'
        : '';
    }

    // ---- image ----
    var image = j.image || null;
    uploadedFile = null;
    if (upload) { upload.value = ''; }
    if (image && image.ok && image.url) {
      imageWrap.src = image.url;
      imageWrap.hidden = false;
      imageEmpty.hidden = true;
      imageFile.value = image.file || '';
      if (imageNote) {
        imageNote.textContent = 'Generated for this draft and stored in uploads/products. '
          + 'Replace it below if you prefer your own photo.';
      }
      if (j.image_used) {
        imageNote.textContent += ' The source photo was also visible to the model.';
      }
    } else {
      imageWrap.hidden = true;
      imageWrap.removeAttribute('src');
      imageEmpty.hidden = false;
      imageFile.value = '';
      if (imageNote) {
        imageNote.textContent = image && image.error
          ? 'Image generation failed: ' + image.error
          : 'No image was generated.';
      }
      if (image && image.error) {
        showNotice('warn', '<strong>The draft text is ready, but the product image could not be generated.</strong> '
          + esc(image.error) + ' Use <em>Generate image</em> to retry, or upload your own photo — '
          + 'the product can be saved once you have one, or save it without an image.');
      }
    }

    // ---- gallery ----
    var gallery = j.gallery || [];
    if (galleryBox) {
      galleryBox.hidden = gallery.length === 0;
      galleryBox.innerHTML = gallery.map(function (g) {
        return '<img src="' + esc(g.url) + '" alt="" loading="lazy">';
      }).join('');
      galleryBox.setAttribute('data-files', JSON.stringify(gallery));
    }

    reviewForm.hidden = false;
    savedPanel.hidden = true;
    reviewForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  /* -------------------------------------------------------------- generate */

  startForm.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (generating) { return; }
    var name = (startForm.querySelector('[name="name"]') || {}).value || '';
    if (!name.trim()) {
      setStatus(genStatus, 'Enter the product name first.', 'error');
      return;
    }

    generating = true;
    runBtn.disabled = true;
    runBtn.dataset.prev = runBtn.innerHTML;
    runBtn.innerHTML = 'Generating…';
    setStatus(genStatus, 'Working. A full draft and its image can take up to a minute.', 'busy');
    showNotice('', '');

    var notes = (startForm.querySelector('[name="notes"]') || {}).value || '';
    var wantGallery = !!(startForm.querySelector('[data-ai-want-gallery]') || {}).checked;

    post({
      action: 'product_draft',
      name: name,
      known: JSON.stringify(notes.trim() ? { notes: notes.trim() } : {}),
      want_image: '1',
      want_gallery: wantGallery ? '1' : '0'
    }).then(function (j) {
      applyDraft(j);
      var imageOk = j.image && j.image.ok;
      setStatus(genStatus, imageOk
        ? 'Draft ready for review — the image was generated too.'
        : 'Draft ready for review. The image did not generate; see the notice below.', imageOk ? 'ok' : 'error');
    }).catch(function (err) {
      setStatus(genStatus, err.message || 'Generation failed.', 'error');
      showNotice('bad', '<strong>Nothing was generated.</strong> ' + esc(err.message || ''));
    }).finally(function () {
      generating = false;
      runBtn.disabled = false;
      if (runBtn.dataset.prev) { runBtn.innerHTML = runBtn.dataset.prev; }
    });
  });

  /* ------------------------------------------------------- image retry/upload */

  root.querySelector('[data-ai-image-retry]').addEventListener('click', function () {
    if (!draft || generating) { return; }
    var btn = this;
    btn.disabled = true;
    btn.dataset.prev = btn.innerHTML;
    btn.innerHTML = 'Generating…';
    setStatus(genStatus, 'Asking the image model for a new photo…', 'busy');

    // The draft already exists; only the picture is missing. Ask the dedicated
    // image action so no text is regenerated and no tokens are spent twice.
    var catEl = field('category_id');
    post({
      action: 'product_image',
      name: (field('name') || {}).value || (startForm.querySelector('[name="name"]') || {}).value || '',
      data: JSON.stringify({
        title: (field('name') || {}).value || '',
        short_description: (field('short_description') || {}).value || '',
        category_name: (catEl && catEl.selectedOptions && catEl.selectedOptions[0])
          ? catEl.selectedOptions[0].textContent : ''
      })
    }).then(function (j) {
      if (j.file && j.url) {
        imageWrap.src = j.url;
        imageWrap.hidden = false;
        imageEmpty.hidden = true;
        imageFile.value = j.file;
        if (imageNote) { imageNote.textContent = 'A new image was generated for this draft.'; }
        showNotice('', '');
        setStatus(genStatus, 'Image generated.', 'ok');
      } else {
        var msg = j.error || 'The image model returned no image.';
        if (imageNote) { imageNote.textContent = 'Image generation failed: ' + msg; }
        showNotice('warn', '<strong>The image could not be generated.</strong> ' + esc(msg)
          + ' Retry, or upload your own photo below.');
        setStatus(genStatus, 'Image generation failed.', 'error');
      }
    }).catch(function (err) {
      setStatus(genStatus, err.message || 'Image generation failed.', 'error');
      showNotice('warn', '<strong>The image could not be generated.</strong> ' + esc(err.message || ''));
    }).finally(function () {
      btn.disabled = false;
      if (btn.dataset.prev) { btn.innerHTML = btn.dataset.prev; }
    });
  });

  if (upload) {
    upload.addEventListener('change', function () {
      var file = upload.files && upload.files[0];
      if (!file) { return; }
      if (!/^image\//.test(file.type)) {
        showNotice('warn', 'Choose a JPG, PNG, WebP or GIF image.');
        upload.value = '';
        return;
      }
      uploadedFile = file;
      imageFile.value = '';                 // the uploaded file wins on save
      imageWrap.src = URL.createObjectURL(file);
      imageWrap.hidden = false;
      imageEmpty.hidden = true;
      if (imageNote) { imageNote.textContent = 'Your own photo will be stored when you save.'; }
      showNotice('', '');
    });
  }

  /* ------------------------------------------------------------------ save */

  function collect() {
    var out = {
      token: draft ? draft.token : '',
      image_file: imageFile.value || '',
      gallery_files: []
    };
    ['name', 'slug', 'sku', 'category_id', 'brand_id', 'price', 'compare_price',
     'stock', 'short_description', 'description', 'specifications', 'features',
     'meta_title', 'meta_description', 'focus_keyword', 'related_phrases',
     'keywords', 'image_alt'].forEach(function (k) {
      var el = field(k);
      out[k] = el ? el.value : '';
    });
    var tagsEl = field('tags');
    out.tags = tagsEl ? tagsEl.value.split(',') : [];
    var statusEl = field('status');
    out.status = statusEl && statusEl.checked ? 1 : 0;

    try {
      out.gallery_files = JSON.parse(galleryBox.getAttribute('data-files') || '[]')
        .map(function (g) { return g.file; });
    } catch (e) { out.gallery_files = []; }
    return out;
  }

  reviewForm.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (saving) { return; }
    if (!draft || !draft.token) {
      showNotice('bad', 'Generate a draft first.');
      return;
    }

    saving = true;
    saveBtn.disabled = true;
    saveBtn.dataset.prev = saveBtn.innerHTML;
    saveBtn.innerHTML = 'Saving…';
    setStatus(saveStatus, 'Writing the product, its tags and its images…', 'busy');
    showNotice('', '');

    var payload = collect();
    var request = { action: 'save_product', data: JSON.stringify(payload) };
    if (uploadedFile) { request.image = uploadedFile; }

    post(request).then(function (j) {
      reviewForm.hidden = true;
      savedPanel.hidden = false;

      var msg = savedPanel.querySelector('[data-ai-saved-message]');
      if (msg) {
        msg.textContent = (j.already_saved ? 'This draft was already saved — no duplicate was created. ' : '')
          + 'Product #' + j.id + ' · ' + (j.message || '');
      }
      var edit = savedPanel.querySelector('[data-ai-saved-edit]');
      if (edit) { edit.href = j.admin_url || '#'; }
      var view = savedPanel.querySelector('[data-ai-saved-view]');
      if (view) { view.href = j.url || '#'; }

      var verify = savedPanel.querySelector('[data-ai-saved-verify]');
      if (verify && j.verify) {
        verify.textContent = 'Saved and verified in the database: '
          + (j.verify.name || '') + ', slug “' + (j.verify.slug || '') + '”, '
          + (j.verify.category_id ? 'category #' + j.verify.category_id : 'no category') + ', '
          + (j.verify.tag_count || 0) + ' tag(s), '
          + (j.verify.gallery_count || 0) + ' gallery image(s)'
          + (j.verify.image ? ', featured image stored' : ', no featured image') + '.';
      }

      // A save without an image is still a save, but it is never announced as
      // if the picture were part of it.
      var hasImage = !!(j.verify && j.verify.image);
      draft = null;
      setStatus(saveStatus, '', '');
      if (window.shToast) {
        window.shToast(hasImage
          ? 'Product saved with its featured image.'
          : 'Product saved without a featured image.', hasImage ? 'success' : 'warn');
      }
    }).catch(function (err) {
      var html = '<strong>Not saved — nothing was written.</strong> ' + esc(err.message || '');
      if (err.fields) {
        html += '<ul>';
        Object.keys(err.fields).forEach(function (k) {
          html += '<li>' + esc(err.fields[k]) + '</li>';
          var el = field(k);
          if (el) { el.classList.add('sh-input--error'); }
        });
        html += '</ul>';
        var first = Object.keys(err.fields)[0];
        var target = field(first);
        if (target) { target.focus(); }
      }
      showNotice('bad', html);
      setStatus(saveStatus, 'The product was not saved.', 'error');
    }).finally(function () {
      saving = false;
      saveBtn.disabled = false;
      if (saveBtn.dataset.prev) { saveBtn.innerHTML = saveBtn.dataset.prev; }
    });
  });

  /* --------------------------------------------------------------- discard */

  root.querySelector('[data-ai-discard]').addEventListener('click', function () {
    if (saving) { return; }
    draft = null;
    uploadedFile = null;
    reviewForm.reset();
    reviewForm.hidden = true;
    savedPanel.hidden = true;
    showNotice('', '');
    setStatus(genStatus, '', '');
    setStatus(saveStatus, '', '');
    var nameEl = startForm.querySelector('[name="name"]');
    if (nameEl) { nameEl.focus(); }
  });

  root.querySelector('[data-ai-saved-again]').addEventListener('click', function () {
    savedPanel.hidden = true;
    var nameEl = startForm.querySelector('[name="name"]');
    var notesEl = startForm.querySelector('[name="notes"]');
    if (nameEl) { nameEl.value = ''; nameEl.focus(); }
    if (notesEl) { notesEl.value = ''; }
    startForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
})();
