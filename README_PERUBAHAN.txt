SIPALA - VERSI DIPERBARUI

Perubahan utama:
1. Peminjam/siswa sekarang dapat mengajukan peminjaman sendiri melalui ajukan.php.
2. Pengajuan masuk status MENUNGGU dan tidak langsung mengurangi stok.
3. Admin/petugas dapat menyetujui atau menolak pengajuan.
4. Penolakan wajib memiliki alasan.
5. Saat alat diserahkan, sistem memeriksa RFID siswa dan baru mengurangi stok.
6. Tanggal persetujuan dan tanggal serah terima dicatat.
7. Pengembalian dipindahkan ke halaman proses.php agar kondisi dapat dicatat per alat.
8. Kondisi Rusak Berat tidak otomatis ditambahkan kembali ke stok tersedia.
9. Denda keterlambatan otomatis mengambil tarif dari pengaturan tarif_denda_harian.
10. Log aktivitas mencatat login, logout, pengajuan, persetujuan, penolakan, serah terima, pengembalian, dan perubahan pengaturan.
11. Ditambahkan halaman Laporan dengan filter tanggal dan export CSV.
12. Ditambahkan halaman Log Aktivitas untuk admin.
13. Menu Pengguna dan Pengaturan dibatasi untuk admin.
14. Ditambahkan CSRF protection pada login, pengajuan, approval, serah terima, pengembalian, dan pengaturan.
15. Notifikasi login/logout diperbaiki.
16. Database tidak membutuhkan tabel baru karena fitur menggunakan tabel SIPALA yang sudah ada.

Catatan:
- Import database/SIPALA.sql atau sipala.sql seperti biasa.
- Pastikan database bernama sipala dan MySQL/MariaDB aktif.
- Pastikan PHP mendukung PDO MySQL dan password_verify.
