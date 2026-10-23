SIPALA - PENYEMPURNAAN TAMBAHAN

Perubahan pada versi ini:
1. Halaman Admin > Peralatan memiliki pencarian berdasarkan nama barang, kode barang, dan lokasi.
2. Filter kategori dan kondisi alat.
3. Pagination 10 data per halaman, dengan filter tetap dipertahankan saat berpindah halaman.
4. Statistik total peralatan dihitung dari seluruh data, bukan hanya hasil pencarian.
5. Pesan kosong yang lebih informatif saat data tidak ditemukan.
6. Query pencarian menggunakan prepared statement untuk mengurangi risiko SQL injection.
7. Pesan penghapusan lebih jelas jika data alat terkait transaksi sehingga database menolak penghapusan.

Cara menggunakan:
- Ganti folder proyek SIPALA lama dengan isi paket ini atau salin file yang berubah.
- Tidak ada perubahan skema database untuk pembaruan ini.
- Buka Admin > Peralatan, lalu gunakan kolom pencarian dan filter.

Catatan:
Perubahan ini berfokus pada halaman daftar peralatan. Fitur lain seperti notifikasi email/WhatsApp, unggah foto alat, dan backup database belum ditambahkan pada versi ini. Uji aplikasi di lingkungan lokal sebelum dipakai produksi.
