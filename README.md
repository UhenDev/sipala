# SIPALA — Sistem Informasi Peminjaman Alat Laboratorium

Sistem Informasi Manajemen Peminjaman dan Pengembalian Alat Laboratorium Sekolah/Kampus berbasis Web dengan Pelacakan Unit Fisik, Integrasi Kartu RFID, Kalkulasi Denda Otomatis, dan Audit Aktivitas.

---

## 1. Deskripsi Proyek
**SIPALA** adalah aplikasi manajemen laboratorium yang dirancang khusus untuk mengelola sirkulasi peminjaman alat praktik secara presisi, akuntabel, dan transparan. 

Berbeda dari aplikasi inventaris umum yang hanya mencatat jumlah kuantitas stok agregat, SIPALA memisahkan konsep antara **Master Peralatan** (tipe/katalog alat) dan **Unit Fisik Individual** (nomor seri/kode unit/tag RFID unik). Hal ini memungkinkan laboran mengetahui secara pasti unit mana yang sedang dipinjam oleh siswa, kondisi fisiknya saat keluar dan kembali, serta riwayat kerusakan individual setiap barang.

---

## 2. Fitur Utama
1. **Pemisahan Master Alat & Unit Fisik:**
   - Pencatatan spesifikasi master alat laboratorium.
   - Manajemen unit individual dengan kode unit (misal `LPT-001`), serial number pabrik, tag RFID fisik, dan kondisi fisik individual (Baik, Rusak Ringan, Rusak Berat).
2. **Katalog Publik & Portal Siswa:**
   - Siswa dapat menelusuri alat laboratorium yang tersedia secara *real-time*.
   - Siswa dapat memilih unit alat, menentukan tanggal rencana pengembalian, dan mengajukan pinjaman mandiri.
3. **Validasi & Workflow Peminjaman Transaksional:**
   - Verifikasi pengajuan pinjaman oleh laboran / administrator.
   - Pengalokasian unit fisik secara otomatis atau terarah.
   - Serah terima alat dengan pemindaian kartu RFID siswa atau verifikasi manual.
4. **Pengembalian Cepat & Cek Fisik:**
   - Pencarian transaksi pengembalian via pemindaian kartu RFID, barcode transaksi, kode unit alat, atau nama/NIS siswa.
   - Pemeriksaan kondisi fisik saat pengembalian (Baik / Rusak Ringan / Rusak Berat).
5. **Kalkulasi Keterlambatan & Denda Otomatis:**
   - Penghitungan otomatis selisih hari keterlambatan berdasarkan tanggal jatuh tempo.
   - Kalkulasi nominal denda harian sesuai tarif sistem yang dapat disesuaikan.
   - Pencatatan status pelunasan denda (Lunas / Belum Lunas).
6. **Integrasi Kartu RFID / Barcode Scanner:**
   - Mendukung login instan via tap kartu RFID siswa.
   - Registrasi dan pemasangan UID kartu RFID langsung ke profil siswa.
   - Pemindaian cepat pada form peminjaman dan pengembalian.
7. **Pemberitahuan & Notifikasi Interaktif:**
   - Notifikasi otomatis status persetujuan, serah terima, dan verifikasi pengembalian alat.
   - Sinkronisasi instan tanda baca (*mark as read*) tanpa reload halaman.
8. **Audit Trail & Log Aktivitas:**
   - Setiap mutasi data (CREATE, UPDATE, DELETE, BORROW, RETURN) dicatat dalam tabel `audit_log` lengkap dengan ID pengguna, aksi, rincian, dan timestamp.
9. **Laporan & Rekapitulasi Data:**
   - Ekspor dan cetak laporan inventaris, riwayat peminjaman, serta status denda laboratorium.

---

## 3. Hak Akses (Role Pengguna)
Aplikasi SIPALA memiliki 3 tingkatan hak akses:

| Role | Deskripsi & Hak Akses |
| :--- | :--- |
| **admin** | Administrator Laboratorium: Memiliki akses penuh ke seluruh modul, master alat, unit fisik, pengguna, persetujuan pinjaman, pengembalian, pengaturan sistem, tarif denda, dan audit log. |
| **petugas** | Laboran / Petugas Lab: Bertanggung jawab dalam operasional harian: approval peminjaman, serah terima barang, penerimaan pengembalian, cek kondisi fisik alat, dan input denda. |
| **peminjam** | Siswa / Mahasiswa: Mengakses portal katalog publik, mengajukan permohonan pinjaman, memantau batas waktu pengembalian, riwayat denda, dan notifikasi akun. |

---

## 4. Teknologi yang Digunakan
- **Backend:** PHP 8.x (Native murni berorientasi keamanan dan modularitas tanpa bloatware).
- **Database:** MySQL / MariaDB dengan *InnoDB Engine*, *Foreign Key Constraints*, dan *ACID Transactions*.
- **Database Driver:** PHP Data Objects (`PDO`) dengan *Prepared Statements* anti SQL-Injection.
- **Frontend:** HTML5 Semantik, CSS3 Murni (Clean Design System tanpa ketergantungan library eksternal berat), Vanilla JavaScript.
- **Hardware Layer:** Kompatibel dengan USB RFID Reader standar (Keyboard Emulation Mode 13.56MHz Mifare / 125KHz Proximity) dan USB Barcode Scanner.

---

## 5. Struktur Folder Proyek
```text
SIPALA/
├── .agents/                    # Konfigurasi dan aturan developer AI
│   └── rules/
│       └── aturan_dokumentasi.md
├── admin/                      # Modul Administrasi & Operasional Laboran
│   ├── includes/
│   │   └── admin.php           # Master template layout, sidebar & middleware admin
│   ├── kategori/
│   │   └── index.php           # Pengalihan terpadu kategori ke Master Peralatan
│   ├── laporan/
│   │   └── index.php           # Rekapitulasi dan pelaporan laboratorium
│   ├── peminjaman/
│   │   ├── detail.php          # Rincian transaksi & cetak bukti serah terima
│   │   ├── index.php           # Daftar & pemrosesan persetujuan transaksi
│   │   └── tambah.php          # Form peminjaman langsung via tap RFID
│   ├── pengembalian/
│   │   └── index.php           # Konsol pengembalian, cek fisik & denda
│   ├── pengguna/
│   │   ├── edit.php            # Edit profil, peranan, dan pasang kartu RFID
│   │   ├── hapus.php           # Hapus pengguna dengan integritas relasi
│   │   ├── index.php           # Daftar seluruh akun pengguna & siswa
│   │   └── tambah.php          # Registrasi manual akun siswa/petugas
│   ├── peralatan/
│   │   ├── edit.php            # Ubah spesifikasi master alat & kategori
│   │   ├── hapus.php           # Hapus master alat jika tidak memiliki riwayat aktif
│   │   ├── index.php           # Daftar master alat laboratorium
│   │   └── tambah.php          # Tambah jenis alat baru & kategori instan
│   ├── unit/
│   │   ├── edit.php            # Edit serial number, RFID unit, kondisi fisik
│   │   ├── hapus.php           # Hapus unit fisik jika tidak sedang dipinjam
│   │   ├── index.php           # Pelacakan inventaris unit individual
│   │   └── tambah.php          # Tambah unit fisik baru untuk master alat
│   ├── api_notif_read.php      # Endpoint AJAX penanda notifikasi terbaca
│   ├── audit_log.php           # Pemantauan riwayat log aktivitas sistem
│   ├── dashboard.php           # Panel utama statistik laboratorium 1-baris kompak
│   └── pengaturan.php          # Konfigurasi batas pinjam & tarif denda harian
├── assets/                     # Aset Statis
│   ├── css/
│   │   ├── image-cropper-modal.css
│   │   ├── rfid.css            # Gaya badge status & kartu identitas siswa
│   │   └── style.css           # Gaya utama portal siswa
│   ├── js/
│   │   └── rfid.js             # Event listener pemindai kartu RFID USB
│   └── uploads/                # Direktori penyimpanan foto alat & profil pengguna
├── config/
│   └── database.php            # Konfigurasi koneksi PDO & parameter database
├── database/
│   ├── SIPALA.sql              # Skema DDL & data awal sistem
│   └── run_migration.php       # Script otomatisasi migrasi struktur basis data
├── includes/
│   ├── csrf.php                # Pustaka proteksi CSRF Token
│   └── functions.php           # Helper global (stok, denda, notif, audit log)
├── AGENTS.md                   # Panduan integrasi kode
├── index.php                   # Portal utama siswa & katalog alat
├── login.php                   # Halaman masuk sistem (kredensial & kartu RFID)
├── logout.php                  # Penghentian sesi pengguna
├── profil.php                  # Halaman profil peminjam / petugas
├── README.md                   # Dokumentasi resmi proyek
└── register.php                # Pendaftaran akun siswa baru
```

---

## 6. Arsitektur Database
Sistem menggunakan skema relasional dengan *Foreign Key Constraints* ketat:
```text
users ───< peminjaman ───< detail_peminjaman >─── unit_peralatan >─── peralatan >─── kategori
               │                                       │
               ├───< pengembalian                      └─── (stok disinkronkan)
               └───< notifikasi
```
- `peralatan`: Master katalog tipe barang (nama alat, deskripsi, foto, total stok teragregasi).
- `unit_peralatan`: Fisik individual setiap alat (`kode_unit`, `serial_number`, `rfid_uid`, `kondisi`, `status`).
- `peminjaman`: Induk transaksi peminjaman (`kode_peminjaman`, `user_id`, `tanggal_pinjam`, `tanggal_rencana_kembali`, `status`).
- `detail_peminjaman`: Relasi peminjaman dengan master alat dan unit fisik tertentu.
- `pengembalian`: Berkas pencatatan pengembalian (`terlambat_hari`, `denda`, `kondisi_barang`, `diproses_oleh`).
- `audit_log`: Catatan jejak audit sistem (`aksi`, `tabel`, `record_id`, `keterangan`, `ip_address`).

---

## 7. Persyaratan & Instalasi XAMPP
### Persyaratan Sistem:
- Apache 2.4+
- PHP 8.1 atau yang lebih baru (dengan ekstensi `pdo_mysql`, `mbstring`, `fileinfo`)
- MySQL 5.7+ atau MariaDB 10.4+
- Peramban web modern (Google Chrome, Firefox, Microsoft Edge)

### Langkah Instalasi:
1. Pastikan **XAMPP** sudah terinstal di komputer Anda.
2. Tempatkan folder proyek di dalam direktori `htdocs`, contoh:
   ```text
   C:\xampp\htdocs\SIPALA
   ```
3. Buka **XAMPP Control Panel** dan jalankan modul **Apache** dan **MySQL**.

---

## 8. Konfigurasi Database
1. Buka browser dan buka `http://localhost/phpmyadmin`.
2. Buat database baru bernama `sipala`.
3. Import file `database/SIPALA.sql` ke dalam database `sipala` tersebut.
4. Periksa file konfigurasi [config/database.php](file:///C:/xampp2/htdocs/SIPALA/config/database.php):
   ```php
   $host = 'localhost';
   $db   = 'sipala';
   $user = 'root';
   $pass = '';
   ```
   Sesuaikan kredensial di atas jika MySQL Anda menggunakan port atau password berbeda.

---

## 9. Cara Menjalankan Aplikasi
1. Buka browser dan akses alamat:
   ```text
   http://localhost/SIPALA/
   ```
2. Akun default sistem untuk pengujian awal:
   - **Administrator:**
     - Username: `admin`
     - Password: `password` atau `admin123`
   - **Siswa (Peminjam):**
     - Registrasi langsung melalui halaman `register.php` atau login menggunakan akun siswa terdaftar.

---

## 10. Workflow Peminjaman
```text
Siswa Mengajukan Pinjaman
         ↓
Status: MENUNGGU
         ↓
Laboran Memeriksa Ketersediaan Unit Fisik
         ↓
Laboran Menyetujui (Status: DISETUJUI)
         ↓
Siswa Datang ke Laboratorium (Serah Terima Alat)
         ↓
Scan Kartu RFID Siswa / Verifikasi Manual
         ↓
Unit Fisik Dialokasikan (Status Unit: DIPINJAM)
         ↓
Status Transaksi: DIPINJAM (Stok Tersedia Berkurang)
```

---

## 11. Workflow Pengembalian & Denda
```text
Siswa Mengembalikan Alat ke Laboran
         ↓
Pencarian Transaksi via Konsol Pengembalian (Scan RFID / Input Kode Pinjam)
         ↓
Laboran Memeriksa Kondisi Fisik Alat (Baik / Rusak Ringan / Rusak Berat)
         ↓
Sistem Otomatis Menghitung Keterlambatan:
  Jika hari ini > tanggal jatuh tempo:
  hari terlambat × tarif denda = nominal denda
         ↓
Simpan Pengembalian:
  - Unit fisik diperbarui kondisinya.
  - Jika kondisi Baik/Rusak Ringan: Unit kembali TERSEDIA.
  - Jika Rusak Berat: Unit dialihkan ke status RUSAK.
         ↓
Stok Master Peralatan Otomatis Disinkronkan
         ↓
Status Transaksi: SELESAI & Audit Log Tercatat
```

---

## 12. Sistem RFID & Barcode Scanner
SIPALA mengadopsi protokol emulasi *Keyboard HID (Human Interface Device)* yang merupakan standar industri pembaca kartu RFID dan barcode scanner USB:
1. Reader membaca chip RFID (UID/kartu Mifare 13.56MHz atau EM4100 125KHz).
2. Reader mengetikkan deretan nomor UID ke kolom input yang sedang aktif (`.rfid-autofocus`) dan mengirimkan sinyal `Enter`.
3. Skrip JavaScript [assets/js/rfid.js](file:///C:/xampp2/htdocs/SIPALA/assets/js/rfid.js) secara otomatis menangkap sinyal `Enter`, memicu audio konfirmasi (*beep*), dan langsung memproses formulir pencarian atau autentikasi secara instan tanpa perlu klik mouse manual.

---

## 13. Standar Keamanan Sistem
- **Proteksi SQL Injection:** 100% interaksi query dinamis menggunakan *Prepared Statements* dan parameter binding `PDO`.
- **Proteksi Cross-Site Request Forgery (CSRF):** Seluruh formulir bermetode `POST` dilindungi oleh token sesi kriptografis via `csrf_field()` dan divalidasi dengan `require_csrf()`.
- **Penyimpanan Password:** Password dienkripsi menggunakan algoritma *Bcrypt* modern melalui `password_hash()` dan diverifikasi via `password_verify()`.
- **Proteksi XSS (Cross-Site Scripting):** Seluruh variabel keluaran pengguna di-escape menggunakan fungsi `e()` (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`).
- **Autentikasi & Otorisasi Bertingkat:** Middleware sisi server memastikan pengguna hanya dapat mengakses fitur yang diizinkan sesuai `$_SESSION['role']`.
- **Pencegahan Race Condition:** Penyesuaian stok dan alokasi unit dilakukan dalam blok transaksi database dengan penguncian baris (`FOR UPDATE`).

---

## 14. Prosedur Pengujian (Testing)
Pengujian mandiri dapat dilakukan dengan skenario:
1. **Uji Autentikasi:** Login salah memasukkan kredensial (pesan error muncul), login benar (diarahkan ke dashboard).
2. **Uji Otorisasi:** Siswa mencoba mengakses URL `admin/` langsung dicegah dan dialihkan ke login.
3. **Uji CRUD Master Alat & Kategori:** Menambah alat dengan mengetikkan kategori baru langsung di form Master Peralatan tanpa melalui menu terpisah.
4. **Uji Alokasi Unit Fisik:** Menambahkan 2 unit fisik (kondisi Baik dan Rusak Ringan), cek apakah stok master otomatis tersinkronisasi menjadi 2.
5. **Uji Transaksi Peminjaman:** Mengajukan pinjaman 1 unit, lakukan serah terima, pastikan status unit fisik menjadi `DIPINJAM` dan stok tersedia berkurang 1.
6. **Uji Pengembalian & Denda:** Kembalikan alat lewat batas tanggal, periksa kalkulasi otomatis denda harian, selesaikan transaksi, dan pastikan stok master kembali sinkron.
7. **Uji Audit Log:** Buka halaman [admin/audit_log.php](file:///C:/xampp2/htdocs/SIPALA/admin/audit_log.php) dan pastikan seluruh riwayat aktivitas peminjaman dan pengembalian tercatat dengan timestamp akurat.
