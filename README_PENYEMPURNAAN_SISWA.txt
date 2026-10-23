SIPALA - PENYEMPURNAAN ALUR SISWA

Perubahan:
1. Katalog siswa di index.php dibuat lebih mudah dipindai dengan card ringan, tanpa mengganti style utama SIPALA.
2. Ditambahkan status ketersediaan alat: tersedia, stok terbatas, dan tidak tersedia.
3. Tombol "Pinjam" langsung menuju form pengajuan dan dapat memilih alat dari katalog.
4. Form ajukan.php menyorot alat yang dipilih dari katalog dan menampilkan foto alat jika kolom foto berisi path yang valid.
5. Ditambahkan halaman detail.php khusus siswa untuk melihat detail peminjaman milik sendiri.
6. Pengajuan Saya ditambah tombol Detail dan Pinjam Lagi untuk transaksi yang sudah selesai/ditolak.
7. Status peminjaman dibuat lebih mudah dibaca dengan badge yang tetap mengikuti warna utama SIPALA.
8. Perbaikan kecil pada validasi tanggal_pengajuan agar dikirim sebagai format DATE.
9. Tidak mengubah database, nama tabel, nama kolom, session, atau alur approval/pengembalian yang sudah ada.
10. Semua file PHP lolos pemeriksaan sintaks PHP 8.x.

Catatan:
- CSS utama lama dipertahankan; perubahan UI hanya berupa tambahan styling/polishing.
- Pastikan path pada kolom peralatan.foto sesuai lokasi file jika ingin gambar tampil.
