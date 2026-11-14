/* Shop Haat compact product gallery + structured colour editor.
   All DB persistence stays in PHP; this file only manages local previews,
   removal, manual ordering and file-list preparation. */
(function () {
  'use strict';

  var MAX_IMAGES = 5;
  var MAX_BYTES = 3145728;
  var ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  }

  function make(tag, className, text) {
    var el = document.createElement(tag);
    if (className) { el.className = className; }
    if (text !== undefined && text !== null) { el.textContent = String(text); }
    return el;
  }

  function initGallery(form, gallery) {
    var grid = gallery.querySelector('[data-pg-grid]');
    var inputWrap = gallery.querySelector('[data-pg-input-wrap]');
    var input = gallery.querySelector('[data-pg-input]');
    var count = gallery.querySelector('[data-pg-count]');
    var error = gallery.querySelector('[data-pg-error]');
    if (!grid || !inputWrap || !input) { return; }
    var pickerTemplate = input.cloneNode(false);
    var queue = [];
    var objectUrls = [];
    var supportsTransfer = typeof window.DataTransfer === 'function';
    if (!supportsTransfer) { input.removeAttribute('multiple'); }

    function setError(message) {
      if (!error) { return; }
      error.textContent = message || '';
      error.hidden = !message;
    }

    function activeTiles() {
      return Array.prototype.slice.call(grid.querySelectorAll('[data-pg-tile]'))
        .filter(function (tile) { return !tile.classList.contains('is-removed'); });
    }

    function orderedNewItems() {
      var ordered = [];
      Array.prototype.slice.call(grid.querySelectorAll('[data-pg-tile="new"]')).forEach(function (tile) {
        queue.forEach(function (item) { if (item.tile === tile) { ordered.push(item); } });
      });
      return ordered;
    }

    function tokenInput(tile, value, isNew) {
      var el = make('input');
      el.type = 'hidden';
      el.name = 'image_row[]';
      el.value = value;
      el.setAttribute('data-pg-token', '');
      if (isNew) { el.setAttribute('data-pg-new-token', ''); }
      tile.appendChild(el);
      return el;
    }

    function announcePrimary(tiles) {
      var first = tiles.length ? tiles[0] : null;
      var pickedFile = first && first._pickedFile ? first._pickedFile : null;
      if (typeof window.CustomEvent === 'function') {
        document.dispatchEvent(new CustomEvent('sh-product-image-picked', { detail: { file: pickedFile } }));
      }
    }

    function updateState() {
      var tiles = activeTiles();
      tiles.forEach(function (tile, i) {
        var primary = tile.querySelector('[data-pg-primary]');
        tile.classList.toggle('is-primary', i === 0);
        if (primary) { primary.hidden = i !== 0; }
      });
      if (count) { count.textContent = tiles.length + ' / ' + MAX_IMAGES + ' images'; }
      announcePrimary(tiles);
      return tiles.length;
    }

    function syncFiles() {
      if (!supportsTransfer || !input) { return; }
      try {
        var transfer = new window.DataTransfer();
        orderedNewItems().forEach(function (item) { transfer.items.add(item.file); });
        input.files = transfer.files;
      } catch (e) {
        supportsTransfer = false;
      }
    }

    function renumberNewTiles() {
      orderedNewItems().forEach(function (item, i) {
        item.token = 'n:' + i;
        item.tokenEl.value = item.token;
        item.tile.setAttribute('data-pg-token', item.token);
      });
      syncFiles();
    }

    function previewUrl(file) {
      if (!window.URL || !window.URL.createObjectURL) { return ''; }
      var url = window.URL.createObjectURL(file);
      objectUrls.push(url);
      return url;
    }

    function moveTile(tile, direction) {
      var tiles = activeTiles();
      var index = tiles.indexOf(tile);
      var target = index + direction;
      if (index < 0 || target < 0 || target >= tiles.length) { return; }
      // Use the active list, not adjacent DOM siblings: a removed tile stays in
      // the DOM for its checkbox but must not block manual reordering.
      if (direction < 0) { grid.insertBefore(tile, tiles[target]); }
      else { grid.insertBefore(tiles[target], tile); }
      renumberNewTiles();
      updateState();
    }

    function actionButton(label, className, handler) {
      var btn = make('button', 'sh-pg__btn' + (className ? ' ' + className : ''), label);
      btn.type = 'button';
      btn.addEventListener('click', handler);
      return btn;
    }

    function makeNewTile(file, fallbackInput) {
      var tile = make('div', 'sh-pg__tile');
      tile.setAttribute('data-pg-tile', 'new');
      var primary = make('span', 'sh-pg__primary', 'PRIMARY');
      primary.hidden = true;
      primary.setAttribute('data-pg-primary', '');
      var preview = make('div', 'sh-pg__preview');
      var img = make('img');
      img.alt = 'New image preview';
      img.src = previewUrl(file);
      preview.appendChild(img);
      var actions = make('div', 'sh-pg__actions');
      actions.appendChild(actionButton('←', '', function () { moveTile(tile, -1); }));
      actions.appendChild(actionButton('→', '', function () { moveTile(tile, 1); }));
      actions.appendChild(actionButton('Remove', 'sh-pg__btn--danger', function () {
        var index = queue.findIndex(function (item) { return item.tile === tile; });
        if (index !== -1) {
          var previewImg = queue[index].tile.querySelector('img');
          if (previewImg && previewImg.src && window.URL && window.URL.revokeObjectURL) {
            window.URL.revokeObjectURL(previewImg.src);
          }
          queue.splice(index, 1);
        }
        if (tile.parentNode) { tile.parentNode.removeChild(tile); }
        renumberNewTiles();
        updateState();
      }));
      tile.appendChild(primary);
      tile.appendChild(preview);
      tile.appendChild(actions);
      if (fallbackInput) {
        fallbackInput.removeAttribute('id');
        fallbackInput.removeAttribute('data-pg-input');
        fallbackInput.classList.add('sh-pg__input');
        fallbackInput.name = 'images[]';
        tile.appendChild(fallbackInput);
      }
      tile._pickedFile = file;
      var item = { file: file, tile: tile, token: '', tokenEl: null, fallbackInput: fallbackInput || null };
      item.tokenEl = tokenInput(tile, '', true);
      queue.push(item);
      return tile;
    }

    function validFile(file) {
      var ext = /\.(jpe?g|png|webp|gif)$/i.test(file.name || '');
      return (ALLOWED_TYPES.indexOf(file.type) !== -1 || (file.type === '' && ext)) && file.size <= MAX_BYTES;
    }

    function createPicker() {
      var next = pickerTemplate.cloneNode(false);
      next.value = '';
      next.name = 'images[]';
      next.setAttribute('data-pg-input', '');
      next.id = 'p-images';
      if (!supportsTransfer) { next.removeAttribute('multiple'); }
      next.addEventListener('change', onFilesPicked);
      inputWrap.appendChild(next);
      input = next;
    }

    function onFilesPicked(ev) {
      var picker = ev.currentTarget;
      var incoming = Array.prototype.slice.call(picker.files || []);
      var current = activeTiles().length;
      if (current + incoming.length > MAX_IMAGES) {
        setError('A product can have at most ' + MAX_IMAGES + ' images. Remove an image first or choose fewer files.');
        picker.value = '';
        syncFiles();
        return;
      }
      var rejected = 0;
      incoming.forEach(function (file) {
        if (!validFile(file)) {
          rejected++;
          return;
        }
        var fallbackInput = null;
        if (!supportsTransfer) {
          // Older browsers without DataTransfer keep each single-file input
          // inside its own preview tile; a fresh picker replaces it.
          fallbackInput = picker;
          if (fallbackInput.parentNode) { fallbackInput.parentNode.removeChild(fallbackInput); }
          createPicker();
        }
        grid.appendChild(makeNewTile(file, fallbackInput));
      });
      renumberNewTiles();
      if (supportsTransfer) {
        picker.value = '';
        syncFiles();
      }
      updateState();
      if (!supportsTransfer && rejected > 0) { picker.value = ''; }
      setError(rejected > 0 ? rejected + ' file(s) were not added. Use JPG, PNG, WebP or GIF up to 3 MB.' : '');
    }

    input.addEventListener('change', onFilesPicked);
    Array.prototype.slice.call(grid.querySelectorAll('[data-pg-tile="existing"]')).forEach(function (tile) {
      var remove = tile.querySelector('[data-pg-remove]');
      var removeBtn = tile.querySelector('[data-pg-remove-btn]');
      var replace = tile.querySelector('[data-pg-replace]');
      if (removeBtn && remove) {
        removeBtn.addEventListener('click', function () {
          remove.checked = !remove.checked;
          tile.classList.toggle('is-removed', remove.checked);
          if (replace) { replace.disabled = remove.checked; }
          removeBtn.textContent = remove.checked ? 'Undo' : 'Remove';
          removeBtn.setAttribute('aria-pressed', remove.checked ? 'true' : 'false');
          updateState();
        });
      }
      if (replace) {
        replace.addEventListener('change', function () {
          var file = replace.files && replace.files[0];
          tile._pickedFile = file || null;
          var img = tile.querySelector('.sh-pg__preview img');
          if (file && img && window.URL && window.URL.createObjectURL) {
            img.src = previewUrl(file);
          }
          updateState();
        });
      }
    });
    Array.prototype.slice.call(grid.querySelectorAll('[data-pg-move]')).forEach(function (btn) {
      var tile = btn.closest('[data-pg-tile]');
      if (!tile) { return; }
      btn.addEventListener('click', function () { moveTile(tile, parseInt(btn.getAttribute('data-pg-move'), 10) || 0); });
    });

    form.addEventListener('submit', function (ev) {
      var total = activeTiles().length;
      if (total < 1) {
        ev.preventDefault();
        setError('Upload at least one product image.');
      } else if (total > MAX_IMAGES) {
        ev.preventDefault();
        setError('A product can have at most ' + MAX_IMAGES + ' images.');
      }
    });
    window.addEventListener('beforeunload', function () {
      objectUrls.forEach(function (url) {
        if (window.URL && window.URL.revokeObjectURL) { window.URL.revokeObjectURL(url); }
      });
    });
    // The shared SEO preview initialises after this script. It asks for the
    // current primary instead of relying on script-load order; no timed rotation
    // is used to coordinate them.
    document.addEventListener('sh-product-image-picked-request', function () {
      announcePrimary(activeTiles());
    });
    renumberNewTiles();
    updateState();
  }

  function initColours(root) {
    var list = root.querySelector('[data-pc-custom]');
    var add = root.querySelector('[data-pc-add]');
    var template = root.querySelector('[data-pc-template]');
    var summary = root.querySelector('[data-pc-summary]');
    if (!list || !add || !template) { return; }

    function selectedNames() {
      var names = [];
      Array.prototype.slice.call(root.querySelectorAll('[data-colour-option]:checked')).forEach(function (input) {
        var label = input.closest('label');
        var name = input.getAttribute('data-colour-name');
        if (!name && label) { name = label.textContent.trim(); }
        if (name) { names.push(name); }
      });
      Array.prototype.slice.call(list.querySelectorAll('[data-pc-name]')).forEach(function (input) {
        var name = input.value.trim();
        if (name) { names.push(name); }
      });
      return names;
    }

    function updateSummary() {
      if (!summary) { return; }
      var names = selectedNames();
      summary.textContent = names.length ? 'Selected: ' + names.join(', ') : '';
    }

    function bindRow(row) {
      var name = row.querySelector('[data-pc-name]');
      var remove = row.querySelector('[data-pc-remove]');
      if (name) { name.addEventListener('input', updateSummary); }
      if (remove) {
        remove.addEventListener('click', function () {
          if (row.parentNode) { row.parentNode.removeChild(row); }
          updateSummary();
        });
      }
    }

    Array.prototype.slice.call(list.querySelectorAll('[data-pc-row]')).forEach(bindRow);
    add.addEventListener('click', function () {
      var fragment = template.content ? template.content.cloneNode(true) : null;
      if (!fragment) { return; }
      var row = fragment.querySelector('[data-pc-row]');
      if (!row) { return; }
      bindRow(row);
      list.appendChild(fragment);
      var name = row.querySelector('[data-pc-name]');
      if (name) { name.focus(); }
      updateSummary();
    });
    Array.prototype.slice.call(root.querySelectorAll('[data-colour-option]')).forEach(function (input) {
      input.addEventListener('change', updateSummary);
    });
    updateSummary();
  }

  ready(function () {
    var form = document.querySelector('[data-product-form]');
    if (!form) { return; }
    var gallery = form.querySelector('[data-product-gallery-editor]');
    if (gallery) { initGallery(form, gallery); }
    var colours = form.querySelector('[data-product-colour-editor]');
    if (colours) { initColours(colours); }
  });
})();
