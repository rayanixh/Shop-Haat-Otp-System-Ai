/**
 * Settings → Category Display.
 *
 * The only thing this script changes is the CSS variable the storefront grid
 * already uses (`--sh-cat-min`). The preview is an iframe of the real grid, so
 * what the administrator sees while dragging is exactly what the storefront
 * will render — no second copy of the layout to fall out of date.
 *
 * Progressive enhancement: without JavaScript the slider still posts normally
 * and the preview shows the saved size.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-category-display]');
  if (!root) { return; }

  var range = root.querySelector('[data-cat-scale]');
  var output = root.querySelector('[data-cat-scale-value]');
  var frame = root.querySelector('.sh-catdisplay__iframe');
  var shell = root.querySelector('[data-preview-shell]');
  var base = root.getAttribute('data-preview-base') || '';
  if (!range) { return; }

  var BASELINE_PX = 220;   // the original five-column desktop card width
  var MIN_PX = 88;
  var MAX_PX = 220;

  function clamp(v) {
    return Math.min(MAX_PX, Math.max(MIN_PX, v));
  }

  /** Same arithmetic the server uses (sh_category_card_min_width). */
  function pxFor(scale) {
    return clamp(Math.round(BASELINE_PX * scale / 100));
  }

  function readScale() {
    var v = parseInt(range.value, 10);
    if (isNaN(v) || v < 40 || v > 100) { v = 50; }
    return v;
  }

  function paint(scale) {
    var px = pxFor(scale);
    if (output) { output.textContent = scale + '%'; }
    range.setAttribute('aria-valuetext', scale + ' percent');

    // Update the preview in place: no reload, no flicker while dragging.
    try {
      var doc = frame && frame.contentDocument;
      if (doc && doc.documentElement) {
        doc.documentElement.style.setProperty('--sh-cat-min', px + 'px');
        var note = doc.querySelector('.sh-catpreview__note');
        if (note) {
          note.innerHTML = note.innerHTML
            .replace(/Card width: <strong>\d+px minimum<\/strong>/, 'Card width: <strong>' + px + 'px minimum</strong>')
            .replace(/<strong>\d+%<\/strong>\s*$/, '<strong>' + scale + '%</strong>');
        }
      }
    } catch (e) {
      // Cross-document access can be blocked; the reload below still applies it.
    }
  }

  /** Keep the iframe URL shareable/refreshable, without reloading mid-drag. */
  function syncUrl(scale) {
    if (!frame || base === '') { return; }
    var want = base + '?scale=' + scale;
    if (frame.getAttribute('src') !== want) { frame.setAttribute('src', want); }
  }

  range.addEventListener('input', function () { paint(readScale()); });
  range.addEventListener('change', function () {
    var s = readScale();
    paint(s);
    syncUrl(s);
  });

  // Restore-default button returns the slider to 50% before the form posts;
  // the server writes the value, nothing here writes to storage.
  var restore = root.querySelector('button[name="restore_default"]');
  if (restore) {
    restore.addEventListener('click', function () { range.value = '50'; paint(50); });
  }

  // Desktop / tablet / phone widths inside the preview frame, so the
  // responsive breakpoints can be checked without resizing the browser.
  root.querySelectorAll('[data-preview-width]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      root.querySelectorAll('[data-preview-width]').forEach(function (b) { b.classList.remove('is-on'); });
      btn.classList.add('is-on');
      if (shell) { shell.style.maxWidth = btn.getAttribute('data-preview-width') || '100%'; }
    });
  });

  if (frame) {
    // After a reload (e.g. the URL sync below) re-apply the in-place value so
    // the frame and the slider never disagree.
    frame.addEventListener('load', function () { paint(readScale()); });
  }

  paint(readScale());
})();
