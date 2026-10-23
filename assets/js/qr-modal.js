/**
 * FILE       : assets/js/qr-modal.js
 * MODUL      : Modal Pratinjau & Cetak Kode QR Interaktif
 * FUNGSI     : Membuka modal pratinjau QR Code dengan fitur unduh PNG, cetak label, salin link, dan buka scan
 */

(function () {
    let modalOverlay = null;
    let canvasEl = null;
    let titleEl = null;
    let codeEl = null;
    let descEl = null;
    let currentData = { text: '', title: '', code: '', printUrl: '', scanUrl: '' };

    function ensureModal() {
        if (modalOverlay) return;

        modalOverlay = document.createElement('div');
        modalOverlay.className = 'sipala-qr-modal-overlay';
        modalOverlay.id = 'sipalaQrModal';
        modalOverlay.innerHTML = `
            <div class="sipala-qr-modal-box">
                <div class="sipala-qr-modal-header">
                    <h3>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                        Kode QR Inventaris Lab
                    </h3>
                    <button type="button" class="sipala-qr-modal-close" id="sipalaQrCloseBtn">&times;</button>
                </div>
                <div class="sipala-qr-modal-body">
                    <div class="sipala-qr-card-preview">
                        <div class="sipala-qr-canvas-wrapper">
                            <canvas id="sipalaQrCanvas" width="220" height="220"></canvas>
                        </div>
                        <div class="sipala-qr-modal-title" id="sipalaQrTitle">-</div>
                        <div class="sipala-qr-modal-code" id="sipalaQrCode">-</div>
                        <div class="sipala-qr-modal-desc" id="sipalaQrDesc">Pindai kode QR untuk mengecek kondisi fisik dan ketersediaan alat.</div>
                    </div>

                    <div class="sipala-qr-modal-actions">
                        <div class="sipala-qr-btn-row">
                            <button type="button" class="btn btn-primary" id="sipalaQrDownloadBtn" style="font-size:12.5px;padding:8px 12px;background:#2563eb;color:#ffffff;border:none;border-radius:6px;cursor:pointer;font-weight:700;">
                                📥 Unduh Gambar (PNG)
                            </button>
                            <a href="#" id="sipalaQrPrintBtn" target="_blank" class="btn" style="font-size:12.5px;padding:8px 12px;background:#0f172a;color:#ffffff;border:none;border-radius:6px;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;font-weight:700;">
                                🖨️ Cetak Stiker
                            </a>
                        </div>
                        <div class="sipala-qr-btn-row">
                            <button type="button" class="btn" id="sipalaQrCopyBtn" style="font-size:12px;padding:8px 12px;background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;border-radius:6px;cursor:pointer;font-weight:600;">
                                📋 Salin URL Scan
                            </button>
                            <a href="#" id="sipalaQrOpenScanBtn" target="_blank" class="btn" style="font-size:12px;padding:8px 12px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:6px;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;font-weight:600;">
                                🔗 Buka Halaman
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modalOverlay);

        canvasEl = document.getElementById('sipalaQrCanvas');
        titleEl = document.getElementById('sipalaQrTitle');
        codeEl = document.getElementById('sipalaQrCode');
        descEl = document.getElementById('sipalaQrDesc');

        const closeBtn = document.getElementById('sipalaQrCloseBtn');
        closeBtn.addEventListener('click', closeQrModal);

        modalOverlay.addEventListener('click', function (e) {
            if (e.target === modalOverlay) closeQrModal();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modalOverlay.classList.contains('active')) {
                closeQrModal();
            }
        });

        document.getElementById('sipalaQrDownloadBtn').addEventListener('click', function () {
            if (!canvasEl) return;
            const link = document.createElement('a');
            link.download = `QR_${currentData.code || 'SIPALA'}.png`;
            link.href = canvasEl.toDataURL('image/png');
            link.click();
        });

        document.getElementById('sipalaQrCopyBtn').addEventListener('click', function () {
            if (!currentData.scanUrl) return;
            navigator.clipboard.writeText(currentData.scanUrl).then(() => {
                alert('Tautan scan berhasil disalin ke clipboard:\n' + currentData.scanUrl);
            }).catch(() => {
                prompt('Salin tautan ini:', currentData.scanUrl);
            });
        });
    }

    function openQrModal(opts) {
        ensureModal();
        currentData = opts;

        titleEl.textContent = opts.title || 'Alat Laboratorium';
        codeEl.textContent = opts.code || opts.text || '-';
        if (opts.desc) descEl.textContent = opts.desc;

        // Render QR ke Canvas
        if (window.SipalaQR && canvasEl) {
            window.SipalaQR.renderToCanvas(canvasEl, opts.scanUrl || opts.text, 220);
        }

        const printBtn = document.getElementById('sipalaQrPrintBtn');
        if (opts.printUrl) {
            printBtn.href = opts.printUrl;
            printBtn.style.display = 'inline-flex';
        } else {
            printBtn.style.display = 'none';
        }

        const openBtn = document.getElementById('sipalaQrOpenScanBtn');
        if (opts.scanUrl) {
            openBtn.href = opts.scanUrl;
            openBtn.style.display = 'inline-flex';
        } else {
            openBtn.style.display = 'none';
        }

        modalOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeQrModal() {
        if (!modalOverlay) return;
        modalOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    window.openSipalaQr = openQrModal;
    window.closeSipalaQr = closeQrModal;

    // Delegasi klik otomatis untuk elemen data-qr
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-qr-target]');
        if (!btn) return;
        e.preventDefault();

        const title = btn.getAttribute('data-qr-title') || '';
        const code = btn.getAttribute('data-qr-code') || '';
        const printUrl = btn.getAttribute('data-qr-print') || '';
        const scanUrl = btn.getAttribute('data-qr-scan') || btn.getAttribute('data-qr-url') || '';
        const desc = btn.getAttribute('data-qr-desc') || '';

        openQrModal({
            title: title,
            code: code,
            text: scanUrl || code,
            printUrl: printUrl,
            scanUrl: scanUrl,
            desc: desc
        });
    });
})();
