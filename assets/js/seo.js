/**
 * SEO field helpers for the admin product/category forms and the SEO Manager.
 *
 * Everything here is progressive enhancement: the fields are plain inputs that
 * work and save correctly with JavaScript switched off. This file only adds
 * an approximate search/social preview and a focus keyword note on top of
 * them. It never limits what can be typed or saved.
 *
 * No inline event handlers and no inline <script>, so it stays compatible with
 * the Content-Security-Policy the application sends.
 */
(function () {
  'use strict';

  var SH_BASE = typeof window.SH_BASE === 'string' ? window.SH_BASE : '/';

  /** Absolute URL for a value the owner typed: a full URL is kept, a path is
   *  resolved against the site root. A browser-generated blob URL (a file the
   *  admin just picked, not uploaded yet) is already usable as-is. */
  function absoluteUrl(value) {
    var v = (value || '').trim();
    if (v === '') { return ''; }
    if (/^(https?:|blob:)/i.test(v)) { return v; }
    if (v.charAt(0) === '/') { return SH_BASE.replace(/\/+$/, '') + v; }
    return SH_BASE + v.replace(/^\.?\//, '');
  }

  /** Normalise whitespace for display. The preview shows the administrator's
   *  words in full: SEO copy has no character limit here, in the form, or in
   *  the saved output, so nothing in this file shortens it. */
  function plain(text) {
    return (text || '').replace(/\s+/g, ' ').trim();
  }

  function setText(el, text) {
    if (el) { el.textContent = text; }
  }

  /**
   * The set of fields belonging to one SEO block. Each block is a panel with a
   * single set of fields, so two blocks on one page never share state.
   */
  function panelOf(field) {
    return field.closest('[data-seo-block]') || field.closest('.sh-panel') || document;
  }

  function initBlock(panel) {
    var titleInput = panel.querySelector('[data-seo-field="title"]');
    var descInput = panel.querySelector('[data-seo-field="description"]');
    var canonInput = panel.querySelector('[data-seo-field="canonical"]');
    var imageInput = panel.querySelector('[data-seo-field="image"]');
    var noindexInput = panel.querySelector('[data-seo-field="noindex"]');
    if (!titleInput && !descInput) { return; }

    // The entity's own picture (the product image), and the file input that can
    // replace it before the form is saved. Both are optional; without them the
    // preview simply has no fallback image, exactly as before.
    var entityImage = panel.getAttribute('data-seo-image') || '';
    var siteImage = panel.getAttribute('data-seo-default-image') || '';
    var entityFile = document.getElementById(panel.getAttribute('data-seo-image-input') || '');
    var entityCurrent = document.getElementById(panel.getAttribute('data-seo-image-current') || '');
    var pickedObjectUrl = '';

    var brand = (panel.getAttribute('data-seo-brand') || '').trim();
    var suffix = panel.getAttribute('data-seo-suffix') || (brand ? ' | ' + brand : '');

    var preview = {
      url: panel.querySelector('[data-seo-preview="url"]'),
      title: panel.querySelector('[data-seo-preview="title"]'),
      description: panel.querySelector('[data-seo-preview="description"]'),
      ogTitle: panel.querySelector('[data-seo-preview="og-title"]'),
      ogDescription: panel.querySelector('[data-seo-preview="og-description"]'),
      host: panel.querySelector('[data-seo-preview="host"]'),
      image: panel.querySelector('[data-seo-preview="image"]'),
      imageFallback: panel.querySelector('[data-seo-preview="image-fallback"]'),
      noindexNote: panel.querySelector('[data-seo-preview="noindex-note"]')
    };

    // The server-rendered fallbacks are kept as the "what happens if I leave it
    // empty" text, so clearing a field restores them instead of showing blank.
    var autoTitle = titleInput ? (titleInput.getAttribute('placeholder') || '') : '';
    var autoDesc = descInput ? (descInput.getAttribute('placeholder') || '') : '';
    var autoCanonical = canonInput ? (canonInput.getAttribute('data-seo-auto') || '') : '';
    var imageFallbackText = preview.imageFallback ? (preview.imageFallback.textContent || '').trim() : 'No image';

    function titleWithSuffix(raw) {
      var t = (raw || '').trim();
      if (t === '') { return ''; }
      // Mirrors the server rule: never repeat the store name or a separator.
      if (t.indexOf('|') !== -1) { return t; }
      if (brand !== '' && t.toLowerCase().indexOf(brand.toLowerCase()) !== -1) { return t; }
      return t + suffix;
    }

    function render() {
      var rawTitle = titleInput ? titleInput.value : '';
      var rawDesc = descInput ? descInput.value : '';
      var typed = rawTitle.trim() !== '';
      var shownTitle = plain(typed ? titleWithSuffix(rawTitle) : autoTitle);
      var shownDesc = plain(rawDesc.trim() !== '' ? rawDesc : autoDesc);

      setText(preview.title, shownTitle);
      setText(preview.ogTitle, shownTitle);
      setText(preview.description, shownDesc);
      setText(preview.ogDescription, shownDesc);

      // URL line.
      var canonTyped = canonInput ? canonInput.value.trim() : '';
      var shownCanon = canonTyped !== '' ? canonTyped : autoCanonical;
      setText(preview.url, shownCanon !== '' ? shownCanon : SH_BASE);
      if (preview.host) {
        var hostSource = shownCanon !== '' ? absoluteUrl(shownCanon) : SH_BASE;
        setText(preview.host, hostSource.replace(/^https?:\/\//i, '').split('/')[0]);
      }

      // Social image, with a plain-text fallback when there is none. Precedence
      // mirrors the server: a typed sharing image, then the file the admin has
      // just picked (not uploaded yet), then the entity's saved image.
      if (preview.image) {
        var typedImage = imageInput ? imageInput.value : '';
        var savedImage = entityImage;
        if (entityCurrent && entityCurrent.value === '') { savedImage = ''; }
        var fallbackImage = pickedObjectUrl !== '' ? pickedObjectUrl : (savedImage !== '' ? savedImage : siteImage);
        var src = absoluteUrl(typedImage !== '' ? typedImage : fallbackImage);
        if (src !== '') {
          if (preview.image.getAttribute('src') !== src) { preview.image.setAttribute('src', src); }
          preview.image.style.display = 'block';
          if (preview.imageFallback) { preview.imageFallback.style.display = 'none'; }
        } else {
          preview.image.removeAttribute('src');
          preview.image.style.display = 'none';
          if (preview.imageFallback) {
            setText(preview.imageFallback, imageFallbackText);
            preview.imageFallback.style.display = '';
          }
        }
      }

      // A hidden page is never offered to search engines; say so in the preview.
      if (preview.noindexNote) {
        preview.noindexNote.hidden = !(noindexInput && noindexInput.checked);
      }

      checkFocus();
    }

    /**
     * Focus keyword check. Deliberately advisory: it reports where the phrase
     * does and does not appear, and never claims a ranking or a "score".
     */
    function checkFocus() {
      var out = panel.querySelector('[data-seo-check="focus"]');
      if (!out) { return; }
      var focusInput = panel.querySelector('[data-seo-field="focus"]')
        || document.getElementById((panel.id || '').replace(/-panel$/, '') + '-focus');
      var phrase = focusInput ? focusInput.value.trim().toLowerCase() : '';
      if (phrase === '') {
        out.textContent = '';
        out.classList.remove('sh-seo-count--over', 'sh-seo-count--near');
        return;
      }
      var inTitle = (titleInput ? titleInput.value : '').toLowerCase().indexOf(phrase) !== -1;
      var inDesc = (descInput ? descInput.value : '').toLowerCase().indexOf(phrase) !== -1;
      var where = [];
      if (inTitle) { where.push('title'); }
      if (inDesc) { where.push('description'); }

      if (where.length === 2) {
        out.textContent = ' “' + focusInput.value.trim() + '” appears in the title and the description.';
        out.classList.remove('sh-seo-count--over');
      } else if (where.length === 1) {
        out.textContent = ' “' + focusInput.value.trim() + '” appears in the ' + where[0] + ' but not the other.';
        out.classList.remove('sh-seo-count--over');
      } else {
        out.textContent = ' “' + focusInput.value.trim() + '” is not in the title or the description.';
        out.classList.add('sh-seo-count--over');
      }
    }

    [titleInput, descInput, canonInput, imageInput].forEach(function (el) {
      if (!el) { return; }
      el.addEventListener('input', render);
      el.addEventListener('change', render);
    });

    // Choosing a replacement product image updates the preview immediately.
    // The file is not uploaded yet, so a local object URL is used — it points at
    // the browser's own copy, never at a server path.
    if (entityFile) {
      entityFile.addEventListener('change', function () {
        var file = entityFile.files && entityFile.files[0];
        if (pickedObjectUrl !== '' && window.URL && window.URL.revokeObjectURL) {
          window.URL.revokeObjectURL(pickedObjectUrl);
        }
        pickedObjectUrl = file && window.URL && window.URL.createObjectURL
          ? window.URL.createObjectURL(file)
          : '';
        render();
      });
    }
    var focusField = panel.querySelector('[data-seo-field="focus"]');
    if (focusField) { focusField.addEventListener('input', render); }
    if (noindexInput) { noindexInput.addEventListener('change', render); }

    // A sharing image that cannot load is shown as "unavailable" rather than as
    // a broken image icon. (No onerror attribute: it would need inline JS.)
    if (preview.image) {
      preview.image.addEventListener('error', function () {
        preview.image.style.display = 'none';
        if (preview.imageFallback) {
          setText(preview.imageFallback, 'That image could not be loaded');
          preview.imageFallback.style.display = '';
        }
      });
    }

    render();
  }

  function init() {
    document.querySelectorAll('[data-seo-field="title"], [data-seo-field="description"]').forEach(function (field) {
      var panel = panelOf(field);
      if (panel.dataset && panel.dataset.seoReady === '1') { return; }
      if (panel.dataset) { panel.dataset.seoReady = '1'; }
      initBlock(panel);
    });

    // SEO Manager: switching the page in the picker reloads the form with that
    // page's saved values (a GET, so it can be bookmarked or linked).
    document.querySelectorAll('[data-seo-page-picker]').forEach(function (picker) {
      picker.addEventListener('change', function () {
        var url = new URL(window.location.href);
        url.searchParams.set('page', picker.value);
        url.hash = '';
        window.location.href = url.toString();
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
