/**
 * ShopHaat Admin Category Image Cropper
 *
 * Touch-enabled, mobile-responsive client-side cropper with live storefront card
 * and navigation thumbnail previews. Zero external dependencies.
 */
(function() {
  'use strict';

  function initCategoryCropper() {
    const modal = document.getElementById('modal-category-cropper');
    const btnOpen = document.getElementById('btn-crop-image');
    const fileInput = document.getElementById('c-img');
    const cropDataInput = document.getElementById('c-crop-data');
    const croppedImageInput = document.getElementById('c-cropped-image-data');
    const catPreview = document.getElementById('cat-preview');
    const catPreviewCap = document.getElementById('cat-preview-cap');
    const cropIndicator = document.getElementById('cat-crop-indicator');
    const nameInput = document.getElementById('c-name');

    if (!modal || !btnOpen) return;

    const viewport = document.getElementById('cropper-viewport');
    const imgEl = document.getElementById('cropper-image');
    const boxEl = document.getElementById('cropper-box');
    const zoomSlider = document.getElementById('crop-zoom-slider');
    const zoomVal = document.getElementById('crop-zoom-val');
    const btnZoomIn = document.getElementById('crop-zoom-in');
    const btnZoomOut = document.getElementById('crop-zoom-out');
    const btnFit = document.getElementById('crop-fit-btn');
    const btnReset = document.getElementById('crop-btn-reset');
    const btnSave = document.getElementById('crop-btn-save');
    const aspectBtns = document.querySelectorAll('.sh-cropper-aspect');
    const cardCanvas = document.getElementById('crop-live-card');
    const navCanvas = document.getElementById('crop-live-nav');
    const liveName = document.getElementById('crop-live-name');
    const liveNavName = document.getElementById('crop-live-navname');

    // Cropper State
    let activeSrc = '';
    let naturalW = 0;
    let naturalH = 0;
    let baseScale = 1;
    let currentZoom = 1;
    let aspect = '4:3'; // '4:3' | '1:1' | '16:9' | 'free'
    let box = { x: 0, y: 0, w: 0, h: 0 };
    let imgPos = { x: 0, y: 0, w: 0, h: 0 };
    let initialCropSaved = null;

    // Drag / Touch Interaction State
    let isDraggingBox = false;
    let isResizing = false;
    let resizeDir = '';
    let dragStart = { x: 0, y: 0 };
    let boxStart = { x: 0, y: 0, w: 0, h: 0 };
    let initialPinchDist = 0;
    let pinchStartZoom = 1;

    // Show button whenever a file is selected
    if (fileInput) {
      fileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
          const reader = new FileReader();
          reader.onload = function(e) {
            btnOpen.setAttribute('data-orig-src', e.target.result);
            btnOpen.style.display = 'inline-flex';
            if (cropIndicator) cropIndicator.style.display = 'none';
          };
          reader.readAsDataURL(this.files[0]);
        }
      });
    }

    // Open Modal
    btnOpen.addEventListener('click', function() {
      const src = btnOpen.getAttribute('data-orig-src') || (catPreview ? catPreview.src : '');
      if (!src || src.indexOf('placeholder') !== -1) return;

      activeSrc = src;
      if (nameInput) {
        const title = nameInput.value.trim() || 'Category Name';
        if (liveName) liveName.textContent = title;
        if (liveNavName) liveNavName.textContent = title;
      }

      // Read existing crop settings
      try {
        initialCropSaved = cropDataInput && cropDataInput.value ? JSON.parse(cropDataInput.value) : null;
      } catch (err) {
        initialCropSaved = null;
      }

      modal.removeAttribute('hidden');
      document.body.classList.add('sh-modal-open');

      loadImage(activeSrc, function() {
        if (initialCropSaved && initialCropSaved.aspect) {
          setAspect(initialCropSaved.aspect, false);
          if (initialCropSaved.zoom) {
            setZoom(parseFloat(initialCropSaved.zoom), false);
          }
          restoreSavedCrop(initialCropSaved);
        } else {
          setAspect('4:3', false);
          resetCrop(false);
        }
        render();
      });
    });

    // Close Modal / Cancel
    function closeModal() {
      modal.setAttribute('hidden', '');
      document.body.classList.remove('sh-modal-open');
    }

    document.querySelectorAll('[data-cropper-cancel]').forEach(function(el) {
      el.addEventListener('click', closeModal);
    });

    // Load Image
    function loadImage(src, cb) {
      imgEl.onload = function() {
        naturalW = imgEl.naturalWidth || 800;
        naturalH = imgEl.naturalHeight || 600;
        layoutImage();
        if (cb) cb();
      };
      imgEl.src = src;
    }

    // Fit image to viewport
    function layoutImage() {
      const vw = viewport.clientWidth || 500;
      const vh = viewport.clientHeight || 380;
      const pad = 20;

      const scaleX = (vw - pad * 2) / naturalW;
      const scaleY = (vh - pad * 2) / naturalH;
      baseScale = Math.min(scaleX, scaleY, 1);

      imgPos.w = naturalW * baseScale * currentZoom;
      imgPos.h = naturalH * baseScale * currentZoom;
      imgPos.x = (vw - imgPos.w) / 2;
      imgPos.y = (vh - imgPos.h) / 2;

      applyImagePosition();
    }

    function applyImagePosition() {
      imgEl.style.width = Math.round(imgPos.w) + 'px';
      imgEl.style.height = Math.round(imgPos.h) + 'px';
      imgEl.style.left = Math.round(imgPos.x) + 'px';
      imgEl.style.top = Math.round(imgPos.y) + 'px';
    }

    function setZoom(val, redraw) {
      currentZoom = Math.max(1, Math.min(3, val));
      if (zoomSlider) zoomSlider.value = currentZoom;
      if (zoomVal) zoomVal.textContent = Math.round(currentZoom * 100) + '%';

      const prevW = imgPos.w;
      const prevH = imgPos.h;
      const vw = viewport.clientWidth || 500;
      const vh = viewport.clientHeight || 380;

      imgPos.w = naturalW * baseScale * currentZoom;
      imgPos.h = naturalH * baseScale * currentZoom;

      // Keep center anchored
      imgPos.x = imgPos.x - (imgPos.w - prevW) / 2;
      imgPos.y = imgPos.y - (imgPos.h - prevH) / 2;

      constrainImage();
      applyImagePosition();
      constrainBox();

      if (redraw !== false) render();
    }

    function constrainImage() {
      // Ensure image covers or stays reasonable
      applyImagePosition();
    }

    function setAspect(choice, redraw) {
      aspect = choice;
      aspectBtns.forEach(function(b) {
        b.classList.toggle('is-active', b.getAttribute('data-aspect') === choice);
      });

      if (redraw !== false) {
        fitBoxToAspect();
        render();
      }
    }

    function fitBoxToAspect() {
      let targetRatio = null;
      if (aspect === '4:3') targetRatio = 4 / 3;
      else if (aspect === '1:1') targetRatio = 1;
      else if (aspect === '16:9') targetRatio = 16 / 9;

      const maxW = imgPos.w * 0.9;
      const maxH = imgPos.h * 0.9;

      if (!targetRatio) {
        box.w = Math.round(maxW * 0.85);
        box.h = Math.round(maxH * 0.85);
      } else {
        let bw = maxW;
        let bh = bw / targetRatio;
        if (bh > maxH) {
          bh = maxH;
          bw = bh * targetRatio;
        }
        box.w = Math.round(bw);
        box.h = Math.round(bh);
      }

      box.x = Math.round(imgPos.x + (imgPos.w - box.w) / 2);
      box.y = Math.round(imgPos.y + (imgPos.h - box.h) / 2);
      constrainBox();
    }

    function resetCrop(redraw) {
      currentZoom = 1;
      setZoom(1, false);
      layoutImage();
      fitBoxToAspect();
      if (redraw !== false) render();
    }

    function restoreSavedCrop(saved) {
      if (!saved || !saved.width || !saved.height) {
        resetCrop(false);
        return;
      }
      layoutImage();
      const ratio = imgPos.w / naturalW;
      box.w = Math.max(30, Math.round(saved.width * ratio));
      box.h = Math.max(30, Math.round(saved.height * ratio));
      box.x = Math.round(imgPos.x + (saved.x || 0) * ratio);
      box.y = Math.round(imgPos.y + (saved.y || 0) * ratio);
      constrainBox();
    }

    function constrainBox() {
      // Keep box inside image boundaries
      if (box.w > imgPos.w) box.w = imgPos.w;
      if (box.h > imgPos.h) box.h = imgPos.h;

      if (box.x < imgPos.x) box.x = imgPos.x;
      if (box.y < imgPos.y) box.y = imgPos.y;
      if (box.x + box.w > imgPos.x + imgPos.w) box.x = imgPos.x + imgPos.w - box.w;
      if (box.y + box.h > imgPos.y + imgPos.h) box.y = imgPos.y + imgPos.h - box.h;

      applyBox();
    }

    function applyBox() {
      boxEl.style.width = Math.round(box.w) + 'px';
      boxEl.style.height = Math.round(box.h) + 'px';
      boxEl.style.left = Math.round(box.x) + 'px';
      boxEl.style.top = Math.round(box.y) + 'px';
    }

    // Render Live Previews
    function render() {
      applyBox();
      if (!imgEl.complete || naturalW <= 0) return;

      // Calculate natural image source coordinates
      const scale = naturalW / imgPos.w;
      const srcX = Math.max(0, Math.round((box.x - imgPos.x) * scale));
      const srcY = Math.max(0, Math.round((box.y - imgPos.y) * scale));
      const srcW = Math.min(naturalW - srcX, Math.round(box.w * scale));
      const srcH = Math.min(naturalH - srcY, Math.round(box.h * scale));

      if (srcW <= 0 || srcH <= 0) return;

      // Draw Storefront 4:3 Card Preview
      if (cardCanvas) {
        const ctx = cardCanvas.getContext('2d');
        if (ctx) {
          ctx.clearRect(0, 0, cardCanvas.width, cardCanvas.height);
          ctx.imageSmoothingEnabled = true;
          ctx.imageSmoothingQuality = 'high';
          ctx.drawImage(imgEl, srcX, srcY, srcW, srcH, 0, 0, cardCanvas.width, cardCanvas.height);
        }
      }

      // Draw Nav 1:1 Preview
      if (navCanvas) {
        const nctx = navCanvas.getContext('2d');
        if (nctx) {
          nctx.clearRect(0, 0, navCanvas.width, navCanvas.height);
          nctx.imageSmoothingEnabled = true;
          nctx.imageSmoothingQuality = 'high';
          nctx.drawImage(imgEl, srcX, srcY, srcW, srcH, 0, 0, navCanvas.width, navCanvas.height);
        }
      }
    }

    // Mouse & Touch Interaction Helpers
    function getClientPos(e) {
      if (e.touches && e.touches.length > 0) {
        return { x: e.touches[0].clientX, y: e.touches[0].clientY };
      }
      return { x: e.clientX, y: e.clientY };
    }

    function getTouchDistance(e) {
      if (e.touches && e.touches.length >= 2) {
        const dx = e.touches[0].clientX - e.touches[1].clientX;
        const dy = e.touches[0].clientY - e.touches[1].clientY;
        return Math.sqrt(dx * dx + dy * dy);
      }
      return 0;
    }

    // Start Dragging or Resizing
    function handlePointerDown(e) {
      if (e.touches && e.touches.length >= 2) {
        // Pinch zoom gesture start
        initialPinchDist = getTouchDistance(e);
        pinchStartZoom = currentZoom;
        return;
      }

      const target = e.target;
      const handle = target.closest('.sh-cropper-handle');

      if (handle) {
        isResizing = true;
        resizeDir = handle.getAttribute('data-dir');
        dragStart = getClientPos(e);
        boxStart = { x: box.x, y: box.y, w: box.w, h: box.h };
        e.preventDefault();
        return;
      }

      if (target === boxEl || boxEl.contains(target)) {
        isDraggingBox = true;
        dragStart = getClientPos(e);
        boxStart = { x: box.x, y: box.y, w: box.w, h: box.h };
        e.preventDefault();
        return;
      }

      // Drag outside: center box on click/tap
      const rect = viewport.getBoundingClientRect();
      const pos = getClientPos(e);
      const clickX = pos.x - rect.left;
      const clickY = pos.y - rect.top;

      box.x = clickX - box.w / 2;
      box.y = clickY - box.h / 2;
      constrainBox();
      render();

      isDraggingBox = true;
      dragStart = pos;
      boxStart = { x: box.x, y: box.y, w: box.w, h: box.h };
      e.preventDefault();
    }

    function handlePointerMove(e) {
      // Multi-touch pinch zoom
      if (e.touches && e.touches.length >= 2) {
        const dist = getTouchDistance(e);
        if (initialPinchDist > 0 && dist > 0) {
          const factor = dist / initialPinchDist;
          setZoom(pinchStartZoom * factor);
          e.preventDefault();
        }
        return;
      }

      if (!isDraggingBox && !isResizing) return;

      const pos = getClientPos(e);
      const dx = pos.x - dragStart.x;
      const dy = pos.y - dragStart.y;

      if (isDraggingBox) {
        box.x = boxStart.x + dx;
        box.y = boxStart.y + dy;
        constrainBox();
        render();
        e.preventDefault();
        return;
      }

      if (isResizing) {
        let newX = boxStart.x;
        let newY = boxStart.y;
        let newW = boxStart.w;
        let newH = boxStart.h;

        let ratio = null;
        if (aspect === '4:3') ratio = 4 / 3;
        else if (aspect === '1:1') ratio = 1;
        else if (aspect === '16:9') ratio = 16 / 9;

        if (resizeDir.includes('e')) newW = Math.max(30, boxStart.w + dx);
        if (resizeDir.includes('s')) newH = Math.max(30, boxStart.h + dy);
        if (resizeDir.includes('w')) {
          const w = Math.max(30, boxStart.w - dx);
          newX = boxStart.x + (boxStart.w - w);
          newW = w;
        }
        if (resizeDir.includes('n')) {
          const h = Math.max(30, boxStart.h - dy);
          newY = boxStart.y + (boxStart.h - h);
          newH = h;
        }

        if (ratio) {
          if (resizeDir === 'e' || resizeDir === 'w') {
            newH = newW / ratio;
          } else if (resizeDir === 'n' || resizeDir === 's') {
            newW = newH * ratio;
          } else {
            // Corner handles
            if (newW / newH > ratio) {
              newW = newH * ratio;
            } else {
              newH = newW / ratio;
            }
          }
        }

        box.x = newX;
        box.y = newY;
        box.w = newW;
        box.h = newH;
        constrainBox();
        render();
        e.preventDefault();
      }
    }

    function handlePointerUp() {
      isDraggingBox = false;
      isResizing = false;
      initialPinchDist = 0;
    }

    // Attach Viewport Interaction Listeners
    viewport.addEventListener('mousedown', handlePointerDown);
    window.addEventListener('mousemove', handlePointerMove);
    window.addEventListener('mouseup', handlePointerUp);

    viewport.addEventListener('touchstart', handlePointerDown, { passive: false });
    window.addEventListener('touchmove', handlePointerMove, { passive: false });
    window.addEventListener('touchend', handlePointerUp);
    window.addEventListener('touchcancel', handlePointerUp);

    // Mouse wheel zoom
    viewport.addEventListener('wheel', function(e) {
      e.preventDefault();
      const delta = e.deltaY < 0 ? 0.08 : -0.08;
      setZoom(currentZoom + delta);
    }, { passive: false });

    // Zoom Controls
    if (zoomSlider) {
      zoomSlider.addEventListener('input', function() {
        setZoom(parseFloat(this.value));
      });
    }
    if (btnZoomIn) {
      btnZoomIn.addEventListener('click', function() { setZoom(currentZoom + 0.15); });
    }
    if (btnZoomOut) {
      btnZoomOut.addEventListener('click', function() { setZoom(currentZoom - 0.15); });
    }
    if (btnFit) {
      btnFit.addEventListener('click', function() { setZoom(1); });
    }
    if (btnReset) {
      btnReset.addEventListener('click', function() { resetCrop(); });
    }

    // Aspect Ratio Buttons
    aspectBtns.forEach(function(b) {
      b.addEventListener('click', function() {
        setAspect(this.getAttribute('data-aspect'));
      });
    });

    // Save Crop
    if (btnSave) {
      btnSave.addEventListener('click', function() {
        btnSave.disabled = true;
        btnSave.textContent = 'Applying…';

        const scale = naturalW / imgPos.w;
        const srcX = Math.max(0, Math.round((box.x - imgPos.x) * scale));
        const srcY = Math.max(0, Math.round((box.y - imgPos.y) * scale));
        const srcW = Math.min(naturalW - srcX, Math.round(box.w * scale));
        const srcH = Math.min(naturalH - srcY, Math.round(box.h * scale));

        const cropData = {
          x: srcX,
          y: srcY,
          width: srcW,
          height: srcH,
          zoom: currentZoom,
          aspect: aspect
        };

        const cropJson = JSON.stringify(cropData);

        // Generate high resolution canvas data
        let targetW = 800;
        let targetH = Math.round(800 * (srcH / srcW));
        if (aspect === '1:1') { targetW = 800; targetH = 800; }
        else if (aspect === '16:9') { targetW = 960; targetH = 540; }

        const exportCanvas = document.createElement('canvas');
        exportCanvas.width = targetW;
        exportCanvas.height = targetH;
        const ectx = exportCanvas.getContext('2d');
        ectx.imageSmoothingEnabled = true;
        ectx.imageSmoothingQuality = 'high';
        ectx.drawImage(imgEl, srcX, srcY, srcW, srcH, 0, 0, targetW, targetH);

        const dataUrl = exportCanvas.toDataURL('image/jpeg', 0.92);

        // Update form fields. The input event tells the form's unsaved-changes
        // note that there is now something to save; it never submits.
        if (cropDataInput) {
          cropDataInput.value = cropJson;
          try { cropDataInput.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
        }
        if (croppedImageInput) croppedImageInput.value = dataUrl;
        if (catPreview) {
          catPreview.src = dataUrl;
          catPreview.classList.remove('is-placeholder');
        }
        if (catPreviewCap) catPreviewCap.textContent = 'Cropped image (ready to save)';
        if (cropIndicator) cropIndicator.style.display = 'inline-flex';

        // The crop is only staged in the form's hidden fields. Nothing is
        // written to the database here: the administrator still has to press
        // Save changes / Create category, and Cancel therefore really cancels.
        btnSave.disabled = false;
        btnSave.textContent = 'Apply crop';
        closeModal();
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCategoryCropper);
  } else {
    initCategoryCropper();
  }
})();
