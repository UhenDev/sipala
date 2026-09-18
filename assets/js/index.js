/**
 * FILE    : index.js
 * MODUL   : Interaksi Portal SIPALA
 * FUNGSI  : Mengatur pencarian, filter, interaksi katalog,
 *           tracking pengajuan, dan feedback pengguna.
 */

document.addEventListener('DOMContentLoaded', function () {
    initCatalogSearch();
    initModalEvents();
});

// Cache current active equipment data for modals
let currentActiveItem = null;

/**
 * Inisialisasi fitur pencarian cepat pada katalog alat (Client-Side Instant Filter)
 */
function initCatalogSearch() {
    const searchInput = document.getElementById('catalogSearchInput');
    const catalogGrid = document.getElementById('equipmentGrid');
    const emptyState = document.getElementById('catalogEmptyState');

    if (!searchInput || !catalogGrid) return;

    searchInput.addEventListener('input', function () {
        const query = this.value.trim().toLowerCase();
        const cards = catalogGrid.querySelectorAll('.equipment-card');
        let visibleCount = 0;

        cards.forEach(card => {
            const name = (card.getAttribute('data-name') || '').toLowerCase();
            const code = (card.getAttribute('data-code') || '').toLowerCase();
            const category = (card.getAttribute('data-category') || '').toLowerCase();

            if (query === '' || name.includes(query) || code.includes(query) || category.includes(query)) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        if (emptyState) {
            if (visibleCount === 0 && cards.length > 0) {
                emptyState.style.display = 'block';
            } else {
                emptyState.style.display = 'none';
            }
        }
    });
}

/**
 * Event listener untuk menutup modal dengan klik overlay luar atau tombol Escape
 */
function initModalEvents() {
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function (e) {
            if (e.target === this) {
                closeAllModals();
            }
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAllModals();
        }
    });
}

/**
 * Menutup semua modal yang sedang aktif
 */
function closeAllModals() {
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.classList.remove('active');
    });
}

/**
 * Membuka modal rincian informasi dan spesifikasi alat
 * @param {Object} item Data objek peralatan
 */
function openDetailModal(item) {
    currentActiveItem = item;

    const modal = document.getElementById('detailModalOverlay');
    if (!modal) return;

    // Foto / Placeholder
    const mediaContainer = document.getElementById('detailMediaWrap');
    if (mediaContainer) {
        if (item.foto && item.foto.trim() !== '') {
            mediaContainer.innerHTML = `
                <img src="${escapeHtml(item.foto)}" alt="${escapeHtml(item.nama_barang)}" 
                     class="sipala-zoomable-img"
                     data-lightbox-src="${escapeHtml(item.foto)}"
                     data-lightbox-title="${escapeHtml(item.nama_barang)}"
                     data-lightbox-sub="Kode: ${escapeHtml(item.kode_barang)} &bull; ${escapeHtml(item.nama_kategori || 'Lab Komputer RPL')}"
                     data-lightbox-badge="Kondisi: ${escapeHtml(item.kondisi)}"
                     title="Klik untuk memperbesar gambar">
            `;
        } else {
            mediaContainer.innerHTML = `
                <div class="card-img-placeholder">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                        <line x1="8" y1="21" x2="16" y2="21"></line>
                        <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                    <span>Foto belum tersedia</span>
                </div>
            `;
        }
    }

    // Identitas Alat
    setText('detailAlatNama', item.nama_barang);
    setText('detailAlatKode', item.kode_barang);
    setText('detailAlatKategori', item.nama_kategori || 'Perangkat RPL');
    setText('detailAlatKondisi', item.kondisi);
    setText('detailAlatLokasi', item.lokasi || 'Lab Komputer RPL');
    setText('detailAlatTotal', (item.jumlah || 0) + ' unit');

    // Status Ketersediaan
    const stokTersedia = parseInt(item.jumlah_tersedia, 10) || 0;
    const stockBadge = document.getElementById('detailAlatStockBadge');
    if (stockBadge) {
        if (stokTersedia > 2) {
            stockBadge.className = 'stock-badge available';
            stockBadge.textContent = `✓ ${stokTersedia} unit tersedia`;
        } else if (stokTersedia > 0) {
            stockBadge.className = 'stock-badge limited';
            stockBadge.textContent = `! Terbatas (${stokTersedia} unit)`;
        } else {
            stockBadge.className = 'stock-badge empty';
            stockBadge.textContent = 'Tidak tersedia';
        }
    }

    // Deskripsi
    const descBox = document.getElementById('detailAlatDeskripsi');
    if (descBox) {
        descBox.textContent = item.deskripsi && item.deskripsi.trim() !== '' 
            ? item.deskripsi 
            : 'Informasi spesifikasi lengkap perangkat belum ditambahkan oleh pengelola lab.';
    }

    // Tombol Pinjam di Detail
    const actionBtn = document.getElementById('detailActionBtn');
    if (actionBtn) {
        if (stokTersedia > 0) {
            actionBtn.disabled = false;
            actionBtn.className = 'btn btn-primary';
            actionBtn.textContent = 'Ajukan Peminjaman';
            actionBtn.onclick = function () {
                closeDetailModal();
                openLoanModal(item.id, item.nama_barang, item.kode_barang, stokTersedia, item.foto);
            };
        } else {
            actionBtn.disabled = true;
            actionBtn.className = 'btn btn-disabled';
            actionBtn.textContent = 'Stok Tidak Tersedia';
            actionBtn.onclick = null;
        }
    }

    modal.classList.add('active');
}

/**
 * Menutup modal detail
 */
function closeDetailModal() {
    const modal = document.getElementById('detailModalOverlay');
    if (modal) modal.classList.remove('active');
}

/**
 * Membuka modal formulir pengajuan peminjaman
 */
function openLoanModal(id, nama, kode, stok, foto) {
    const modal = document.getElementById('loanModalOverlay');
    if (!modal) return;

    setText('modalAlatNama', nama);
    setText('modalAlatKode', kode);
    setText('modalAlatStok', stok);

    const idPeralatanInput = document.getElementById('modalPeralatanId');
    if (idPeralatanInput) idPeralatanInput.value = id;

    const inpJumlah = document.getElementById('modalJumlah');
    if (inpJumlah) {
        const maxUnitConfig = parseInt(inpJumlah.getAttribute('data-max-config') || '2', 10);
        inpJumlah.max = Math.min(stok, maxUnitConfig);
        inpJumlah.value = 1;
    }

    // Foto mini
    const imgEl = document.getElementById('modalAlatImg');
    const placeholderEl = document.getElementById('modalImgPlaceholder');
    if (imgEl && placeholderEl) {
        if (foto && foto.trim() !== '') {
            imgEl.src = foto;
            imgEl.style.display = 'block';
            placeholderEl.style.display = 'none';
        } else {
            imgEl.style.display = 'none';
            placeholderEl.style.display = 'flex';
        }
    }

    modal.classList.add('active');
}

/**
 * Menutup modal form peminjaman
 */
function closeLoanModal() {
    const modal = document.getElementById('loanModalOverlay');
    if (modal) modal.classList.remove('active');
}

/**
 * Menyalin kode transaksi peminjaman ke clipboard
 */
function copyLoanCode() {
    const codeEl = document.getElementById('loanCodeText');
    if (!codeEl) return;

    const code = codeEl.innerText.trim();
    if (navigator.clipboard) {
        navigator.clipboard.writeText(code).then(() => {
            if (window.Swal) {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Kode ' + code + ' disalin!',
                    showConfirmButton: false,
                    timer: 2500
                });
            } else {
                alert('Kode peminjaman ' + code + ' berhasil disalin!');
            }
        });
    } else {
        alert('Kode peminjaman: ' + code);
    }
}

/**
 * Konfirmasi logout akun siswa
 */
function confirmLogoutStudent(e) {
    if (e) e.preventDefault();
    if (!window.Swal) {
        if (confirm('Apakah Anda yakin ingin keluar dari akun SIPALA?')) {
            window.location.href = 'logout.php';
        }
        return;
    }

    Swal.fire({
        title: 'Keluar dari SIPALA?',
        text: 'Anda akan mengakhiri sesi akun siswa.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Keluar',
        cancelButtonText: 'Batal',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Memproses Keluar...',
                text: 'Sedang mengakhiri sesi akun.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
            });
            window.location.href = 'logout.php';
        }
    });
}

/**
 * Helper untuk menyetel teks elemen dengan aman
 */
function setText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text || '-';
}

/**
 * Helper escape HTML sederhana
 */
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
