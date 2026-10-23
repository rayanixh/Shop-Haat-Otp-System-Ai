/**
 * Admin — category card size selector.
 *
 * The three choices are real radio inputs inside their labels, so the control
 * works with the keyboard and posts a plain value ("small" / "medium" /
 * "large") with the form. This script only mirrors the checked state onto the
 * tile so the chosen size is obvious before saving; the server validates the
 * value again and stores nothing else.
 */
(function () {
  'use strict';

  var group = document.querySelector('[data-cat-size-group]');
  if (!group) { return; }

  var options = Array.prototype.slice.call(group.querySelectorAll('.sh-catsize__opt'));

  function sync() {
    options.forEach(function (opt) {
      var input = opt.querySelector('[data-cat-size]');
      opt.classList.toggle('is-on', !!(input && input.checked));
    });
  }

  group.addEventListener('change', function (ev) {
    if (!ev.target || !ev.target.matches('[data-cat-size]')) { return; }
    sync();
  });

  // A radio restored by the browser (back/forward cache) must not show a stale
  // highlight, so the state is re-read whenever the page becomes visible again.
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { sync(); }
  });
  window.addEventListener('pageshow', sync);
  sync();
})();
