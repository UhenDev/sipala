/**
 * FILE    : assets/js/index.js
 * MODUL   : Interaksi Portal SIPALA & Dashboard Siswa
 * DESAIN  : Modern Editorial + Interactive Laboratory Website
 * FUNGSI  : Mengatur scroll spy, navbar sticky transition, number counter animasi,
 *           filter & live search katalog, FAQ accordion, drawer mobile,
 *           modal detail alat, modal peminjaman, serta feedback SweetAlert2.
 */

document.addEventListener('DOMContentLoaded', function () {
    initNavbarScroll();
    initBackToTop();
    initMobileDrawer();
    initNumberCounter();
    initCatalogSearch();
    initModalEvents();
    initFaqAccordion();
    initHeroMotionGraphics();
});

// Cache data item yang sedang aktif dibuka
let currentActiveItem = null;

/**
 * 1. NAVBAR SCROLL TRANSITION & SCROLL SPY
 */
function initNavbarScroll() {
    const header = document.getElementById('siteHeader');
    const backToTopBtn = document.getElementById('backToTopBtn');
    const navLinks = document.querySelectorAll('.desktop-nav .nav-item');
    const sections = document.querySelectorAll('section[id], div[id="dashboard-saya"]');

    function onScroll() {
        const scrollY = window.scrollY || window.pageYOffset;

        // Sticky scrolled style
        if (header) {
            if (scrollY > 30) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        }

        // Back to top button visibility
        if (backToTopBtn) {
            if (scrollY > 350) {
                backToTopBtn.classList.add('visible');
            } else {
                backToTopBtn.classList.remove('visible');
            }
        }

        // Active link spy
        if (sections.length > 0 && navLinks.length > 0) {
            let currentId = '';
            sections.forEach(section => {
                const sectionTop = section.offsetTop - 120;
                const sectionHeight = section.offsetHeight;
                if (scrollY >= sectionTop && scrollY < sectionTop + sectionHeight) {
                    currentId = section.getAttribute('id');
                }
            });

            if (currentId) {
                navLinks.forEach(link => {
                    const href = link.getAttribute('href') || '';
                    if (href === '#' + currentId || href.endsWith('#' + currentId)) {
                        link.classList.add('active');
                    } else if (href.startsWith('#')) {
                        link.classList.remove('active');
                    }
                });
            }
        }
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
}

/**
 * 2. FLOATING BACK TO TOP BUTTON
 */
function initBackToTop() {
    const btn = document.getElementById('backToTopBtn');
    if (!btn) return;

    btn.addEventListener('click', function (e) {
        e.preventDefault();
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
}

/**
 * 3. MOBILE MENU DRAWER
 */
function initMobileDrawer() {
    const toggleBtn = document.getElementById('mobileMenuBtn');
    const drawer = document.getElementById('mobileNavDrawer');

    if (!toggleBtn || !drawer) return;

    toggleBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        drawer.classList.toggle('active');
    });

    // Tutup saat link diklik
    drawer.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', function () {
            drawer.classList.remove('active');
        });
    });

    // Tutup saat klik di luar
    document.addEventListener('click', function (e) {
        if (!drawer.contains(e.target) && e.target !== toggleBtn && !toggleBtn.contains(e.target)) {
            drawer.classList.remove('active');
        }
    });
}

/**
 * 4. ANIMASI NUMBER COUNTER PADA STATISTIK REAL-TIME
 */
function initNumberCounter() {
    const statNumbers = document.querySelectorAll('.stat-number');
    if (statNumbers.length === 0) return;

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReducedMotion || !('IntersectionObserver' in window)) {
        statNumbers.forEach(el => {
            const target = parseInt(el.getAttribute('data-target') || el.innerText, 10);
            el.innerText = isNaN(target) ? '0' : target.toLocaleString('id-ID');
        });
        return;
    }

    const observer = new IntersectionObserver((entries, obs) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const el = entry.target;
                const target = parseInt(el.getAttribute('data-target') || el.innerText, 10);
                if (isNaN(target)) return;

                let start = 0;
                const duration = 1200;
                const startTime = performance.now();

                function update(currentTime) {
                    const elapsed = currentTime - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    // Easing out cubic
                    const easeOut = 1 - Math.pow(1 - progress, 3);
                    const currentVal = Math.floor(easeOut * target);

                    el.innerText = currentVal.toLocaleString('id-ID');

                    if (progress < 1) {
                        requestAnimationFrame(update);
                    } else {
                        el.innerText = target.toLocaleString('id-ID');
                    }
                }

                requestAnimationFrame(update);
                obs.unobserve(el);
            }
        });
    }, { threshold: 0.35 });

    statNumbers.forEach(el => observer.observe(el));
}

/**
 * 5. PENCARIAN & INSTANT FILTER KATALOG ALAT (CLIENT-SIDE)
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
 * 6. FAQ ACCORDION INTERAKSI
 */
function initFaqAccordion() {
    const toggleBtn = document.getElementById('faqToggleBtn');
    const accordionWrap = document.getElementById('faqAccordion');

    if (toggleBtn && accordionWrap) {
        toggleBtn.addEventListener('click', function () {
            const isOpen = accordionWrap.classList.contains('active');
            if (isOpen) {
                accordionWrap.classList.remove('active');
                toggleBtn.querySelector('span').textContent = 'Lihat FAQ';
            } else {
                accordionWrap.classList.add('active');
                toggleBtn.querySelector('span').textContent = 'Tutup FAQ';
            }
        });
    }

    const faqItems = document.querySelectorAll('.faq-item');
    faqItems.forEach(item => {
        const qBtn = item.querySelector('.faq-question-btn');
        if (qBtn) {
            qBtn.addEventListener('click', function () {
                const isItemOpen = item.classList.contains('open');
                // Tutup item lain jika ingin mode single
                faqItems.forEach(other => {
                    if (other !== item) other.classList.remove('open');
                });
                if (!isItemOpen) {
                    item.classList.add('open');
                } else {
                    item.classList.remove('open');
                }
            });
        }
    });
}

/**
 * 7. MODAL EVENTS & CLOSE HANDLERS
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

function closeAllModals() {
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.classList.remove('active');
    });
}

/**
 * 8. MODAL DETAIL ALAT
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

    // Tombol QR Code di Detail
    const qrBtn = document.getElementById('detailQrBtn');
    if (qrBtn) {
        qrBtn.setAttribute('data-qr-title', item.nama_barang || '');
        qrBtn.setAttribute('data-qr-code', item.kode_barang || '');
        qrBtn.setAttribute('data-qr-desc', `Kategori: ${item.nama_kategori || 'Perangkat RPL'} • Lokasi: ${item.lokasi || '-'}`);
        const basePath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
        qrBtn.setAttribute('data-qr-scan', `${window.location.origin}${basePath}/scan.php?alat=${encodeURIComponent(item.kode_barang || item.id)}`);
    }

    modal.classList.add('active');
}

function closeDetailModal() {
    const modal = document.getElementById('detailModalOverlay');
    if (modal) modal.classList.remove('active');
}

/**
 * 9. MODAL FORM PENGAJUAN PEMINJAMAN
 */
function openLoanModal(id, nama, kode, stok, foto) {
    if (typeof id === 'object' && id !== null) {
        foto = id.foto || '';
        stok = parseInt(id.jumlah_tersedia, 10) || 0;
        kode = id.kode_barang || '';
        nama = id.nama_barang || '';
        id = id.id;
    }

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

function closeLoanModal() {
    const modal = document.getElementById('loanModalOverlay');
    if (modal) modal.classList.remove('active');
}

/**
 * 10. SALIN KODE PINJAM
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
 * 11. KONFIRMASI LOGOUT SISWA
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
 * UTILS & HELPERS
 */
function setText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text || '-';
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * 8. HERO AMBIENT VECTOR NETWORK MESH (SUBTLE & REFINED)
 * - Calibrated HTML5 canvas particle constellation for dark laboratory theme
 * - Quiet, elegant movement with distance-based connective links
 * - IntersectionObserver pauses when out of viewport for 0% CPU overhead
 * - Respects prefers-reduced-motion
 */
function initHeroMotionGraphics() {
    initHeroMeshCanvas();
}

function initHeroMeshCanvas() {
    const canvas = document.getElementById('heroMotionCanvas');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    let width = 0;
    let height = 0;
    let dpr = Math.min(window.devicePixelRatio || 1, 2);
    let particles = [];
    let animationFrameId = null;
    let isVisible = true;
    const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const mouse = {
        x: -9999,
        y: -9999,
        active: false,
        radius: 110
    };

    function resize() {
        const parent = canvas.parentElement;
        if (!parent) return;

        width = parent.clientWidth;
        height = parent.clientHeight;

        canvas.width = Math.floor(width * dpr);
        canvas.height = Math.floor(height * dpr);
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.scale(dpr, dpr);

        createParticles();
        if (reducedMotion) {
            renderFrame();
        }
    }

    function createParticles() {
        particles = [];
        // Calm, sparse node density
        const count = Math.max(16, Math.min(32, Math.floor(width / 45)));

        for (let i = 0; i < count; i++) {
            particles.push({
                x: Math.random() * width,
                y: Math.random() * height,
                vx: (Math.random() - 0.5) * 0.35,
                vy: (Math.random() - 0.5) * 0.35,
                radius: 1.2 + Math.random() * 1.0,
                baseAlpha: 0.25 + Math.random() * 0.35
            });
        }
    }

    function update() {
        for (let i = 0; i < particles.length; i++) {
            const p = particles[i];

            p.x += p.vx;
            p.y += p.vy;

            if (p.x < 0) p.x = width;
            else if (p.x > width) p.x = 0;

            if (p.y < 0) p.y = height;
            else if (p.y > height) p.y = 0;

            // Gentle drift near cursor
            if (mouse.active) {
                const dx = p.x - mouse.x;
                const dy = p.y - mouse.y;
                const dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < mouse.radius && dist > 0) {
                    const force = (mouse.radius - dist) / mouse.radius;
                    p.x += (dx / dist) * force * 0.8;
                    p.y += (dy / dist) * force * 0.8;
                }
            }
        }
    }

    function draw() {
        ctx.clearRect(0, 0, width, height);

        const maxLineDist = 110;

        // Draw connecting links
        for (let i = 0; i < particles.length; i++) {
            const p1 = particles[i];

            for (let j = i + 1; j < particles.length; j++) {
                const p2 = particles[j];
                const dx = p1.x - p2.x;
                const dy = p1.y - p2.y;
                const dist = Math.sqrt(dx * dx + dy * dy);

                if (dist < maxLineDist) {
                    const lineAlpha = (1 - dist / maxLineDist) * 0.18;
                    ctx.beginPath();
                    ctx.moveTo(p1.x, p1.y);
                    ctx.lineTo(p2.x, p2.y);
                    ctx.strokeStyle = `rgba(56, 189, 248, ${lineAlpha})`;
                    ctx.lineWidth = 0.8;
                    ctx.stroke();
                }
            }

            // Connection to mouse
            if (mouse.active) {
                const dx = p1.x - mouse.x;
                const dy = p1.y - mouse.y;
                const dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < mouse.radius) {
                    const mouseAlpha = (1 - dist / mouse.radius) * 0.3;
                    ctx.beginPath();
                    ctx.moveTo(p1.x, p1.y);
                    ctx.lineTo(mouse.x, mouse.y);
                    ctx.strokeStyle = `rgba(0, 194, 255, ${mouseAlpha})`;
                    ctx.lineWidth = 0.9;
                    ctx.stroke();
                }
            }
        }

        // Draw particle nodes
        for (let i = 0; i < particles.length; i++) {
            const p = particles[i];
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
            ctx.fillStyle = `rgba(56, 189, 248, ${p.baseAlpha})`;
            ctx.fill();
        }
    }

    function renderFrame() {
        update();
        draw();
    }

    function loop() {
        if (!isVisible) return;
        renderFrame();
        animationFrameId = requestAnimationFrame(loop);
    }

    const heroSection = canvas.closest('.hero-section') || canvas.parentElement;
    if (heroSection) {
        heroSection.addEventListener('mousemove', function (e) {
            const rect = canvas.getBoundingClientRect();
            mouse.x = e.clientX - rect.left;
            mouse.y = e.clientY - rect.top;
            mouse.active = true;
        }, { passive: true });

        heroSection.addEventListener('mouseleave', function () {
            mouse.active = false;
            mouse.x = -9999;
            mouse.y = -9999;
        }, { passive: true });
    }

    let resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(resize, 150);
    }, { passive: true });

    if ('IntersectionObserver' in window && heroSection) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                isVisible = entry.isIntersecting;
                if (isVisible && !reducedMotion) {
                    cancelAnimationFrame(animationFrameId);
                    loop();
                } else {
                    cancelAnimationFrame(animationFrameId);
                }
            });
        }, { threshold: 0.05 });
        observer.observe(heroSection);
    }

    resize();
    if (!reducedMotion) {
        loop();
    }
}


