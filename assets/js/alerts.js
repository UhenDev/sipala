/**
 * SIPALA - Notifikasi & Konfirmasi Modern (SweetAlert2 Helper)
 */
(function (window) {
    'use strict';

    window.confirmDelete = function (url, itemName) {
        function executePostDelete() {
            const metaCsrf = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = metaCsrf ? metaCsrf.getAttribute('content') : '';

            const parts = url.split('?');
            const actionUrl = parts[0];
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = actionUrl;
            form.style.display = 'none';

            if (csrfToken) {
                const inputCsrf = document.createElement('input');
                inputCsrf.type = 'hidden';
                inputCsrf.name = 'csrf_token';
                inputCsrf.value = csrfToken;
                form.appendChild(inputCsrf);
            }

            if (parts[1]) {
                const params = new URLSearchParams(parts[1]);
                params.forEach(function (val, key) {
                    const inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = key;
                    inp.value = val;
                    form.appendChild(inp);
                });
            }

            document.body.appendChild(form);
            form.submit();
        }

        if (!window.Swal) {
            if (confirm('Apakah Anda yakin ingin menghapus ' + (itemName || 'data ini') + '?')) {
                executePostDelete();
            }
            return;
        }

        Swal.fire({
            title: 'Hapus Data?',
            html: 'Apakah Anda yakin ingin menghapus <strong>' + (itemName || 'data ini') + '</strong>?<br><small style=color:#64748b;font-size:12px;margin-top:6px;display:block;>Tindakan ini tidak dapat dibatalkan.</small>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal',
            reverseButtons: true,
            focusCancel: true
        }).then(function (result) {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Menghapus Data...',
                    text: 'Sedang memproses penghapusan data.',
                    allowOutsideClick: false,
                    didOpen: function () { Swal.showLoading(); }
                });
                executePostDelete();
            }
        });
    };

    window.confirmAction = function (formId, title, text, confirmBtnText, confirmBtnColor) {
        const form = document.getElementById(formId);
        if (!form) return;

        if (!window.Swal) {
            if (confirm(text || 'Lanjutkan tindakan ini?')) {
                form.submit();
            }
            return;
        }

        Swal.fire({
            title: title || 'Konfirmasi Tindakan',
            text: text || 'Apakah Anda yakin ingin melanjutkan?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: confirmBtnColor || '#1f4f82',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: confirmBtnText || 'Ya, Lanjutkan',
            cancelButtonText: 'Batal',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    };

    window.confirmLogout = function (logoutUrl) {
        if (!window.Swal) {
            if (confirm('Keluar dari SIPALA?')) {
                window.location.href = logoutUrl;
            }
            return;
        }

        Swal.fire({
            title: 'Keluar dari SIPALA?',
            text: 'Anda akan keluar dari sesi akun dan kembali ke halaman login.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'Ya, Keluar',
            cancelButtonText: 'Batal',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = logoutUrl;
            }
        });
    };

    window.showToast = function (type, message) {
        if (!window.Swal) {
            alert(message);
            return;
        }

        const isSuccess = type === 'success';
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: isSuccess ? 'success' : 'error',
            title: message,
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        // Hubungkan semua tombol logout
        const logoutBtns = document.querySelectorAll('#logoutButton, #logoutBtn, .btn-logout-trigger, .logout-button, .menu-item.logout');
        logoutBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                let logoutPath = btn.getAttribute('data-logout-url') || btn.getAttribute('href');
                if (!logoutPath) {
                    const p = window.location.pathname.toLowerCase();
                    if (p.includes('/admin/kategori/') || p.includes('/admin/pengguna/') || p.includes('/admin/peralatan/') || p.includes('/admin/peminjaman/') || p.includes('/admin/pengembalian/')) {
                        logoutPath = '../../logout.php';
                    } else if (p.includes('/admin/')) {
                        logoutPath = '../logout.php';
                    } else {
                        logoutPath = 'logout.php';
                    }
                }
                confirmLogout(logoutPath);
            });
        });
    });
})(window);
