/**
 * FILE       : assets/js/wa-modal.js
 * MODUL      : Modal Pengiriman WhatsApp Interaktif
 * FUNGSI     : Membuka dialog pengiriman pesan WA via Bot API atau WA Web untuk transaksi laboratorium
 */

(function () {
    let waOverlay = null;
    let targetNameEl = null;
    let targetPhoneEl = null;
    let templateSelect = null;
    let messageTextarea = null;
    let sendBotBtn = null;
    let sendWebBtn = null;
    let currentLoanData = {};

    function ensureWaModal() {
        if (waOverlay) return;

        waOverlay = document.createElement('div');
        waOverlay.className = 'sipala-wa-modal-overlay';
        waOverlay.id = 'sipalaWaModal';
        waOverlay.innerHTML = `
            <div class="sipala-wa-modal-box">
                <div class="sipala-wa-modal-header">
                    <h3>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.299.045-.677.063-1.092-.069-.252-.08-.575-.187-.988-.365-1.739-.751-2.874-2.502-2.961-2.617-.087-.116-.708-.94-.708-1.793s.448-1.273.607-1.446c.159-.173.346-.217.462-.217l.332.006c.106.005.249-.04.39.298.144.347.491 1.2.534 1.287.043.087.072.188.014.304-.058.116-.087.188-.173.289l-.26.304c-.087.086-.177.18-.076.354.101.174.449.741.964 1.201.662.591 1.221.774 1.394.86s.275.072.376-.043c.101-.116.433-.506.549-.68.116-.173.231-.145.39-.087s1.011.477 1.184.564.289.13.332.202c.045.072.045.419-.1.824zm-3.423-14.416c-6.627 0-12 5.373-12 12 0 2.159.57 4.187 1.564 5.946l-1.564 5.882 6.046-1.585c1.716.947 3.69 1.488 5.789 1.488 6.627 0 12-5.373 12-12 0-6.627-5.373-12-12-12z"/></svg>
                        Kirim Pesan WhatsApp Laboratorium
                    </h3>
                    <button type="button" class="sipala-wa-modal-close" id="sipalaWaCloseBtn">&times;</button>
                </div>
                <div class="sipala-wa-modal-body">
                    <div class="sipala-wa-target-badge">
                        <div>
                            <strong id="sipalaWaTargetName">-</strong>
                            <div style="font-size:11px;color:#166534;" id="sipalaWaTargetKelas">-</div>
                        </div>
                        <div style="font-family:monospace;font-weight:700;" id="sipalaWaTargetPhone">-</div>
                    </div>

                    <div class="sipala-wa-modal-field">
                        <label>Pilih Template Pesan:</label>
                        <select id="sipalaWaTemplateSelect">
                            <option value="pengingat">⏰ Pengingat Jatuh Tempo / Tenggat Pinjam</option>
                            <option value="disetujui">✅ Pemberitahuan Pinjaman Disetujui</option>
                            <option value="terlambat">⚠️ Peringatan Keterlambatan & Denda</option>
                            <option value="pengembalian">🎉 Bukti Penerimaan Pengembalian Alat</option>
                            <option value="custom">✏️ Tulis Pesan Kustom Bebas</option>
                        </select>
                    </div>

                    <div class="sipala-wa-modal-field">
                        <label>Isi Pesan WhatsApp:</label>
                        <textarea id="sipalaWaMessageText" placeholder="Ketik pesan WhatsApp..."></textarea>
                    </div>

                    <div class="sipala-wa-modal-actions">
                        <button type="button" class="sipala-wa-btn-bot" id="sipalaWaSendBotBtn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 2 11 13"></polyline><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                            Kirim via Bot WA (Otomatis)
                        </button>
                        <a href="#" target="_blank" class="sipala-wa-btn-web" id="sipalaWaSendWebBtn">
                            💬 Buka WA Web
                        </a>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(waOverlay);

        targetNameEl = document.getElementById('sipalaWaTargetName');
        targetPhoneEl = document.getElementById('sipalaWaTargetPhone');
        templateSelect = document.getElementById('sipalaWaTemplateSelect');
        messageTextarea = document.getElementById('sipalaWaMessageText');
        sendBotBtn = document.getElementById('sipalaWaSendBotBtn');
        sendWebBtn = document.getElementById('sipalaWaSendWebBtn');

        document.getElementById('sipalaWaCloseBtn').addEventListener('click', closeWaModal);
        waOverlay.addEventListener('click', function (e) {
            if (e.target === waOverlay) closeWaModal();
        });

        templateSelect.addEventListener('change', updateMessageTemplate);
        messageTextarea.addEventListener('input', updateWebLink);

        sendBotBtn.addEventListener('click', executeSendBot);
    }

    function updateMessageTemplate() {
        const type = templateSelect.value;
        const d = currentLoanData;
        let text = '';

        if (type === 'pengingat') {
            text = `⏰ *PENGINGAT PENGEMBALIAN ALAT SIPALA*\n\nHalo *${d.nama || 'Siswa'}*,\nMengingatkan peminjaman alat *${d.alat || 'laboratorium'}* (Kode: ${d.kode || '-'}) memiliki batas kembali pada *${d.tgl_kembali || 'hari ini'}*.\n\nHarap menjaga kondisi alat dan mengembalikannya ke ruang lab tepat waktu. Terima kasih!`;
        } else if (type === 'disetujui') {
            text = `✅ *PEMINJAMAN DISETUJUI - SIPALA*\n\nHalo *${d.nama || 'Siswa'}*,\nPengajuan pinjaman alat *${d.alat || '-'}* (${d.kode || '-'}) telah DISETUJUI oleh petugas lab.\nSilakan datang ke ruang laboratorium untuk serah terima alat.\nBatas kembali: ${d.tgl_kembali || '-'}`;
        } else if (type === 'terlambat') {
            text = `⚠️ *PERINGATAN KETERLAMBATAN SIPALA*\n\nHalo *${d.nama || 'Siswa'}*,\nPeminjaman alat *${d.alat || '-'}* (${d.kode || '-'}) telah melewati batas tanggal pengembalian (${d.tgl_kembali || '-'}).\n\nHarap SEGERA mengembalikan alat ke ruang laboratorium untuk menghindari akumulasi denda harian.`;
        } else if (type === 'pengembalian') {
            text = `🎉 *PENGEMBALIAN ALAT SELESAI - SIPALA*\n\nHalo *${d.nama || 'Siswa'}*,\nAlat laboratorium *${d.alat || '-'}* telah diterima kembali oleh petugas lab dalam kondisi baik.\nTerima kasih telah mematuhi aturan laboratorium!`;
        }

        if (type !== 'custom') {
            messageTextarea.value = text;
        }
        updateWebLink();
    }

    function updateWebLink() {
        const phone = currentLoanData.phone || '';
        const msg = messageTextarea.value || '';
        let cleanPhone = phone.replace(/[^\d]/g, '');
        if (cleanPhone.startsWith('0')) cleanPhone = '62' + cleanPhone.substring(1);
        sendWebBtn.href = 'https://wa.me/' + cleanPhone + '?text=' + encodeURIComponent(msg);
    }

    function openWaModal(data) {
        ensureWaModal();
        currentLoanData = data;

        targetNameEl.textContent = data.nama || 'Siswa Peminjam';
        document.getElementById('sipalaWaTargetKelas').textContent = data.kelas ? `Kelas: ${data.kelas}` : 'Peminjam Laboratorium';
        targetPhoneEl.textContent = data.phone || 'Nomor HP Belum Terdaftar';

        templateSelect.value = data.defaultType || 'pengingat';
        updateMessageTemplate();

        waOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeWaModal() {
        if (!waOverlay) return;
        waOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    function executeSendBot() {
        const phone = currentLoanData.phone || '';
        const message = messageTextarea.value.trim();

        if (!phone) {
            alert('Nomor HP siswa belum dicantumkan di profil pengguna.');
            return;
        }
        if (!message) {
            alert('Pesan WhatsApp tidak boleh kosong.');
            return;
        }

        const originalHtml = sendBotBtn.innerHTML;
        sendBotBtn.disabled = true;
        sendBotBtn.innerHTML = 'Mengirim...';

        const adminPrefix = (window.location.pathname.indexOf('/admin/') !== -1 && window.location.pathname.indexOf('/admin/peminjaman/') !== -1) ? '../' : (window.location.pathname.indexOf('/admin/') !== -1 ? '' : 'admin/');

        const formData = new FormData();
        formData.append('phone', phone);
        formData.append('message', message);
        formData.append('peminjaman_id', currentLoanData.id || 0);

        fetch(adminPrefix + 'api_send_wa.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => res.json())
        .then(data => {
            sendBotBtn.disabled = false;
            sendBotBtn.innerHTML = originalHtml;

            if (data.success) {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Terkirim!',
                        text: 'Pesan Bot WhatsApp berhasil dikirim ke ' + phone,
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    alert('Pesan Bot WhatsApp berhasil dikirim ke ' + phone);
                }
                closeWaModal();
            } else {
                if (data.is_fallback) {
                    if (confirm(data.message + '\n\nBuka WhatsApp Web / App sekarang untuk mengirim manual?')) {
                        window.open(data.url, '_blank');
                    }
                } else {
                    alert('Gagal mengirim: ' + data.message);
                }
            }
        })
        .catch(err => {
            sendBotBtn.disabled = false;
            sendBotBtn.innerHTML = originalHtml;
            alert('Terjadi kesalahan jaringan: ' + err.message);
        });
    }

    window.openSipalaWa = openWaModal;
    window.closeSipalaWa = closeWaModal;

    // Delegasi klik otomatis untuk elemen data-wa-target
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-wa-target]');
        if (!btn) return;
        e.preventDefault();

        const id = btn.getAttribute('data-wa-id') || 0;
        const nama = btn.getAttribute('data-wa-nama') || '';
        const kelas = btn.getAttribute('data-wa-kelas') || '';
        const phone = btn.getAttribute('data-wa-phone') || '';
        const kode = btn.getAttribute('data-wa-kode') || '';
        const alat = btn.getAttribute('data-wa-alat') || '';
        const tglKembali = btn.getAttribute('data-wa-kembali') || '';
        const defaultType = btn.getAttribute('data-wa-type') || 'pengingat';

        openWaModal({
            id: id,
            nama: nama,
            kelas: kelas,
            phone: phone,
            kode: kode,
            alat: alat,
            tgl_kembali: tglKembali,
            defaultType: defaultType
        });
    });
})();
