<?php

/**
 * FILE    : index.php
 * MODUL   : Portal Publik & Dashboard Peminjam SIPALA
 * FUNGSI  : Menampilkan halaman utama SIPALA sebelum login (katalog publik & informasi),
 *           serta dashboard personal siswa peminjam setelah login (kartu digital, status transaksi, denda, dan katalog praktikum).
 * AKSES   : Publik (Sebelum Login) / Siswa & Peminjam (Setelah Login)
 * ALUR    : Pengguna belum login → melihat landing/katalog publik → masuk via login/RFID
 *           Siswa telah login → diarahkan ke dashboard peminjam (kartu digital, monitoring pinjaman, katalog praktikum).
 */

declare(strict_types=1);

session_name('sipala_session');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
$pdo = db();

// Ambil konfigurasi operasional dari tabel pengaturan
$settingsRows = $pdo->query('SELECT nama, nilai FROM pengaturan')->fetchAll(PDO::FETCH_KEY_PAIR);
$maksHariPinjam = max(1, (int)($settingsRows['maks_hari_pinjam'] ?? 3));
$maksUnitPinjam = max(1, (int)($settingsRows['maks_unit_pinjam'] ?? 3));
$tarifDendaHarian = (int)($settingsRows['tarif_denda_harian'] ?? 1000);
$kontakLabWa = (string)($settingsRows['kontak_lab_wa'] ?? '');
$jamLab = (string)($settingsRows['jam_operasional_lab'] ?? 'Senin - Jumat: 07.30 - 15.30 WIB');
$lokasiLab = (string)($settingsRows['lokasi_ruang_lab'] ?? 'Ruang Lab Komputer RPL');
$namaInstansi = (string)($settingsRows['nama_instansi'] ?? 'SMKN 1 PANYINGKIRAN');

if (!function_exists('e')) {
    function e(string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

// Flash session handlers
$loginSuccess = $_SESSION['login_success'] ?? '';
unset($_SESSION['login_success']);

$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

$loanSubmitted = $_SESSION['loan_submitted'] ?? null;
unset($_SESSION['loan_submitted']);

// Status login pengguna
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = (string)($_SESSION['role'] ?? '');

// Jika admin/petugas mengakses index.php, arahkan ke dashboard admin
if ($isLoggedIn && in_array($userRole, ['admin', 'petugas'], true)) {
    header('Location: admin/dashboard.php');
    exit;
}

// Data pengguna yang sedang login (Role: peminjam / siswa)
$userId = (int)($_SESSION['user_id'] ?? 0);
$userName = (string)($_SESSION['nama'] ?? 'Siswa');
$userNis = (string)($_SESSION['nis'] ?? '-');
$userKelas = (string)($_SESSION['kelas'] ?? '-');
$userRfid = (string)($_SESSION['rfid_uid'] ?? '');
$userFoto = (string)($_SESSION['foto'] ?? '');
$userAlamat = '';
$userNoHp = '';

// Ambil data profil segar jika user login
if ($isLoggedIn && $userId > 0) {
    $stU = $pdo->prepare('SELECT nama, nis, kelas, rfid_uid, foto, no_hp, alamat FROM users WHERE id = ?');
    $stU->execute([$userId]);
    $freshU = $stU->fetch();
    if ($freshU) {
        $userName = $freshU['nama'];
        $userNis = $freshU['nis'] ?: '-';
        $userKelas = $freshU['kelas'] ?: '-';
        $userRfid = $freshU['rfid_uid'] ?: '';
        $userFoto = $freshU['foto'] ?: '';
        $userAlamat = (string)($freshU['alamat'] ?? '');
        $userNoHp = (string)($freshU['no_hp'] ?? '');
        $_SESSION['foto'] = $userFoto;
    }
}

// PROSES POST PENGAJUAN PEMINJAMAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_loan') {
    if (!verify_csrf()) {
        $_SESSION['flash_error'] = 'Sesi form kedaluwarsa. Silakan muat ulang halaman.';
        header('Location: index.php');
        exit;
    }

    $alatId = (int)($_POST['peralatan_id'] ?? 0);
    $jumlah = max(1, (int)($_POST['jumlah'] ?? 1));
    $tglKembali = trim((string)($_POST['tanggal_rencana_kembali'] ?? ''));
    $keperluan = trim((string)($_POST['keperluan'] ?? 'Praktikum Lab Komputer RPL'));

    // Cek peralatan di database
    $stAlat = $pdo->prepare('SELECT id, nama_barang, kode_barang, jumlah_tersedia, kondisi, status FROM peralatan WHERE id = ?');
    $stAlat->execute([$alatId]);
    $alat = $stAlat->fetch();

    if (!$alat || $alat['status'] !== 'aktif') {
        $_SESSION['flash_error'] = 'Peralatan tidak ditemukan atau sedang dinonaktifkan.';
        header('Location: index.php');
        exit;
    }

    if ($alat['jumlah_tersedia'] < $jumlah) {
        $_SESSION['flash_error'] = 'Stok peralatan tidak mencukupi (Tersedia: ' . $alat['jumlah_tersedia'] . ' unit).';
        header('Location: index.php');
        exit;
    }

    if ($tglKembali === '' || strtotime($tglKembali) < strtotime(date('Y-m-d'))) {
        $_SESSION['flash_error'] = 'Tanggal rencana pengembalian tidak valid (minimal hari ini).';
        header('Location: index.php');
        exit;
    }

    $maxAllowedDate = date('Y-m-d', strtotime('+' . $maksHariPinjam . ' days'));
    if ($tglKembali > $maxAllowedDate) {
        $_SESSION['flash_error'] = 'Batas maksimal durasi peminjaman adalah ' . $maksHariPinjam . ' hari (maksimal s/d ' . date('d-m-Y', strtotime($maxAllowedDate)) . ').';
        header('Location: index.php');
        exit;
    }

    if ($jumlah > $maksUnitPinjam) {
        $_SESSION['flash_error'] = 'Batas maksimal peminjaman per transaksi adalah ' . $maksUnitPinjam . ' unit alat.';
        header('Location: index.php');
        exit;
    }

    // Tentukan user peminjam
    $borrowerUserId = 0;
    $borrowerNama = '';
    if ($isLoggedIn && $userId > 0) {
        $borrowerUserId = $userId;
        $borrowerNama = $userName;
    } else {
        // Peminjam Tamu / Siswa tanpa login
        $guestNama = trim((string)($_POST['guest_nama'] ?? ''));
        $guestNis = trim((string)($_POST['guest_nis'] ?? ''));
        $guestKelas = trim((string)($_POST['guest_kelas'] ?? ''));
        $guestHp = trim((string)($_POST['guest_hp'] ?? ''));

        if ($guestNama === '' || $guestNis === '' || $guestKelas === '') {
            $_SESSION['flash_error'] = 'Untuk pengajuan mandiri, Nama Lengkap, NIS, dan Kelas wajib diisi.';
            header('Location: index.php');
            exit;
        }

        $borrowerNama = $guestNama;

        // Cek apakah NIS sudah ada di tabel users
        $chk = $pdo->prepare('SELECT id, nama, role FROM users WHERE nis = ? LIMIT 1');
        $chk->execute([$guestNis]);
        $existingUser = $chk->fetch();

        if ($existingUser) {
            $borrowerUserId = (int)$existingUser['id'];
        } else {
            // Buat akun peminjam otomatis
            $cleanNis = preg_replace('/[^a-zA-Z0-9]/', '', $guestNis);
            $username = 'siswa_' . ($cleanNis !== '' ? $cleanNis : bin2hex(random_bytes(3)));
            
            $chkU = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $chkU->execute([$username]);
            if ($chkU->fetch()) {
                $username .= '_' . bin2hex(random_bytes(2));
            }
            $pwdHash = password_hash($guestNis, PASSWORD_BCRYPT);
            $insU = $pdo->prepare('
                INSERT INTO users (nama, nis, username, password, role, kelas, no_hp, status)
                VALUES (?, ?, ?, ?, "peminjam", ?, ?, "aktif")
            ');
            $insU->execute([$guestNama, $guestNis, $username, $pwdHash, $guestKelas, $guestHp !== '' ? $guestHp : null]);
            $borrowerUserId = (int)$pdo->lastInsertId();
        }
    }

    // Buat Kode Transaksi Unik
    $kodePinjam = 'PJM-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

    try {
        $pdo->beginTransaction();

        $insP = $pdo->prepare('
            INSERT INTO peminjaman (
                kode_peminjaman, user_id, tanggal_pengajuan, tanggal_pinjam, tanggal_rencana_kembali,
                keperluan, status
            ) VALUES (?, ?, CURDATE(), CURDATE(), ?, ?, "MENUNGGU")
        ');
        $insP->execute([$kodePinjam, $borrowerUserId, $tglKembali, $keperluan]);
        $peminjamanId = (int)$pdo->lastInsertId();

        $insD = $pdo->prepare('
            INSERT INTO detail_peminjaman (peminjaman_id, peralatan_id, jumlah, kondisi_awal)
            VALUES (?, ?, ?, ?)
        ');
        $insD->execute([$peminjamanId, $alatId, $jumlah, $alat['kondisi'] ?? 'Baik']);

        $pdo->commit();

        // Audit log & Notifikasi
        audit_log('REQUEST', 'PEMINJAMAN', $peminjamanId, "Pengajuan pinjaman $kodePinjam ($borrowerNama - $jumlah unit {$alat['nama_barang']})", $borrowerUserId);
        send_notification($borrowerUserId, 'Pengajuan Berhasil Dikirim', "Pengajuan peminjaman $kodePinjam telah terkirim dan menunggu verifikasi petugas Lab RPL.", 'info', 'index.php');
        notify_all_admins("Pengajuan Pinjaman Baru: $kodePinjam", "Siswa $borrowerNama mengajukan $jumlah unit {$alat['nama_barang']}.", 'info', 'peminjaman/index.php');

        $_SESSION['loan_submitted'] = [
            'kode' => $kodePinjam,
            'alat' => $alat['nama_barang'],
            'jumlah' => $jumlah,
            'nama' => $borrowerNama,
            'tgl_kembali' => $tglKembali
        ];

        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Gagal memproses pengajuan: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Gagal memproses pengajuan. Silakan periksa kembali data pengajuan Anda.';
        header('Location: index.php');
        exit;
    }
}

// PROSES BATALKAN PENGAJUAN (OLEH SISWA)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_loan') {
    if (!verify_csrf()) {
        $_SESSION['flash_error'] = 'Sesi form kedaluwarsa.';
        header('Location: index.php');
        exit;
    }

    $cancelId = (int)($_POST['loan_id'] ?? 0);
    try {
        $stChk = $pdo->prepare("SELECT id, kode_peminjaman, status, user_id FROM peminjaman WHERE id = ?");
        $stChk->execute([$cancelId]);
        $loanToCancel = $stChk->fetch();

        if (!$loanToCancel) {
            $_SESSION['flash_error'] = 'Data peminjaman tidak ditemukan.';
        } elseif ($loanToCancel['status'] !== 'MENUNGGU') {
            $_SESSION['flash_error'] = 'Hanya pengajuan dengan status MENUNGGU yang dapat dibatalkan.';
        } elseif (!$isLoggedIn || (int)$loanToCancel['user_id'] !== $userId) {
            $_SESSION['flash_error'] = 'Anda tidak memiliki hak untuk membatalkan pengajuan ini.';
        } else {
            $updCan = $pdo->prepare("UPDATE peminjaman SET status = 'DIBATALKAN' WHERE id = ?");
            $updCan->execute([$cancelId]);

            audit_log('CANCEL', 'PEMINJAMAN', $cancelId, "Membatalkan pengajuan {$loanToCancel['kode_peminjaman']}", $userId);
            $_SESSION['flash_error'] = '';
            $_SESSION['login_success'] = 'Pengajuan ' . $loanToCancel['kode_peminjaman'] . ' berhasil dibatalkan.';
        }
    } catch (Throwable $e) {
        error_log('Gagal membatalkan pengajuan: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Gagal membatalkan pengajuan karena kendala sistem. Silakan coba lagi.';
    }
    header('Location: index.php');
    exit;
}

// Data Peminjaman Siswa Login
$myPendingLoans = [];
$myApprovedLoans = [];
$myActiveLoans = [];
$myHistory = [];
$myFines = [];

if ($isLoggedIn && $userId > 0) {
    // 1. Menunggu Persetujuan
    $stmtWait = $pdo->prepare('
        SELECT p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_rencana_kembali, p.status, p.keperluan,
               COALESCE(SUM(d.jumlah), 0) AS total_item,
               GROUP_CONCAT(CONCAT(pr.nama_barang, " (", d.jumlah, " unit)") SEPARATOR ", ") AS daftar_alat
        FROM peminjaman p 
        LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id 
        LEFT JOIN peralatan pr ON pr.id = d.peralatan_id
        WHERE p.user_id = ? AND p.status = "MENUNGGU" 
        GROUP BY p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_rencana_kembali, p.status, p.keperluan
        ORDER BY p.id DESC
    ');
    $stmtWait->execute([$userId]);
    $myPendingLoans = $stmtWait->fetchAll();

    // 2. Disetujui (Siap diambil di Lab)
    $stmtAppr = $pdo->prepare('
        SELECT p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_rencana_kembali, p.status, p.keperluan,
               COALESCE(SUM(d.jumlah), 0) AS total_item,
               GROUP_CONCAT(CONCAT(pr.nama_barang, " (", d.jumlah, " unit)") SEPARATOR ", ") AS daftar_alat
        FROM peminjaman p 
        LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id 
        LEFT JOIN peralatan pr ON pr.id = d.peralatan_id
        WHERE p.user_id = ? AND p.status = "DISETUJUI" 
        GROUP BY p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_rencana_kembali, p.status, p.keperluan
        ORDER BY p.id DESC
    ');
    $stmtAppr->execute([$userId]);
    $myApprovedLoans = $stmtAppr->fetchAll();

    // 3. Peminjaman Aktif (Sedang Dipinjam)
    $stmtActive = $pdo->prepare('
        SELECT p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.status, p.keperluan,
               COALESCE(SUM(d.jumlah), 0) AS total_item,
               GROUP_CONCAT(CONCAT(pr.nama_barang, " (", d.jumlah, " unit)") SEPARATOR ", ") AS daftar_alat
        FROM peminjaman p 
        LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id 
        LEFT JOIN peralatan pr ON pr.id = d.peralatan_id
        WHERE p.user_id = ? AND p.status = "DIPINJAM" 
        GROUP BY p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.status, p.keperluan
        ORDER BY p.id DESC
    ');
    $stmtActive->execute([$userId]);
    $myActiveLoans = $stmtActive->fetchAll();

    // 4. Riwayat Selesai
    $stmtHist = $pdo->prepare('
        SELECT p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_realisasi_kembali, p.status,
               COALESCE(SUM(d.jumlah), 0) AS total_item,
               GROUP_CONCAT(CONCAT(pr.nama_barang, " (", d.jumlah, " unit)") SEPARATOR ", ") AS daftar_alat
        FROM peminjaman p 
        LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id 
        LEFT JOIN peralatan pr ON pr.id = d.peralatan_id
        WHERE p.user_id = ? AND p.status = "SELESAI" 
        GROUP BY p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_realisasi_kembali, p.status
        ORDER BY p.id DESC 
        LIMIT 5
    ');
    $stmtHist->execute([$userId]);
    $myHistory = $stmtHist->fetchAll();

    // 5. Riwayat Denda Siswa (Termasuk Status Pelunasan)
    $stmtFines = $pdo->prepare('
        SELECT pg.id, pg.tanggal_pengembalian, pg.terlambat_hari, pg.denda, pg.status_denda, pg.kondisi_barang, pg.catatan,
               p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali,
               GROUP_CONCAT(CONCAT(pr.nama_barang, " (", d.jumlah, " unit)") SEPARATOR ", ") AS daftar_alat
        FROM pengembalian pg 
        JOIN peminjaman p ON p.id = pg.peminjaman_id 
        LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id 
        LEFT JOIN peralatan pr ON pr.id = d.peralatan_id
        WHERE p.user_id = ? AND pg.denda > 0
        GROUP BY pg.id, pg.tanggal_pengembalian, pg.terlambat_hari, pg.denda, pg.status_denda, pg.kondisi_barang, pg.catatan, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali
        ORDER BY pg.id DESC
    ');
    $stmtFines->execute([$userId]);
    $myFines = $stmtFines->fetchAll();
}

// 100% REAL DATABASE STATS (Untuk Tampilan Publik)
$totalJenisAlat = (int)$pdo->query("SELECT COUNT(*) FROM peralatan WHERE status = 'aktif'")->fetchColumn();
$totalUnitTersedia = (int)$pdo->query("SELECT COALESCE(SUM(jumlah_tersedia), 0) FROM peralatan WHERE status = 'aktif'")->fetchColumn();
$totalUnitDipinjam = (int)$pdo->query("SELECT COALESCE(SUM(dp.jumlah), 0) FROM detail_peminjaman dp JOIN peminjaman p ON p.id = dp.peminjaman_id WHERE p.status = 'DIPINJAM'")->fetchColumn();

// Lacak status peminjaman via Kode Transaksi (Khusus Tamu / Publik)
$trackCode = trim((string)($_GET['track_kode'] ?? ''));
$trackedLoan = null;
if ($trackCode !== '') {
    $stmtTrack = $pdo->prepare('
        SELECT p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_pinjam, p.tanggal_rencana_kembali, 
               p.tanggal_realisasi_kembali, p.status, p.keperluan, p.alasan_penolakan,
               u.nama AS nama_peminjam, u.nis, u.kelas,
               COALESCE(SUM(d.jumlah), 0) AS total_item,
               GROUP_CONCAT(CONCAT(pr.nama_barang, " (", d.jumlah, " unit)") SEPARATOR ", ") AS daftar_alat
        FROM peminjaman p 
        LEFT JOIN users u ON u.id = p.user_id 
        LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id 
        LEFT JOIN peralatan pr ON pr.id = d.peralatan_id
        WHERE p.kode_peminjaman = ?
        GROUP BY p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_pinjam, p.tanggal_rencana_kembali, 
                 p.tanggal_realisasi_kembali, p.status, p.keperluan, p.alasan_penolakan, u.nama, u.nis, u.kelas
        LIMIT 1
    ');
    $stmtTrack->execute([$trackCode]);
    $trackedLoan = $stmtTrack->fetch();
}

// Ambil Kategori untuk Filter
$categories = $pdo->query('SELECT id, nama_kategori FROM kategori ORDER BY nama_kategori ASC')->fetchAll();

// Ambil Katalog Peralatan Lab Komputer RPL
$searchCatalog = trim((string)($_GET['q'] ?? ''));
$selectedKat = (int)($_GET['kat'] ?? 0);

$catalogSql = '
    SELECT p.id, p.kode_barang, p.nama_barang, p.jumlah_tersedia, p.jumlah, p.kondisi, 
           p.lokasi, p.deskripsi, p.foto, p.kategori_id, k.nama_kategori 
    FROM peralatan p 
    LEFT JOIN kategori k ON k.id = p.kategori_id 
    WHERE p.status = "aktif" 
';
$catParams = [];

if ($selectedKat > 0) {
    $catalogSql .= 'AND p.kategori_id = ? ';
    $catParams[] = $selectedKat;
}

if ($searchCatalog !== '') {
    $catalogSql .= 'AND (p.nama_barang LIKE ? OR p.kode_barang LIKE ? OR k.nama_kategori LIKE ?) ';
    $t = '%' . $searchCatalog . '%';
    $catParams[] = $t;
    $catParams[] = $t;
    $catParams[] = $t;
}

$catalogSql .= 'ORDER BY p.nama_barang ASC';
$stmtCat = $pdo->prepare($catalogSql);
$stmtCat->execute($catParams);
$catalog = $stmtCat->fetchAll();

$hasOfficialLogo = file_exists(__DIR__ . '/assets/img/logo.jpg');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isLoggedIn ? 'Dashboard Siswa Peminjam — SIPALA Lab RPL' : 'SIPALA — Peminjaman Alat Lab Komputer RPL' ?></title>
    <meta name="description" content="Sistem Informasi Peminjaman Alat Lab Komputer RPL - Katalog perangkat, informasi stok ketersediaan, dan pengajuan peminjaman laboratorium.">
    <link rel="icon" type="image/jpeg" href="assets/img/logo.jpg?v=2">
    <link rel="shortcut icon" type="image/jpeg" href="assets/img/logo.jpg?v=2">
    <link rel="apple-touch-icon" href="assets/img/logo.jpg?v=2">
    <link rel="stylesheet" href="assets/css/index.css">
    <link rel="stylesheet" href="assets/css/image-cropper-modal.css">
</head>
<body>

<!-- HEADER / NAVBAR (Disesuaikan berdasarkan status login) -->
<header class="site-header">
    <div class="container">
        <nav class="navbar">
            <a href="index.php" class="brand-wrapper">
                <?php if ($hasOfficialLogo): ?>
                    <img src="assets/img/logo.jpg" alt="Logo SIPALA" class="brand-logo">
                <?php else: ?>
                    <div class="brand-logo-fallback">SP</div>
                <?php endif; ?>
                <div class="brand-text">
                    <h1>SIPALA</h1>
                    <span><?= $isLoggedIn ? 'Portal Siswa Peminjam &bull; Lab Komputer RPL' : 'Sistem Informasi Peminjaman Alat Lab Komputer RPL' ?></span>
                </div>
            </a>

            <ul class="nav-links">
                <?php if ($isLoggedIn): ?>
                    <li><a href="#dashboard-saya" class="nav-link">Dashboard Saya</a></li>
                    <li><a href="#katalog" class="nav-link">Katalog Praktikum</a></li>
                    <li><a href="profil.php" class="nav-link">Profil Akun</a></li>
                <?php else: ?>
                    <li><a href="#katalog" class="nav-link">Katalog</a></li>
                    <li><a href="#cara-pinjam" class="nav-link">Cara Peminjaman</a></li>
                    <li><a href="#lacak" class="nav-link">Lacak Pengajuan</a></li>
                    <li><a href="#informasi" class="nav-link">Ketentuan</a></li>
                <?php endif; ?>
            </ul>

            <div class="nav-actions">
                <?php if ($isLoggedIn): ?>
                    <a href="profil.php" class="user-pill" title="Buka Profil Siswa">
                        <?php if (!empty($userFoto) && file_exists(__DIR__ . '/' . $userFoto)): ?>
                            <img src="<?= e($userFoto) ?>" alt="<?= e($userName) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <div class="user-avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
                        <?php endif; ?>
                        <div class="user-pill-meta">
                            <strong><?= e($userName) ?></strong>
                            <span><?= e($userKelas) ?> &bull; NIS: <?= e($userNis) ?></span>
                        </div>
                    </a>
                    <a href="profil.php" class="btn btn-sm">Profil Saya</a>
                    <a href="logout.php" onclick="confirmLogoutStudent(event)" class="btn btn-sm" style="color:var(--color-danger);border-color:var(--color-danger-border);">Keluar</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary">
                        Masuk / Tap Kartu RFID
                    </a>
                <?php endif; ?>
            </div>
        </nav>
    </div>
</header>

<main>
    <div class="container">

        <!-- NOTIFIKASI FLASH ERROR JIKA ADA -->
        <?php if ($flashError !== ''): ?>
            <div class="alert-notice error" style="margin-top:20px;">
                <div><strong>Pemberitahuan:</strong> <?= e($flashError) ?></div>
                <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;font-size:18px;line-height:1;">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ===============================================================
             BAGIAN A: TAMPILAN KHUSUS SISWA / PEMINJAM (SETELAH LOGIN)
             =============================================================== -->
        <?php if ($isLoggedIn): ?>

            <!-- 1. BANNER SAMBUTAN SISWA -->
            <section class="student-portal-banner" id="dashboard-saya">
                <div class="student-portal-banner-text">
                    <span class="badge-portal">Portal Siswa Peminjam</span>
                    <h2>Halo, <?= e($userName) ?>!</h2>
                    <p>
                        NIS: <strong><?= e($userNis) ?></strong> &bull; Kelas: <strong><?= e($userKelas) ?></strong> &bull; 
                        Smart RFID: <strong><?= !empty($userRfid) ? '● Terdaftar (' . e($userRfid) . ')' : 'Belum Terdaftar' ?></strong>
                    </p>
                </div>
                <div class="student-portal-banner-actions">
                    <a href="#katalog" class="btn btn-primary">
                        Pinjam Alat Praktikum
                    </a>
                    <a href="profil.php" class="btn">
                        Pengaturan Profil
                    </a>
                </div>
            </section>

            <!-- 2. DASHBOARD GRID (KARTU DIGITAL SISWA + STATUS TRANSAKSI) -->
            <div class="dashboard-grid">
                
                <!-- KOLOM KIRI: KARTU DIGITAL PELAJAR LAB KOMPUTER RPL -->
                <div class="student-digital-card">
                    <div class="student-digital-card-stripe"></div>
                    <div class="student-digital-card-body">
                        <div class="student-card-kop">
                            <div>
                                <div class="student-card-instansi"><?= e($namaInstansi) ?></div>
                                <div class="student-card-type">KARTU IDENTITAS PELAJAR</div>
                            </div>
                            <span class="stock-badge available" style="font-size:10px;">● AKTIF</span>
                        </div>

                        <!-- Pas Foto Siswa -->
                        <div class="student-card-photo-box">
                            <?php if (!empty($userFoto) && file_exists(__DIR__ . '/' . $userFoto)): ?>
                                <img src="<?= e($userFoto) ?>" alt="Foto <?= e($userName) ?>" 
                                     class="student-card-photo-img sipala-zoomable-img"
                                     data-lightbox-src="<?= e($userFoto) ?>"
                                     data-lightbox-title="<?= e($userName) ?>"
                                     data-lightbox-sub="NIS: <?= e($userNis) ?> &bull; <?= e($userKelas) ?>"
                                     data-lightbox-badge="Foto Kartu Pelajar"
                                     title="Klik untuk memperbesar foto">
                            <?php else: ?>
                                <a href="profil.php" class="student-card-photo-empty" title="Klik untuk mengunggah pas foto Anda di halaman profil">
                                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="12" cy="7" r="4"></circle>
                                    </svg>
                                    <span>+ Pas Foto</span>
                                </a>
                            <?php endif; ?>
                        </div>

                        <!-- Nama & Kelas -->
                        <div class="student-card-fullname"><?= e($userName) ?></div>
                        <div class="student-card-subtext"><?= e($userKelas) ?> &bull; Siswa Peminjam</div>

                        <!-- Data Siswa -->
                        <div class="student-card-datalist">
                            <div class="student-card-datarow">
                                <span class="label">NIS</span>
                                <span class="value" style="font-family:var(--font-mono);color:var(--color-primary);"><?= e($userNis) ?></span>
                            </div>
                            <div class="student-card-datarow">
                                <span class="label">Kelas / Jurusan</span>
                                <span class="value"><?= e($userKelas) ?></span>
                            </div>
                            <div class="student-card-datarow">
                                <span class="label">Smart RFID</span>
                                <span class="value">
                                    <?php if (!empty($userRfid)): ?>
                                        <span style="color:var(--color-success);font-weight:700;">● Terdaftar</span>
                                    <?php else: ?>
                                        <span style="color:var(--color-warning);font-weight:600;">Belum Terdaftar</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if (!empty($userNoHp)): ?>
                                <div class="student-card-datarow">
                                    <span class="label">WhatsApp</span>
                                    <span class="value"><?= e($userNoHp) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($userAlamat)): ?>
                                <div class="student-card-datarow">
                                    <span class="label">Alamat</span>
                                    <span class="value"><?= e($userAlamat) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Segel & Edit -->
                        <div class="student-card-bottom-auth">
                            <div class="student-card-seal">
                                <div class="student-card-seal-icon">&#10003;</div>
                                <div class="student-card-seal-text">
                                    <strong>SIPALA Terverifikasi</strong>
                                    Lab Komputer RPL
                                </div>
                            </div>
                            <a href="profil.php" class="btn btn-sm" style="font-size:11px;">
                                Edit Profil &rarr;
                            </a>
                        </div>
                    </div>
                </div>

                <!-- KOLOM KANAN: STATUS TRANSAKSI PEMINJAMAN SISWA -->
                <div class="student-transactions-panel">
                    <div class="student-panel-header">
                        <h3>Transaksi Peminjaman Saya</h3>
                        <span style="font-size:12px;color:var(--color-text-muted);">
                            <?= count($myPendingLoans) + count($myApprovedLoans) + count($myActiveLoans) ?> Transaksi Berjalan
                        </span>
                    </div>

                    <div class="student-panel-body">
                        <!-- 1. SIAP DIAMBIL (DISETUJUI OLEH LABORAN) -->
                        <?php if (!empty($myApprovedLoans)): ?>
                            <?php foreach ($myApprovedLoans as $appr): ?>
                                <div class="loan-item-card approved">
                                    <div>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <strong style="font-family:var(--font-mono);color:#166534;font-size:14px;"><?= e($appr['kode_peminjaman']) ?></strong>
                                            <span class="status-badge DISETUJUI">Siap Diambil di Lab</span>
                                        </div>
                                        <div style="font-size:13px;color:#15803d;margin-top:4px;font-weight:600;">
                                            Alat: <?= e($appr['daftar_alat'] ?: ($appr['total_item'] . ' item')) ?>
                                        </div>
                                        <div style="font-size:11.5px;color:var(--color-text-secondary);margin-top:2px;">
                                            Keperluan: <?= e($appr['keperluan']) ?> &bull; Rencana Kembali: <strong><?= e(date('d-m-Y', strtotime($appr['tanggal_rencana_kembali']))) ?></strong>
                                        </div>
                                        <div style="font-size:11.5px;color:#166534;margin-top:4px;">
                                            * Silakan datang ke <strong><?= e($lokasiLab) ?></strong> (Jam: <?= e($jamLab) ?>) dan tunjukkan kode transaksi ini ke petugas lab.
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- 2. SEDANG MEMINJAM (DIPINJAM) -->
                        <?php if (!empty($myActiveLoans)): ?>
                            <?php foreach ($myActiveLoans as $act): ?>
                                <?php
                                $isLate = false;
                                $lateDays = 0;
                                $estFine = 0;
                                if (strtotime(date('Y-m-d')) > strtotime($act['tanggal_rencana_kembali'])) {
                                    $isLate = true;
                                    $lateDays = max(1, (int)((strtotime(date('Y-m-d')) - strtotime($act['tanggal_rencana_kembali'])) / 86400));
                                    $estFine = $lateDays * $tarifDendaHarian;
                                }
                                ?>
                                <div class="loan-item-card <?= $isLate ? 'overdue' : 'active-loan' ?>">
                                    <div style="flex:1;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <strong style="font-family:var(--font-mono);color:#1e40af;font-size:14px;"><?= e($act['kode_peminjaman']) ?></strong>
                                            <span class="status-badge DIPINJAM">Sedang Dipinjam</span>
                                            <?php if ($isLate): ?>
                                                <span class="status-badge DITOLAK">Terlambat <?= $lateDays ?> Hari</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size:13px;color:#1e3a8a;margin-top:4px;font-weight:600;">
                                            Alat: <?= e($act['daftar_alat'] ?: ($act['total_item'] . ' item')) ?>
                                        </div>
                                        <div style="font-size:12px;color:<?= $isLate ? 'var(--color-danger)' : 'var(--color-text-secondary)' ?>;margin-top:2px;">
                                            Batas Waktu Pengembalian: <strong><?= e(date('d-m-Y', strtotime($act['tanggal_rencana_kembali']))) ?></strong>
                                            <?php if ($isLate): ?>
                                                &bull; Estimasi Denda: <strong>Rp <?= number_format($estFine, 0, ',', '.') ?></strong>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($isLate): ?>
                                            <div style="font-size:11.5px;color:var(--color-danger);margin-top:6px;background:#fee2e2;padding:6px 10px;border-radius:var(--radius-sm);border:1px solid var(--color-danger-border);">
                                                Peringatan: Peminjaman telah melewati tenggat! Segera kembalikan ke ruang laboratorium agar denda tidak terus bertambah.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- 3. MENUNGGU VERIFIKASI (MENUNGGU) -->
                        <?php if (!empty($myPendingLoans)): ?>
                            <?php foreach ($myPendingLoans as $wait): ?>
                                <div class="loan-item-card pending">
                                    <div style="flex:1;">
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <strong style="font-family:var(--font-mono);color:#92400e;font-size:14px;"><?= e($wait['kode_peminjaman']) ?></strong>
                                            <span class="status-badge MENUNGGU">Menunggu Verifikasi</span>
                                        </div>
                                        <div style="font-size:13px;color:#92400e;margin-top:4px;font-weight:600;">
                                            Alat: <?= e($wait['daftar_alat'] ?: ($wait['total_item'] . ' item')) ?>
                                        </div>
                                        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px;">
                                            Diajukan pada: <?= e(date('d-m-Y', strtotime($wait['tanggal_pengajuan']))) ?> &bull; Rencana Kembali: <?= e(date('d-m-Y', strtotime($wait['tanggal_rencana_kembali']))) ?>
                                        </div>
                                    </div>
                                    <div>
                                        <form method="POST" action="index.php" onsubmit="return confirm('Apakah Anda yakin ingin membatalkan pengajuan <?= e($wait['kode_peminjaman']) ?>?');" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="cancel_loan">
                                            <input type="hidden" name="loan_id" value="<?= $wait['id'] ?>">
                                            <button type="submit" class="btn btn-sm" style="color:var(--color-danger);border-color:var(--color-danger-border);background:#ffffff;">
                                                Batalkan
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- 4. RIWAYAT TERAKHIR SELESAI -->
                        <?php if (!empty($myHistory)): ?>
                            <div style="margin-top:16px;padding-top:14px;border-top:1px dashed var(--color-border);">
                                <span style="font-size:11.5px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.5px;display:block;margin-bottom:8px;">
                                    Riwayat Pengembalian Terakhir (Selesai):
                                </span>
                                <?php foreach ($myHistory as $hist): ?>
                                    <div style="font-size:12px;color:var(--color-text-secondary);padding:6px 0;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
                                        <div>
                                            <strong style="font-family:var(--font-mono);color:var(--color-text-main);"><?= e($hist['kode_peminjaman']) ?></strong>
                                            &bull; <?= e($hist['daftar_alat'] ?: ($hist['total_item'] . ' item')) ?>
                                        </div>
                                        <span style="color:var(--color-success);font-weight:600;font-size:11px;">
                                            Selesai (<?= e(date('d-m-Y', strtotime($hist['tanggal_realisasi_kembali'] ?: $hist['tanggal_pinjam']))) ?>)
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($myApprovedLoans) && empty($myActiveLoans) && empty($myPendingLoans) && empty($myHistory)): ?>
                            <div class="empty-state" style="padding:28px 10px;">
                                <h3>Tidak ada transaksi berjalan</h3>
                                <p>Silakan pilih alat praktikum pada katalog di bawah untuk mengajukan permohonan baru.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 3. PANEL DENDA & GANTI RUGI (HANYA JIKA ADA DENDA) -->
            <?php if (!empty($myFines)): ?>
                <?php 
                    $unpaidFines = array_filter($myFines, fn($f) => ($f['status_denda'] ?? 'LUNAS') === 'BELUM_LUNAS');
                    $totalTunggakan = array_sum(array_map(fn($f) => (float)$f['denda'], $unpaidFines));
                    $hasUnpaid = count($unpaidFines) > 0;
                ?>
                <div class="alert-notice <?= $hasUnpaid ? 'error' : 'success' ?>" style="display:block;margin-bottom:28px;padding:18px 20px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid <?= $hasUnpaid ? 'var(--color-danger-border)' : '#bbf7d0' ?>;">
                        <div>
                            <strong style="font-size:15px;color:<?= $hasUnpaid ? 'var(--color-danger)' : '#15803d' ?>;">
                                <?= $hasUnpaid ? 'Kewajiban Pembayaran Denda Keterlambatan' : 'Riwayat Denda Keterlambatan (Lunas)' ?>
                            </strong>
                            <p style="font-size:12.5px;color:<?= $hasUnpaid ? 'var(--color-danger)' : '#166534' ?>;margin-top:2px;">
                                <?= $hasUnpaid ? 'Terdapat tagihan denda yang belum dilunasi pada akun peminjam Anda.' : 'Seluruh denda keterlambatan pada akun Anda telah selesai dilunasi.' ?>
                            </p>
                        </div>
                        <div style="text-align:right;">
                            <span style="font-size:11px;color:var(--color-text-muted);display:block;"><?= $hasUnpaid ? 'Tunggakan Belum Lunas:' : 'Status Denda:' ?></span>
                            <strong style="font-size:17px;color:<?= $hasUnpaid ? 'var(--color-danger)' : '#15803d' ?>;">
                                <?= $hasUnpaid ? 'Rp ' . number_format($totalTunggakan, 0, ',', '.') : 'LUNAS' ?>
                            </strong>
                        </div>
                    </div>
                    
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:10px;">
                        <?php foreach ($myFines as $fine): ?>
                            <?php $isLunas = (($fine['status_denda'] ?? 'LUNAS') === 'LUNAS'); ?>
                            <div style="background:#ffffff;border:1px solid <?= $isLunas ? '#bbf7d0' : 'var(--color-danger-border)' ?>;border-radius:var(--radius-sm);padding:10px 14px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <strong style="font-family:var(--font-mono);font-size:12px;color:var(--color-primary);"><?= e($fine['kode_peminjaman']) ?></strong>
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <span style="color:<?= $isLunas ? '#15803d' : 'var(--color-danger)' ?>;font-weight:700;font-size:12px;">
                                            Rp <?= number_format((float)$fine['denda'], 0, ',', '.') ?>
                                        </span>
                                        <span class="status-badge <?= $isLunas ? 'SELESAI' : 'DITOLAK' ?>" style="font-size:9.5px;padding:2px 6px;">
                                            <?= $isLunas ? 'LUNAS' : 'BELUM LUNAS' ?>
                                        </span>
                                    </div>
                                </div>
                                <div style="font-size:12px;color:var(--color-text-main);margin-top:4px;">
                                    Alat: <strong><?= e($fine['daftar_alat']) ?></strong>
                                </div>
                                <div style="font-size:11px;color:var(--color-text-muted);margin-top:2px;">
                                    Kembali: <?= e(date('d-m-Y', strtotime($fine['tanggal_pengembalian']))) ?> &bull; Kondisi: <strong><?= e($fine['kondisi_barang']) ?></strong>
                                </div>
                                <?php if (!empty($fine['catatan'])): ?>
                                    <div style="font-size:11px;color:#64748b;margin-top:4px;font-style:italic;">
                                        Catatan: <?= e($fine['catatan']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($hasUnpaid): ?>
                        <div style="margin-top:12px;font-size:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                            <span>* Harap lakukan pelunasan denda secara langsung ke petugas Lab Komputer RPL.</span>
                            <?php if (!empty($kontakLabWa)): ?>
                                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $kontakLabWa) ?>" target="_blank" style="color:var(--color-success);font-weight:700;">
                                    Konfirmasi WhatsApp Petugas &rarr;
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <!-- ===============================================================
             BAGIAN B: TAMPILAN KHUSUS PENGUNJUNG PUBLIK (SEBELUM LOGIN)
             =============================================================== -->
        <?php else: ?>

            <!-- 1. HERO BANNER PUBLIK -->
            <section class="hero-section">
                <div class="hero-box">
                    <div class="hero-content">
                        <div class="hero-tag">Lab Komputer RPL</div>
                        <h2 class="hero-title">Sistem Peminjaman Alat<br>Lab Komputer RPL</h2>
                        <p class="hero-desc">
                            Temukan perangkat yang tersedia, lihat detail alat, dan ajukan peminjaman melalui SIPALA.
                        </p>
                        <div class="hero-actions">
                            <a href="login.php" class="btn btn-primary btn-lg">Masuk / Tap Kartu RFID</a>
                            <a href="#katalog" class="btn btn-lg">Lihat Katalog</a>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 2. STATISTIK PUBLIK DATABASE -->
            <section class="stats-section">
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                                <line x1="8" y1="21" x2="16" y2="21"></line>
                                <line x1="12" y1="17" x2="12" y2="21"></line>
                            </svg>
                        </div>
                        <div class="stat-data">
                            <span class="stat-value"><?= $totalJenisAlat ?></span>
                            <span class="stat-label">Jenis Alat Terdaftar</span>
                        </div>
                    </div>

                    <div class="stat-item">
                        <div class="stat-icon" style="color:var(--color-success);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        </div>
                        <div class="stat-data">
                            <span class="stat-value"><?= $totalUnitTersedia ?></span>
                            <span class="stat-label">Unit Tersedia</span>
                        </div>
                    </div>

                    <div class="stat-item">
                        <div class="stat-icon" style="color:var(--color-primary);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="8.5" cy="7" r="4"></circle>
                                <polyline points="17 11 19 13 23 9"></polyline>
                            </svg>
                        </div>
                        <div class="stat-data">
                            <span class="stat-value"><?= $totalUnitDipinjam ?></span>
                            <span class="stat-label">Sedang Dipinjam</span>
                        </div>
                    </div>
                </div>
            </section>

        <?php endif; ?>

        <!-- ===============================================================
             BAGIAN C: KATALOG PERALATAN (TAMPIL DI KEDUA MODE, ACTION DISESUAIKAN)
             =============================================================== -->
        <section class="catalog-section" id="katalog">
            <div class="catalog-panel">
                <div class="catalog-header">
                    <div class="catalog-header-text">
                        <h2><?= $isLoggedIn ? 'Katalog Peralatan Praktikum Lab RPL' : 'Peralatan Lab Komputer RPL' ?></h2>
                        <p><?= $isLoggedIn ? 'Pilih perangkat praktikum yang dibutuhkan dan kirim permohonan peminjaman.' : 'Lihat perangkat yang tersedia sebelum mengajukan peminjaman.' ?></p>
                    </div>

                    <!-- Form Pencarian -->
                    <form method="get" action="index.php#katalog" class="catalog-search-form">
                        <?php if ($selectedKat > 0): ?>
                            <input type="hidden" name="kat" value="<?= $selectedKat ?>">
                        <?php endif; ?>
                        <div class="search-input-wrap">
                            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            <input type="text" name="q" id="catalogSearchInput" value="<?= e($searchCatalog) ?>" 
                                   placeholder="Cari nama atau kode alat..." class="search-input" autocomplete="off">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Cari</button>
                        <?php if ($searchCatalog !== '' || $selectedKat > 0): ?>
                            <a href="index.php#katalog" class="btn btn-sm">Reset</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- FILTER KATEGORI -->
                <div class="category-filter-bar">
                    <a href="index.php?q=<?= urlencode($searchCatalog) ?>#katalog" 
                       class="category-pill <?= $selectedKat === 0 ? 'active' : '' ?>">
                        Semua Kategori
                    </a>
                    <?php foreach ($categories as $cat): ?>
                        <a href="index.php?kat=<?= $cat['id'] ?>&q=<?= urlencode($searchCatalog) ?>#katalog" 
                           class="category-pill <?= $selectedKat === (int)$cat['id'] ? 'active' : '' ?>">
                            <?= e($cat['nama_kategori']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- GRID KARTU ALAT -->
                <?php if (empty($catalog)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                        </div>
                        <?php if ($searchCatalog !== '' || $selectedKat > 0): ?>
                            <h3>Alat yang dicari tidak ditemukan</h3>
                            <p>Tidak ada perangkat yang sesuai dengan kata kunci atau kategori yang dipilih.</p>
                            <a href="index.php#katalog" class="btn btn-sm">Hapus Pencarian</a>
                        <?php else: ?>
                            <h3>Peralatan belum tersedia</h3>
                            <p>Belum ada data alat aktif yang dapat ditampilkan pada sistem.</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="equipment-grid" id="equipmentGrid">
                        <?php foreach ($catalog as $item): ?>
                            <?php
                            $stokTersedia = (int)$item['jumlah_tersedia'];
                            $itemJson = json_encode([
                                'id' => (int)$item['id'],
                                'kode_barang' => $item['kode_barang'],
                                'nama_barang' => $item['nama_barang'],
                                'nama_kategori' => $item['nama_kategori'] ?? 'Perangkat RPL',
                                'jumlah' => (int)$item['jumlah'],
                                'jumlah_tersedia' => $stokTersedia,
                                'kondisi' => $item['kondisi'],
                                'lokasi' => $item['lokasi'] ?: 'Lab Komputer RPL',
                                'deskripsi' => $item['deskripsi'],
                                'foto' => $item['foto']
                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                            ?>
                            <div class="equipment-card" 
                                 data-name="<?= e(strtolower($item['nama_barang'])) ?>" 
                                 data-code="<?= e(strtolower($item['kode_barang'])) ?>" 
                                 data-category="<?= e(strtolower($item['nama_kategori'] ?? '')) ?>">
                                
                                <div class="card-media">
                                    <?php if (!empty($item['foto']) && file_exists(__DIR__ . '/' . $item['foto'])): ?>
                                        <img src="<?= e($item['foto']) ?>" alt="<?= e($item['nama_barang']) ?>" 
                                             class="card-img sipala-zoomable-img" loading="lazy"
                                             data-lightbox-src="<?= e($item['foto']) ?>"
                                             data-lightbox-title="<?= e($item['nama_barang']) ?>"
                                             data-lightbox-sub="Kode: <?= e($item['kode_barang']) ?> &bull; <?= e($item['nama_kategori'] ?? 'Lab Komputer RPL') ?>"
                                             data-lightbox-badge="Kondisi: <?= e($item['kondisi']) ?>"
                                             data-lightbox-meta="Lokasi: <?= e($item['lokasi'] ?: 'Lab Komputer RPL') ?> &bull; Stok: <?= $item['jumlah_tersedia'] ?> / <?= $item['jumlah'] ?> unit"
                                             title="Klik untuk memperbesar gambar">
                                    <?php else: ?>
                                        <div class="card-img-placeholder">
                                            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                                                <line x1="8" y1="21" x2="16" y2="21"></line>
                                                <line x1="12" y1="17" x2="12" y2="21"></line>
                                            </svg>
                                            <span>Foto belum tersedia</span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- STATUS STOK (BAHASA INDONESIA EKSPLISIT) -->
                                    <div class="card-badge-stock">
                                        <?php if ($stokTersedia > 2): ?>
                                            <span class="stock-badge available">✓ <?= $stokTersedia ?> unit tersedia</span>
                                        <?php elseif ($stokTersedia > 0): ?>
                                            <span class="stock-badge limited">! Terbatas (<?= $stokTersedia ?> unit)</span>
                                        <?php else: ?>
                                            <span class="stock-badge empty">Tidak tersedia</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card-body">
                                    <div class="card-category-code">
                                        <?= e($item['kode_barang']) ?> &bull; <?= e($item['nama_kategori'] ?? 'Perangkat RPL') ?>
                                    </div>
                                    <h3 class="card-title"><?= e($item['nama_barang']) ?></h3>

                                    <div class="card-meta-list">
                                        <div class="card-meta-row">
                                            <span class="label">Lokasi:</span>
                                            <strong><?= e($item['lokasi'] ?: 'Lab Komputer RPL') ?></strong>
                                        </div>
                                        <div class="card-meta-row">
                                            <span class="label">Kondisi:</span>
                                            <span><?= e($item['kondisi']) ?></span>
                                        </div>
                                    </div>

                                    <div class="card-footer">
                                        <div class="card-stock-info">
                                            Total: <strong><?= $item['jumlah'] ?> unit</strong>
                                        </div>
                                        <?php if ($isLoggedIn): ?>
                                            <!-- Tombol untuk Siswa yang Sudah Login -->
                                            <div class="card-actions-student">
                                                <button type="button" class="btn btn-sm" 
                                                        onclick='openDetailModal(<?= $itemJson ?>)' title="Lihat Rincian Spesifikasi">
                                                    Detail
                                                </button>
                                                <?php if ($stokTersedia > 0): ?>
                                                    <button type="button" class="btn btn-sm btn-primary" 
                                                            onclick="openLoanModal(<?= $item['id'] ?>, '<?= e(addslashes($item['nama_barang'])) ?>', '<?= e($item['kode_barang']) ?>', <?= $stokTersedia ?>, '<?= e($item['foto'] ?? '') ?>')">
                                                        Ajukan Pinjam
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-disabled" disabled>
                                                        Habis
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <!-- Tombol untuk Pengunjung Publik Belum Login -->
                                            <button type="button" class="btn btn-sm" 
                                                    onclick='openDetailModal(<?= $itemJson ?>)'>
                                                Lihat Detail
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="catalogEmptyState" class="empty-state" style="display:none;">
                        <div class="empty-state-icon">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                        </div>
                        <h3>Alat yang dicari tidak ditemukan</h3>
                        <p>Tidak ada perangkat yang cocok dengan pencarian instan Anda.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ===============================================================
             BAGIAN D: FITUR KHUSUS PUBLIK (HANYA MUNCUL SEBELUM LOGIN)
             =============================================================== -->
        <?php if (!$isLoggedIn): ?>

            <!-- CARA PEMINJAMAN (4 LANGKAH SEDERHANA) -->
            <section class="steps-section" id="cara-pinjam">
                <div class="steps-panel">
                    <div class="section-intro">
                        <h2>Cara Meminjam Alat</h2>
                        <p>Alur praktis peminjaman perangkat di Lab Komputer RPL.</p>
                    </div>

                    <div class="steps-grid">
                        <div class="step-card">
                            <div class="step-num">01</div>
                            <h3 class="step-title">Cari alat</h3>
                            <p class="step-desc">Temukan perangkat yang dibutuhkan melalui katalog.</p>
                        </div>

                        <div class="step-card">
                            <div class="step-num">02</div>
                            <h3 class="step-title">Ajukan</h3>
                            <p class="step-desc">Masuk dan kirim pengajuan peminjaman.</p>
                        </div>

                        <div class="step-card">
                            <div class="step-num">03</div>
                            <h3 class="step-title">Verifikasi</h3>
                            <p class="step-desc">Pengajuan diperiksa oleh petugas Lab Komputer RPL.</p>
                        </div>

                        <div class="step-card">
                            <div class="step-num">04</div>
                            <h3 class="step-title">Ambil alat</h3>
                            <p class="step-desc">Datang ke lab setelah peminjaman disetujui.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- LACAK STATUS PENGAJUAN MANDIRI VIA KODE (GUEST TRACKING) -->
            <section class="track-section" id="lacak">
                <div class="track-panel">
                    <div class="section-intro">
                        <h2>Cek Status Peminjaman</h2>
                        <p>Masukkan kode peminjaman untuk melihat status pengajuan mandiri.</p>
                    </div>

                    <form method="get" action="index.php#lacak" class="track-form">
                        <div class="track-input-group">
                            <label for="trackCodeInput">Kode Peminjaman</label>
                            <input type="text" id="trackCodeInput" name="track_kode" value="<?= e($trackCode) ?>" 
                                   placeholder="Contoh: PJM-20260918-XXXXX" class="track-input" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="height:39px;">Lacak</button>
                        <?php if ($trackCode !== ''): ?>
                            <a href="index.php#lacak" class="btn" style="height:39px;">Reset</a>
                        <?php endif; ?>
                    </form>

                    <?php if ($trackCode !== ''): ?>
                        <div class="track-result-box">
                            <?php if ($trackedLoan): ?>
                                <div class="track-card">
                                    <div class="track-card-header">
                                        <div>
                                            <div class="track-code"><?= e($trackedLoan['kode_peminjaman']) ?></div>
                                            <div class="track-borrower">
                                                Peminjam: <strong><?= e($trackedLoan['nama_peminjam'] ?? '-') ?></strong> (NIS: <?= e($trackedLoan['nis'] ?? '-') ?>, <?= e($trackedLoan['kelas'] ?? '-') ?>)
                                            </div>
                                        </div>
                                        <span class="status-badge <?= e($trackedLoan['status']) ?>">
                                            <?= e($trackedLoan['status']) ?>
                                        </span>
                                    </div>

                                    <div class="track-card-body">
                                        <div>Perangkat: <strong><?= e($trackedLoan['daftar_alat'] ?: ($trackedLoan['total_item'] . ' unit')) ?></strong></div>
                                        <div>Tanggal Pengajuan: <strong><?= e(date('d-m-Y', strtotime($trackedLoan['tanggal_pengajuan']))) ?></strong></div>
                                        <div>Rencana Pengembalian: <strong><?= e(date('d-m-Y', strtotime($trackedLoan['tanggal_rencana_kembali']))) ?></strong></div>
                                        <div>Keperluan: <?= e($trackedLoan['keperluan']) ?></div>

                                        <?php if ($trackedLoan['status'] === 'DISETUJUI'): ?>
                                            <div class="track-instruction success">
                                                ✓ <strong>Pengajuan Disetujui:</strong> Silakan temui petugas di <?= e($lokasiLab) ?> untuk serah terima fisik peralatan dengan menunjukkan kode di atas.
                                            </div>
                                        <?php elseif ($trackedLoan['status'] === 'DIPINJAM'): ?>
                                            <div class="track-instruction warning">
                                                ● <strong>Sedang Dipinjam:</strong> Harap gunakan alat dengan baik dan kembalikan paling lambat pada <?= e(date('d-m-Y', strtotime($trackedLoan['tanggal_rencana_kembali']))) ?>.
                                            </div>
                                        <?php elseif ($trackedLoan['status'] === 'DITOLAK'): ?>
                                            <div class="track-instruction danger">
                                                ✕ <strong>Pengajuan Ditolak:</strong> <?= e($trackedLoan['alasan_penolakan'] ?: 'Pengajuan belum dapat disetujui petugas lab.') ?>
                                            </div>
                                        <?php elseif ($trackedLoan['status'] === 'MENUNGGU'): ?>
                                            <div class="track-instruction warning">
                                                ⏳ <strong>Menunggu Verifikasi:</strong> Pengajuan Anda sedang menunggu tinjauan dari petugas Lab Komputer RPL.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert-notice error" style="margin-bottom:0;">
                                    <div>Data transaksi dengan kode <strong><?= e($trackCode) ?></strong> tidak ditemukan. Pastikan format kode tepat.</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

        <?php endif; ?>

        <!-- KETENTUAN OPERASIONAL PEMINJAMAN -->
        <section class="info-section" id="informasi">
            <div class="info-panel">
                <div class="section-intro" style="margin-bottom:12px;">
                    <h2>Ketentuan Peminjaman Lab Komputer RPL</h2>
                    <p>Pedoman penggunaan perangkat laboratorium untuk kenyamanan dan kelancaran kegiatan belajar.</p>
                </div>

                <ul class="info-list">
                    <li class="info-item">
                        <span class="info-bullet"></span>
                        <div>Pastikan alat yang dipilih tersedia sebelum mengajukan permohonan.</div>
                    </li>
                    <li class="info-item">
                        <span class="info-bullet"></span>
                        <div>Batas maksimal durasi peminjaman adalah <strong><?= $maksHariPinjam ?> hari kerja</strong> dan maksimal <strong><?= $maksUnitPinjam ?> unit</strong> per transaksi.</div>
                    </li>
                    <li class="info-item">
                        <span class="info-bullet"></span>
                        <div>Periksa kelengkapan dan kondisi fisik alat saat serah terima di <strong><?= e($lokasiLab) ?></strong>.</div>
                    </li>
                    <li class="info-item">
                        <span class="info-bullet"></span>
                        <div>Gunakan perangkat hanya untuk kegiatan pembelajaran dan praktikum Rekayasa Perangkat Lunak.</div>
                    </li>
                    <li class="info-item">
                        <span class="info-bullet"></span>
                        <div>Kembalikan alat sesuai waktu yang ditentukan (Jam operasional: <strong><?= e($jamLab) ?></strong>).</div>
                    </li>
                    <li class="info-item">
                        <span class="info-bullet"></span>
                        <div>Laporkan segera kepada petugas laboratorium apabila terdapat kendala teknis atau kerusakan.</div>
                    </li>
                </ul>
            </div>
        </section>

    </div>
</main>

<!-- FOOTER -->
<footer class="site-footer">
    <div class="container">
        <div class="footer-content">
            <div class="footer-brand">
                <strong>SIPALA &bull; Sistem Informasi Peminjaman Alat Lab Komputer RPL</strong>
                <span>Lab Komputer RPL &bull; Rekayasa Perangkat Lunak &bull; <?= e($namaInstansi) ?></span>
            </div>

            <div class="footer-meta">
                <div>Jam Layanan: <?= e($jamLab) ?> &bull; Lokasi: <?= e($lokasiLab) ?></div>
                <?php if (!empty($kontakLabWa)): ?>
                    <div>Kontak Lab (WhatsApp): <strong><?= e($kontakLabWa) ?></strong></div>
                <?php endif; ?>
                <div style="margin-top:4px;color:var(--color-text-light);">&copy; <?= date('Y') ?> SIPALA. Hak Cipta Dilindungi.</div>
            </div>
        </div>
    </div>
</footer>

<!-- MODAL DETAIL ALAT -->
<div class="modal-overlay" id="detailModalOverlay">
    <div class="modal-container">
        <div class="modal-header">
            <div>
                <span id="detailAlatKategori" style="font-size:11px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;">Kategori</span>
                <h3 id="detailAlatNama">Nama Alat</h3>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeDetailModal()">&times;</button>
        </div>

        <div class="detail-modal-media" id="detailMediaWrap"></div>

        <table class="detail-table">
            <tr>
                <th>Kode Barang</th>
                <td id="detailAlatKode" style="font-family:var(--font-mono);">-</td>
            </tr>
            <tr>
                <th>Kondisi Fisik</th>
                <td id="detailAlatKondisi">-</td>
            </tr>
            <tr>
                <th>Lokasi Simpan</th>
                <td id="detailAlatLokasi">-</td>
            </tr>
            <tr>
                <th>Status Ketersediaan</th>
                <td>
                    <span id="detailAlatStockBadge" class="stock-badge available">-</span>
                </td>
            </tr>
            <tr>
                <th>Total Inventaris</th>
                <td id="detailAlatTotal">-</td>
            </tr>
        </table>

        <div class="detail-desc-box">
            <strong>Deskripsi & Spesifikasi:</strong>
            <div id="detailAlatDeskripsi">-</div>
        </div>

        <div class="form-actions">
            <button type="button" class="btn" onclick="closeDetailModal()">Tutup</button>
            <?php if ($isLoggedIn): ?>
                <button type="button" id="detailActionBtn" class="btn btn-primary">
                    Ajukan Peminjaman
                </button>
            <?php else: ?>
                <a href="login.php" class="btn btn-primary">
                    Masuk untuk Meminjam
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL FORM PENGAJUAN PEMINJAMAN -->
<div class="modal-overlay" id="loanModalOverlay">
    <div class="modal-container">
        <div class="modal-header">
            <div>
                <h3 style="margin:0;">Pengajuan Peminjaman Alat</h3>
                <span style="font-size:12px;color:var(--color-text-muted);">Lab Komputer RPL</span>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeLoanModal()">&times;</button>
        </div>

        <!-- Ringkasan Alat yang Dipilih -->
        <div style="display:flex;gap:12px;background:var(--color-bg-page);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:12px;margin-bottom:16px;align-items:center;">
            <div style="width:48px;height:48px;border-radius:var(--radius-sm);background:#ffffff;border:1px solid var(--color-border);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
                <img id="modalAlatImg" src="" style="width:100%;height:100%;object-fit:cover;display:none;">
                <div id="modalImgPlaceholder" style="color:var(--color-text-light);display:flex;align-items:center;justify-content:center;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                        <line x1="8" y1="21" x2="16" y2="21"></line>
                        <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                </div>
            </div>
            <div style="flex:1;">
                <div style="font-size:11px;font-family:var(--font-mono);color:var(--color-text-muted);" id="modalAlatKode">-</div>
                <strong style="font-size:14px;color:var(--color-text-main);" id="modalAlatNama">-</strong>
                <div style="font-size:12px;color:var(--color-success);margin-top:2px;">
                    Tersedia: <strong id="modalAlatStok">0</strong> unit
                </div>
            </div>
        </div>

        <form method="post" action="index.php" id="formSubmitLoan">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit_loan">
            <input type="hidden" name="peralatan_id" id="modalPeralatanId" value="">

            <div class="form-row">
                <div class="form-group">
                    <label for="modalJumlah">Jumlah Unit Dipinjam *</label>
                    <input type="number" name="jumlah" id="modalJumlah" min="1" max="<?= $maksUnitPinjam ?>" 
                           data-max-config="<?= $maksUnitPinjam ?>" value="1" required>
                    <small style="color:var(--color-text-muted);font-size:11px;">Maks. <?= $maksUnitPinjam ?> unit / transaksi.</small>
                </div>
                <div class="form-group">
                    <label for="modalTglKembali">Rencana Kembali *</label>
                    <input type="date" name="tanggal_rencana_kembali" id="modalTglKembali" required 
                           min="<?= date('Y-m-d') ?>" 
                           max="<?= date('Y-m-d', strtotime('+' . $maksHariPinjam . ' days')) ?>" 
                           value="<?= date('Y-m-d', strtotime('+' . min(2, $maksHariPinjam) . ' days')) ?>">
                    <small style="color:var(--color-text-muted);font-size:11px;">Maksimal <?= $maksHariPinjam ?> hari.</small>
                </div>
            </div>

            <div class="form-group">
                <label for="modalKeperluan">Keperluan Praktikum *</label>
                <textarea name="keperluan" id="modalKeperluan" rows="2" required placeholder="Contoh: Praktikum perakitan jaringan IoT kelas XII RPL 1"></textarea>
            </div>

            <!-- Identitas Siswa / Tamu -->
            <?php if ($isLoggedIn): ?>
                <div style="background:var(--color-primary-light);border:1px solid var(--color-primary-border);border-radius:var(--radius-md);padding:10px 14px;font-size:12.5px;color:var(--color-primary);margin-bottom:16px;">
                    <div><strong>Mengajukan dengan Akun Siswa Terdaftar:</strong></div>
                    <div>Nama: <?= e($userName) ?> &bull; NIS: <?= e($userNis) ?> &bull; Kelas: <?= e($userKelas) ?></div>
                </div>
            <?php else: ?>
                <div style="background:var(--color-bg-page);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:14px;margin-bottom:16px;">
                    <div style="font-weight:700;font-size:12.5px;color:var(--color-text-main);margin-bottom:4px;">
                        Identitas Peminjam
                    </div>
                    <p style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:10px;">
                        Isi identitas siswa Anda untuk memudahkan verifikasi oleh petugas lab.
                    </p>

                    <div class="form-group">
                        <label>Nama Lengkap Siswa *</label>
                        <input type="text" name="guest_nama" required placeholder="Contoh: Muhammad Rizky">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>NIS *</label>
                            <input type="text" name="guest_nis" required placeholder="Contoh: 20261001">
                        </div>
                        <div class="form-group">
                            <label>Kelas / Jurusan *</label>
                            <input type="text" name="guest_kelas" required placeholder="Contoh: XII RPL 1">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label>No. WhatsApp (Opsional)</label>
                        <input type="text" name="guest_hp" placeholder="Contoh: 081234567890">
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-actions">
                <button type="button" class="btn" onclick="closeLoanModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Kirim Pengajuan Peminjaman</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL SUKSES PENGAJUAN -->
<?php if ($loanSubmitted !== null): ?>
    <div class="modal-overlay active" id="successModalOverlay">
        <div class="modal-container" style="text-align:center;">
            <div style="width:48px;height:48px;border-radius:50%;background:var(--color-success-bg);color:var(--color-success);border:1px solid var(--color-success-border);display:inline-flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:12px;">
                &#10003;
            </div>
            <h3 style="margin-bottom:6px;">Pengajuan Berhasil Dikirim</h3>
            <p style="font-size:13px;color:var(--color-text-muted);">
                Pengajuan peminjaman telah masuk ke sistem dan menunggu verifikasi petugas Lab RPL.
            </p>

            <div class="code-display-box">
                <div style="font-size:11.5px;color:var(--color-primary);margin-bottom:4px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Kode Peminjaman Anda</div>
                <div class="code-display-text" id="loanCodeText"><?= e($loanSubmitted['kode']) ?></div>
                <button type="button" class="btn btn-sm" onclick="copyLoanCode()" style="margin-top:8px;">
                    Salin Kode
                </button>
            </div>

            <div style="background:var(--color-bg-page);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:12px 14px;font-size:12.5px;text-align:left;margin-bottom:16px;">
                <div>Peminjam: <strong><?= e($loanSubmitted['nama']) ?></strong></div>
                <div style="margin-top:3px;">Alat: <strong><?= e($loanSubmitted['alat']) ?></strong> (<?= (int)$loanSubmitted['jumlah'] ?> unit)</div>
                <div style="margin-top:3px;">Rencana Kembali: <strong><?= e(date('d-m-Y', strtotime($loanSubmitted['tgl_kembali']))) ?></strong></div>
            </div>

            <div style="font-size:12px;color:var(--color-text-secondary);background:#fffbeb;border:1px solid #fde68a;padding:10px 12px;border-radius:var(--radius-sm);text-align:left;margin-bottom:16px;line-height:1.45;">
                <strong>Petunjuk:</strong> Simpan kode transaksi ini. Datang ke <strong><?= e($lokasiLab) ?></strong> untuk verifikasi dan serah terima alat.
            </div>

            <div style="display:flex;justify-content:flex-end;">
                <button type="button" class="btn btn-primary" onclick="document.getElementById('successModalOverlay').classList.remove('active')">
                    Selesai
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script src="assets/js/sweetalert2.all.min.js"></script>
<script src="assets/js/image-cropper-modal.js"></script>
<script src="assets/js/index.js"></script>
<?php if ($loginSuccess !== ''): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    if (window.Swal) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: <?= json_encode($loginSuccess, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            showConfirmButton: false,
            timer: 3500,
            timerProgressBar: true
        });
    }
});
</script>
<?php endif; ?>
</body>
</html>
