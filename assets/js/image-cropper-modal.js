/**
 * SIPALA - Lightbox Pop-up & Interactive Image Cropper
 * Pure Vanilla JavaScript (Zero External Dependencies)
 */

(function (window, document) {
    'use strict';

    // =========================================================================
    // 1. LIGHTBOX PHOTO VIEWER
    // =========================================================================
    let lightboxEl = null;

    function createLightboxDOM() {
        if (lightboxEl) return lightboxEl;

        const wrapper = document.createElement('div');
        wrapper.className = 'sipala-lightbox-overlay';
        wrapper.id = 'sipalaLightboxModal';
        wrapper.innerHTML = `
            <div class="sipala-lightbox-card" onclick="event.stopPropagation()">
                <div class="sipala-lightbox-header">
                    <div>
                        <div class="sipala-lightbox-title" id="sipalaLightboxTitle">Foto</div>
                        <div class="sipala-lightbox-subtitle" id="sipalaLightboxSubtitle"></div>
                    </div>
                    <button type="button" class="sipala-lightbox-close" id="sipalaLightboxClose" title="Tutup (ESC)">&times;</button>
                </div>
                <div class="sipala-lightbox-body">
                    <img src="" alt="Pratinjau Foto" class="sipala-lightbox-img" id="sipalaLightboxImg">
                </div>
                <div class="sipala-lightbox-footer">
                    <div id="sipalaLightboxMeta">Laboratorium SIPALA</div>
                    <button type="button" class="sipala-cropper-btn" style="background:#e2e8f0;color:#1e293b;" id="sipalaLightboxCloseBtn">Tutup</button>
                </div>
            </div>
        `;

        document.body.appendChild(wrapper);

        // Close events
        wrapper.addEventListener('click', closePhotoLightbox);
        wrapper.querySelector('#sipalaLightboxClose').addEventListener('click', closePhotoLightbox);
        wrapper.querySelector('#sipalaLightboxCloseBtn').addEventListener('click', closePhotoLightbox);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && wrapper.classList.contains('active')) {
                closePhotoLightbox();
            }
        });

        lightboxEl = wrapper;
        return lightboxEl;
    }

    function openPhotoLightbox(options) {
        if (!options || !options.src) return;
        const modal = createLightboxDOM();

        const img = modal.querySelector('#sipalaLightboxImg');
        const title = modal.querySelector('#sipalaLightboxTitle');
        const subtitle = modal.querySelector('#sipalaLightboxSubtitle');
        const meta = modal.querySelector('#sipalaLightboxMeta');

        img.src = options.src;
        img.alt = options.title || 'Foto';

        title.innerHTML = options.title || 'Pratinjau Foto';
        if (options.badge) {
            title.innerHTML += ` <span style="margin-left:4px;">${options.badge}</span>`;
        }

        subtitle.textContent = options.subtitle || '';
        subtitle.style.display = options.subtitle ? 'block' : 'none';

        meta.innerHTML = options.meta || 'Sistem Informasi Laboratorium';

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closePhotoLightbox() {
        if (!lightboxEl) return;
        lightboxEl.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Auto attach listener for elements with data-lightbox-src
    function attachLightboxListeners() {
        document.querySelectorAll('[data-lightbox-src]').forEach(function (el) {
            el.style.cursor = 'zoom-in';
            el.onclick = function (e) {
                e.preventDefault();
                e.stopPropagation();
                openPhotoLightbox({
                    src: this.getAttribute('data-lightbox-src'),
                    title: this.getAttribute('data-lightbox-title') || '',
                    subtitle: this.getAttribute('data-lightbox-sub') || '',
                    badge: this.getAttribute('data-lightbox-badge') || '',
                    meta: this.getAttribute('data-lightbox-meta') || ''
                });
            };
        });
    }

    // =========================================================================
    // 2. INTERACTIVE IMAGE CROPPER (HTML5 CANVAS)
    // =========================================================================
    let cropperEl = null;
    let cropperState = {
        img: null,
        imgWidth: 0,
        imgHeight: 0,
        canvas: null,
        ctx: null,
        cropBox: null,
        aspectRatio: 1, // width / height
        shape: 'square', // 'circle' or 'square'
        scale: 1,
        minScale: 0.2,
        maxScale: 4.0,
        rotation: 0, // 0, 90, 180, 270
        posX: 0,
        posY: 0,
        isDragging: false,
        dragStartX: 0,
        dragStartY: 0,
        onCropCallback: null,
        onCancelCallback: null
    };

    function createCropperDOM() {
        if (cropperEl) return cropperEl;

        const wrapper = document.createElement('div');
        wrapper.className = 'sipala-cropper-overlay';
        wrapper.id = 'sipalaCropperModal';
        wrapper.innerHTML = `
            <div class="sipala-cropper-dialog" onclick="event.stopPropagation()">
                <div class="sipala-cropper-header">
                    <div>
                        <h3 id="sipalaCropperHeading">&#9986; Potong & Sesuaikan Foto</h3>
                        <p id="sipalaCropperSubheading">Geser untuk memposisikan gambar, gunakan slider untuk memperbesar/memperkecil.</p>
                    </div>
                    <button type="button" class="sipala-lightbox-close" id="sipalaCropperClose" style="background:#334155;color:#f8fafc;border-color:#475569;" title="Batal (ESC)">&times;</button>
                </div>
                
                <div class="sipala-cropper-stage" id="sipalaCropperStage">
                    <canvas class="sipala-cropper-canvas" id="sipalaCropperCanvas"></canvas>
                    <div class="sipala-crop-box-guide" id="sipalaCropBoxGuide"></div>
                </div>

                <div class="sipala-cropper-toolbar">
                    <div class="sipala-cropper-zoom-wrap">
                        <label>Perbesar:</label>
                        <button type="button" class="sipala-cropper-btn" id="sipalaZoomOutBtn" title="Perkecil">&minus;</button>
                        <input type="range" class="sipala-cropper-slider" id="sipalaZoomSlider" min="0.2" max="3.5" step="0.01" value="1">
                        <button type="button" class="sipala-cropper-btn" id="sipalaZoomInBtn" title="Perbesar">&#43;</button>
                    </div>

                    <div class="sipala-cropper-btn-group">
                        <button type="button" class="sipala-cropper-btn" id="sipalaRotateBtn" title="Putar 90 Derajat">
                            <span>&#8635;</span> Putar 90&deg;
                        </button>
                        <button type="button" class="sipala-cropper-btn" id="sipalaResetBtn" title="Kembalikan Posisi Awal">
                            Reset
                        </button>
                        <button type="button" class="sipala-cropper-btn danger" id="sipalaCancelBtn">
                            Batal
                        </button>
                        <button type="button" class="sipala-cropper-btn primary" id="sipalaApplyBtn">
                            &#10004; Terapkan Potongan
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(wrapper);

        // Events setup
        wrapper.querySelector('#sipalaCropperClose').onclick = closeCropper;
        wrapper.querySelector('#sipalaCancelBtn').onclick = closeCropper;
        wrapper.querySelector('#sipalaApplyBtn').onclick = applyCrop;

        // Zoom Slider & Buttons
        const slider = wrapper.querySelector('#sipalaZoomSlider');
        slider.oninput = function () {
            cropperState.scale = parseFloat(this.value);
            renderCropperCanvas();
        };

        wrapper.querySelector('#sipalaZoomInBtn').onclick = function () {
            cropperState.scale = Math.min(cropperState.maxScale, cropperState.scale + 0.15);
            slider.value = cropperState.scale;
            renderCropperCanvas();
        };

        wrapper.querySelector('#sipalaZoomOutBtn').onclick = function () {
            cropperState.scale = Math.max(cropperState.minScale, cropperState.scale - 0.15);
            slider.value = cropperState.scale;
            renderCropperCanvas();
        };

        // Rotate
        wrapper.querySelector('#sipalaRotateBtn').onclick = function () {
            cropperState.rotation = (cropperState.rotation + 90) % 360;
            renderCropperCanvas();
        };

        // Reset
        wrapper.querySelector('#sipalaResetBtn').onclick = function () {
            resetCropperPosition();
            renderCropperCanvas();
        };

        // Mouse Drag / Touch Drag on Stage
        const stage = wrapper.querySelector('#sipalaCropperStage');

        stage.addEventListener('mousedown', function (e) {
            cropperState.isDragging = true;
            cropperState.dragStartX = e.clientX - cropperState.posX;
            cropperState.dragStartY = e.clientY - cropperState.posY;
        });

        window.addEventListener('mousemove', function (e) {
            if (!cropperState.isDragging) return;
            cropperState.posX = e.clientX - cropperState.dragStartX;
            cropperState.posY = e.clientY - cropperState.dragStartY;
            renderCropperCanvas();
        });

        window.addEventListener('mouseup', function () {
            cropperState.isDragging = false;
        });

        // Touch support
        stage.addEventListener('touchstart', function (e) {
            if (e.touches.length === 1) {
                cropperState.isDragging = true;
                cropperState.dragStartX = e.touches[0].clientX - cropperState.posX;
                cropperState.dragStartY = e.touches[0].clientY - cropperState.posY;
            }
        }, { passive: true });

        window.addEventListener('touchmove', function (e) {
            if (!cropperState.isDragging || e.touches.length !== 1) return;
            cropperState.posX = e.touches[0].clientX - cropperState.dragStartX;
            cropperState.posY = e.touches[0].clientY - cropperState.dragStartY;
            renderCropperCanvas();
        }, { passive: true });

        window.addEventListener('touchend', function () {
            cropperState.isDragging = false;
        });

        // Mouse Wheel Zoom
        stage.addEventListener('wheel', function (e) {
            e.preventDefault();
            const delta = e.deltaY < 0 ? 0.08 : -0.08;
            cropperState.scale = Math.max(cropperState.minScale, Math.min(cropperState.maxScale, cropperState.scale + delta));
            slider.value = cropperState.scale;
            renderCropperCanvas();
        }, { passive: false });

        cropperEl = wrapper;
        return cropperEl;
    }

    function resetCropperPosition() {
        const stage = cropperEl.querySelector('#sipalaCropperStage');
        const stageW = stage.clientWidth || 600;
        const stageH = stage.clientHeight || 380;

        cropperState.posX = stageW / 2;
        cropperState.posY = stageH / 2;
        cropperState.rotation = 0;

        // Auto calculate scale to cover crop box
        const guide = cropperEl.querySelector('#sipalaCropBoxGuide');
        const boxW = guide.offsetWidth || 260;
        const boxH = guide.offsetHeight || 260;

        const scaleX = boxW / (cropperState.imgWidth || 1);
        const scaleY = boxH / (cropperState.imgHeight || 1);
        cropperState.scale = Math.max(scaleX, scaleY, 0.4);

        const slider = cropperEl.querySelector('#sipalaZoomSlider');
        slider.value = cropperState.scale;
    }

    function updateCropBoxGuide() {
        const stage = cropperEl.querySelector('#sipalaCropperStage');
        const guide = cropperEl.querySelector('#sipalaCropBoxGuide');
        const stageW = stage.clientWidth || 600;
        const stageH = stage.clientHeight || 380;

        let boxW, boxH;
        if (cropperState.aspectRatio === 1) {
            const size = Math.min(stageW - 40, stageH - 40, 260);
            boxW = size;
            boxH = size;
        } else if (cropperState.aspectRatio === (4 / 3)) {
            const maxW = Math.min(stageW - 40, 420);
            boxW = maxW;
            boxH = Math.round(boxW * (3 / 4));
            if (boxH > stageH - 40) {
                boxH = stageH - 40;
                boxW = Math.round(boxH * (4 / 3));
            }
        } else {
            // Free / wide
            boxW = Math.min(stageW - 40, 360);
            boxH = Math.min(stageH - 40, 260);
        }

        guide.style.width = boxW + 'px';
        guide.style.height = boxH + 'px';
        guide.style.left = Math.round((stageW - boxW) / 2) + 'px';
        guide.style.top = Math.round((stageH - boxH) / 2) + 'px';

        if (cropperState.shape === 'circle') {
            guide.classList.add('circle');
        } else {
            guide.classList.remove('circle');
        }
    }

    function renderCropperCanvas() {
        if (!cropperState.img || !cropperState.ctx) return;

        const canvas = cropperState.canvas;
        const ctx = cropperState.ctx;
        const stage = cropperEl.querySelector('#sipalaCropperStage');

        if (canvas.width !== stage.clientWidth || canvas.height !== stage.clientHeight) {
            canvas.width = stage.clientWidth;
            canvas.height = stage.clientHeight;
        }

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.save();

        // Translate to image center position
        ctx.translate(cropperState.posX, cropperState.posY);
        ctx.rotate((cropperState.rotation * Math.PI) / 180);
        ctx.scale(cropperState.scale, cropperState.scale);

        // Draw image centered
        ctx.drawImage(
            cropperState.img,
            -cropperState.imgWidth / 2,
            -cropperState.imgHeight / 2,
            cropperState.imgWidth,
            cropperState.imgHeight
        );

        ctx.restore();
    }

    function openImageCropper(options) {
        if (!options || !options.file) return;

        const modal = createCropperDOM();
        cropperState.aspectRatio = options.aspectRatio || 1; // 1 = 1:1, 4/3 = 4:3
        cropperState.shape = options.shape || 'square';
        cropperState.onCropCallback = options.onCrop || null;
        cropperState.onCancelCallback = options.onCancel || null;

        modal.querySelector('#sipalaCropperHeading').textContent = options.title || 'Potong & Sesuaikan Foto';

        const stage = modal.querySelector('#sipalaCropperStage');
        const canvas = modal.querySelector('#sipalaCropperCanvas');
        cropperState.canvas = canvas;
        cropperState.ctx = canvas.getContext('2d');

        // Read file into Image
        const reader = new FileReader();
        reader.onload = function (e) {
            const img = new Image();
            img.onload = function () {
                cropperState.img = img;
                cropperState.imgWidth = img.naturalWidth || img.width;
                cropperState.imgHeight = img.naturalHeight || img.height;

                modal.classList.add('active');
                document.body.style.overflow = 'hidden';

                setTimeout(function () {
                    updateCropBoxGuide();
                    resetCropperPosition();
                    renderCropperCanvas();
                }, 50);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(options.file);
    }

    function closeCropper() {
        if (!cropperEl) return;
        cropperEl.classList.remove('active');
        document.body.style.overflow = '';
        if (typeof cropperState.onCancelCallback === 'function') {
            cropperState.onCancelCallback();
        }
    }

    function applyCrop() {
        if (!cropperState.img || !cropperEl) return;

        const guide = cropperEl.querySelector('#sipalaCropBoxGuide');
        const stage = cropperEl.querySelector('#sipalaCropperStage');

        const boxRect = guide.getBoundingClientRect();
        const stageRect = stage.getBoundingClientRect();

        const boxX = boxRect.left - stageRect.left;
        const boxY = boxRect.top - stageRect.top;
        const boxW = boxRect.width;
        const boxH = boxRect.height;

        // Create high-res output canvas
        // Minimum output resolution for crisp quality
        const outputCanvas = document.createElement('canvas');
        const targetW = Math.max(480, Math.round(boxW * 2));
        const targetH = Math.max(Math.round(480 / cropperState.aspectRatio), Math.round(boxH * 2));

        outputCanvas.width = targetW;
        outputCanvas.height = targetH;
        const outCtx = outputCanvas.getContext('2d');

        outCtx.imageSmoothingEnabled = true;
        outCtx.imageSmoothingQuality = 'high';

        // Scale factors from stage crop box to output canvas
        const scaleFactor = targetW / boxW;

        outCtx.save();
        outCtx.scale(scaleFactor, scaleFactor);
        outCtx.translate(-boxX, -boxY);

        // Apply current image transformation in output canvas
        outCtx.translate(cropperState.posX, cropperState.posY);
        outCtx.rotate((cropperState.rotation * Math.PI) / 180);
        outCtx.scale(cropperState.scale, cropperState.scale);

        outCtx.drawImage(
            cropperState.img,
            -cropperState.imgWidth / 2,
            -cropperState.imgHeight / 2,
            cropperState.imgWidth,
            cropperState.imgHeight
        );
        outCtx.restore();

        const croppedDataUrl = outputCanvas.toDataURL('image/jpeg', 0.92);

        if (typeof cropperState.onCropCallback === 'function') {
            cropperState.onCropCallback(croppedDataUrl);
        }

        cropperEl.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Helper to wire a file input element with cropper
    function setupFileInputCropper(config) {
        const input = document.getElementById(config.inputId);
        if (!input) return;

        input.addEventListener('change', function () {
            if (!this.files || !this.files[0]) return;
            const file = this.files[0];

            if (file.size > 5 * 1024 * 1024) {
                alert('Ukuran file terlalu besar (maksimal 5 MB).');
                this.value = '';
                return;
            }

            openImageCropper({
                file: file,
                title: config.title || 'Sesuaikan Posisi & Potong Foto',
                aspectRatio: config.aspectRatio || 1,
                shape: config.shape || 'square',
                onCrop: function (croppedDataUrl) {
                    // 1. Update preview image
                    if (config.previewId) {
                        const previewEl = document.getElementById(config.previewId);
                        if (previewEl) {
                            previewEl.src = croppedDataUrl;
                            previewEl.style.display = 'block';
                        }
                    }
                    if (config.placeholderId) {
                        const ph = document.getElementById(config.placeholderId);
                        if (ph) ph.style.display = 'none';
                    }

                    // 2. Set hidden input for base64
                    if (config.hiddenInputId) {
                        const hiddenInput = document.getElementById(config.hiddenInputId);
                        if (hiddenInput) {
                            hiddenInput.value = croppedDataUrl;
                        }
                    }
                },
                onCancel: function () {
                    // If no existing crop, user cancelled selection
                }
            });
        });
    }

    // Export to global scope
    window.SipalaLightbox = {
        open: openPhotoLightbox,
        close: closePhotoLightbox,
        init: attachLightboxListeners
    };

    window.SipalaCropper = {
        open: openImageCropper,
        close: closeCropper,
        setup: setupFileInputCropper
    };

    // Auto initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function () {
        attachLightboxListeners();
    });

})(window, document);
