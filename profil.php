<?php

/**
 * FILE       : profil.php
 * MODUL      : Manajemen Profil Pengguna
 * FUNGSI     : Menampilkan dan memperbarui data profil pengguna, foto profil (dengan pemangkas rasio 1:1), kata sandi, serta riwayat laboratorium
 * AKSES      : Seluruh Pengguna Terautentikasi (Admin, Petugas, Siswa/Peminjam)
 *
 * PERBEDAAN TAMPILAN BERDASARKAN PERAN ($isStudent):
 * - Akun Siswa (Peminjam): Memiliki informasi akademik (NIS, Kelas, Alamat), panel catatan laboratorium, dan status denda aktif.
 * - Akun Admin & Petugas: Hanya menampilkan informasi esensial (Nama Lengkap, Username, WhatsApp/HP, Hak Akses), tanpa kolom NIS/Kelas/Alamat.
 *
 * ALUR:
 * 1. Memeriksa keberadaan sesi login aktif.
 * 2. Mengambil data mutakhir pengguna dari tabel `users`.
 * 3. Memproses pembaruan biodata dan foto cropped base64.
 * 4. Memproses perubahan kata sandi dengan validasi password lama (password_verify) dan enkripsi baru (password_hash).
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

if (!function_exists('e')) {
    function e(string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

// Cek apakah user sedang login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Ambil data user terkini dari database
$stmtUser = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch();

if (!$user) {
    header('Location: logout.php');
    exit;
}

$isStudent = ($user['role'] === 'peminjam');

// Ambil riwayat denda siswa jika ada
$myFines = [];
$totalDenda = 0.0;
if ($isStudent) {
    $stmtFines = $pdo->prepare('
        SELECT pg.*, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali
        FROM pengembalian pg
        JOIN peminjaman p ON p.id = pg.peminjaman_id
        WHERE p.user_id = ? AND pg.denda > 0
        ORDER BY pg.id DESC
    ');
    $stmtFines->execute([$userId]);
    $myFines = $stmtFines->fetchAll();
    $totalDenda = array_sum(array_map(fn($f) => (float)$f['denda'], $myFines));
}

$flashMsg = $_SESSION['flash_msg'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// Handle POST update profil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $nama = trim((string)($_POST['nama'] ?? $user['nama']));
    $kelas = $isStudent ? trim((string)($_POST['kelas'] ?? '')) : null;
    $noHp = trim((string)($_POST['no_hp'] ?? ''));
    $alamat = $isStudent ? trim((string)($_POST['alamat'] ?? '')) : null;
    $hapusFoto = !empty($_POST['hapus_foto']);
    $foto = $user['foto'];

    $oldPassword = (string)($_POST['old_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    $errors = [];

    if ($nama === '') {
        $errors[] = 'Nama lengkap tidak boleh kosong.';
    }
    if ($isStudent && $kelas === '') {
        $errors[] = 'Kelas / Jurusan tidak boleh kosong.';
    }

    // 1. Validasi ganti password jika diisi
    $changePassword = false;
    if ($newPassword !== '' || $oldPassword !== '') {
        if (!password_verify($oldPassword, $user['password'])) {
            $errors[] = 'Password lama yang Anda masukkan tidak sesuai.';
        } elseif (strlen($newPassword) < 6) {
            $errors[] = 'Password baru minimal harus 6 karakter.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'Konfirmasi password baru tidak cocok.';
        } else {
            $changePassword = true;
        }
    }

    // 2. Handle upload foto profil jika ada (Mendukung Cropped Base64 atau File Multipart terverifikasi)
    $croppedBase64 = trim((string)($_POST['foto_cropped'] ?? ''));
    if (!empty($croppedBase64) && str_starts_with($croppedBase64, 'data:image/')) {
        $savedPath = secure_save_base64_image($croppedBase64, 'profile', 'profile_' . $userId);
        if ($savedPath) {
            if (!empty($user['foto']) && str_starts_with($user['foto'], 'assets/uploads/profile/')) {
                $oldPath = __DIR__ . '/' . $user['foto'];
                if (file_exists($oldPath)) @unlink($oldPath);
            }
            $foto = $savedPath;
        } else {
            $errors[] = 'Format atau data gambar hasil crop tidak valid (hanya JPG, PNG, atau WEBP).';
        }
    } elseif (isset($_FILES['foto_file']) && $_FILES['foto_file']['error'] === UPLOAD_ERR_OK) {
        try {
            $savedPath = secure_upload_image($_FILES['foto_file'], 'profile', 'profile_' . $userId);
            if (!empty($user['foto']) && str_starts_with($user['foto'], 'assets/uploads/profile/')) {
                $oldPath = __DIR__ . '/' . $user['foto'];
                if (file_exists($oldPath)) @unlink($oldPath);
            }
            $foto = $savedPath;
        } catch (Throwable $uploadEx) {
            $errors[] = $uploadEx->getMessage();
        }
    } elseif ($hapusFoto) {
        if (!empty($user['foto']) && str_starts_with($user['foto'], 'assets/uploads/profile/')) {
            $oldPath = __DIR__ . '/' . $user['foto'];
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }
        $foto = null;
    }

    if (empty($errors)) {
        try {
            if ($changePassword) {
                $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmtUpd = $pdo->prepare('
                    UPDATE users 
                    SET nama = ?, kelas = ?, no_hp = ?, alamat = ?, foto = ?, password = ? 
                    WHERE id = ?
                ');
                $stmtUpd->execute([$nama, $kelas, $noHp !== '' ? $noHp : null, $alamat !== '' ? $alamat : null, $foto, $newHash, $userId]);
            } else {
                $stmtUpd = $pdo->prepare('
                    UPDATE users 
                    SET nama = ?, kelas = ?, no_hp = ?, alamat = ?, foto = ? 
                    WHERE id = ?
                ');
                $stmtUpd->execute([$nama, $kelas, $noHp !== '' ? $noHp : null, $alamat !== '' ? $alamat : null, $foto, $userId]);
            }

            // Perbarui sesi
            $_SESSION['nama'] = $nama;
            $_SESSION['kelas'] = $kelas;
            $_SESSION['foto'] = $foto ?? '';

            $_SESSION['flash_msg'] = 'Profil dan pengaturan akun berhasil disimpan.';
            $_SESSION['flash_type'] = 'success';
            header('Location: profil.php');
            exit;
        } catch (Throwable $e) {
            error_log('Gagal menyimpan profil: ' . $e->getMessage());
            $errors[] = 'Gagal menyimpan profil karena kendala sistem.';
        }
    }

    if (!empty($errors)) {
        $flashMsg = implode('<br>', array_map('e', $errors));
        $flashType = 'error';
    }
}

// Refresh data user setelah update
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch();

// Ambil riwayat denda siswa jika ada
$stmtFines = $pdo->prepare('
    SELECT pg.*, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali
    FROM pengembalian pg
    JOIN peminjaman p ON p.id = pg.peminjaman_id
    WHERE p.user_id = ? AND pg.denda > 0
    ORDER BY pg.id DESC
');
$stmtFines->execute([$userId]);
$myFines = $stmtFines->fetchAll();
$totalDenda = array_sum(array_map(fn($f) => (float)$f['denda'], $myFines));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pengaturan Profil - SIPALA</title>
    <link rel="icon" type="image/jpeg" href="assets/img/logo.jpg?v=2">
    <link rel="shortcut icon" type="image/jpeg" href="assets/img/logo.jpg?v=2">
    <link rel="apple-touch-icon" href="assets/img/logo.jpg?v=2">
    <link rel="stylesheet" href="assets/css/rfid.css">
    <link rel="stylesheet" href="assets/css/image-cropper-modal.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: Arial, Helvetica, sans-serif; 
            background: #f4f6f8; 
            color: #1e293b; 
            line-height: 1.5; 
        }
        
        /* Top Navigation Bar */
        .navbar {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0 24px;
            height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        .nav-logo-badge {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #1769AA;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 16px;
        }
        .nav-title strong {
            display: block;
            font-size: 17px;
            color: #0f172a;
            letter-spacing: 0.3px;
        }
        .nav-title span {
            display: block;
            font-size: 11px;
            color: #64748b;
        }
        
        .container {
            max-width: 860px;
            margin: 0 auto;
            padding: 24px 20px 60px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }
        .btn-primary {
            background: #1769AA;
            color: #ffffff;
            border-color: #1769AA;
        }
        .btn-primary:hover {
            background: #125488;
            border-color: #125488;
            color: #ffffff;
        }
        .btn-sm {
            padding: 5px 12px;
            font-size: 12px;
            border-radius: 5px;
        }

        .panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            margin-bottom: 22px;
        }
        .panel-header {
            padding: 16px 22px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
        }
        .panel-header h2 {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .panel-body {
            padding: 22px;
        }

        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            font-family: inherit;
            color: #1e293b;
            outline: none;
        }
        .form-group input:focus, .form-group textarea:focus {
            border-color: #1769AA;
            box-shadow: 0 0 0 3px rgba(23,105,170,0.1);
        }
        .form-group input[readonly] {
            background: #f8fafc;
            color: #64748b;
            cursor: not-allowed;
        }

        .notice-alert {
            padding: 13px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .notice-alert.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .notice-alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        .profile-photo-container {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .avatar-wrap {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            background: #e2e8f0;
            border: 2px solid #ffffff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        /* Responsive Breakpoints for Profil */
        @media (max-width: 768px) {
            .navbar {
                padding: 0 16px;
                height: 64px;
            }
            .nav-title span {
                display: none;
            }
            .container {
                padding: 18px 16px 50px;
            }
        }

        @media (max-width: 600px) {
            .navbar {
                padding: 0 12px;
                height: 60px;
            }
            .nav-brand {
                gap: 8px;
            }
            .nav-logo-badge {
                width: 34px;
                height: 34px;
                font-size: 14px;
            }
            .nav-title strong {
                font-size: 15px;
            }
            .btn-nav-back-full {
                display: none;
            }
            .btn-nav-back-short {
                display: inline;
            }
            .profile-photo-container {
                flex-direction: column;
                text-align: center;
                gap: 14px;
            }
            .profile-form-grid {
                grid-template-columns: 1fr !important;
                gap: 12px;
            }
            .panel-header {
                padding: 14px 16px;
            }
            .panel-body {
                padding: 16px;
            }
            .form-actions-bar {
                flex-direction: column-reverse;
                gap: 8px;
            }
            .form-actions-bar .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<header class="navbar">
    <a href="index.php" class="nav-brand">
        <div class="nav-logo-badge">SP</div>
        <div class="nav-title">
            <strong>SIPALA</strong>
            <span>Sistem Peminjaman Alat Laboratorium</span>
        </div>
    </a>
    <?php
    $dashboardUrl = in_array($_SESSION['role'] ?? '', ['admin', 'petugas'], true) ? 'admin/dashboard.php' : 'index.php';
    $dashboardLabel = in_array($_SESSION['role'] ?? '', ['admin', 'petugas'], true) ? 'Dashboard Admin' : 'Beranda';
    $roleTitle = match($user['role'] ?? '') {
        'admin' => 'Administrator',
        'petugas' => 'Petugas Laboratorium',
        default => 'Siswa / Peminjam'
    };
    ?>
    <div style="display:flex;gap:8px;align-items:center;">
        <a href="<?= e($dashboardUrl) ?>" class="btn btn-sm"><span class="btn-nav-back-full">&larr; Kembali ke <?= e($dashboardLabel) ?></span><span class="btn-nav-back-short" style="display:none;">&larr; <?= e($dashboardLabel) ?></span></a>
        <a href="logout.php" onclick="confirmLogoutUser(event)" class="btn btn-sm" style="color:#dc2626;border-color:#fecaca;">Keluar</a>
    </div>
</header>

<div class="container">
    <div style="margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h1 style="font-size:20px;color:#0f172a;font-weight:700;">Pengaturan Profil & Akun <?= e($roleTitle) ?></h1>
            <p style="font-size:13px;color:#64748b;margin-top:2px;">
                Perbarui foto profil, nomor kontak WhatsApp, serta kata sandi akun Anda.
            </p>
        </div>
    </div>

    <?php if ($flashMsg !== ''): ?>
        <div class="notice-alert <?= $flashType ?>">
            <div><?= $flashMsg ?></div>
            <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;font-size:16px;">&times;</button>
        </div>
    <?php endif; ?>

    <form method="post" action="profil.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <!-- PANEL 1: FOTO PROFIL & IDENTITAS -->
        <div class="panel">
            <div class="panel-header">
                <h2>Foto Profil & Identitas <?= $isStudent ? 'Siswa' : 'Pengguna' ?></h2>
            </div>
            <div class="panel-body">
                <div class="profile-photo-container">
                    <div class="avatar-wrap">
                        <?php if (!empty($user['foto']) && file_exists(__DIR__ . '/' . $user['foto'])): ?>
                            <img src="<?= e($user['foto']) ?>" alt="Foto Profil" class="avatar-img sipala-zoomable-img" id="previewImg"
                                 data-lightbox-src="<?= e($user['foto']) ?>" 
                                 data-lightbox-title="<?= e($user['nama']) ?>" 
                                 data-lightbox-sub="<?= $isStudent ? 'NIS: ' . e($user['nis'] ?: '-') . ' &bull; Kelas: ' . e($user['kelas'] ?: '-') : 'Akun: ' . ucfirst(e($user['role'])) . ' &bull; Username: @' . e($user['username']) ?>" 
                                 data-lightbox-badge="<?= $isStudent ? 'Foto Profil Siswa' : 'Foto Profil ' . e($roleTitle) ?>"
                                 title="Klik untuk melihat foto ukuran penuh">
                        <?php else: ?>
                            <div id="placeholderInitial" style="font-size:28px;font-weight:700;color:#1769AA;">
                                <?= strtoupper(substr($user['nama'], 0, 1)) ?>
                            </div>
                            <img src="" alt="Pratinjau Foto" class="avatar-img sipala-zoomable-img" id="previewImg" style="display:none;"
                                 title="Klik untuk melihat foto ukuran penuh">
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-size:13px;font-weight:700;color:#1e293b;margin-bottom:4px;">
                            Ganti Foto Profil (JPG, PNG, WEBP, Maks. 2MB)
                        </label>
                        <input type="file" name="foto_file" id="foto_file" accept=".jpg,.jpeg,.png,.webp"
                               style="padding:6px;font-size:12px;width:100%;max-width:340px;border:1px solid #cbd5e1;border-radius:6px;background:#ffffff;">
                        <input type="hidden" name="foto_cropped" id="foto_cropped" value="">
                        <div style="font-size:11px;color:#0284c7;margin-top:3px;">
                            &#10024; <em>Pilih gambar untuk membuka alat pemotong (crop & zoom) interaktif otomatis.</em>
                        </div>
                        <div style="margin-top:6px;display:flex;gap:12px;align-items:center;">
                            <small style="color:#64748b;"><?= $isStudent ? 'Foto profil akan tampil di kartu pelajar laboratorium Anda.' : 'Foto profil akan tampil pada topbar dan riwayat aktivitas sistem.' ?></small>
                            <?php if (!empty($user['foto'])): ?>
                                <label style="font-size:12px;color:#dc2626;display:inline-flex;align-items:center;gap:4px;cursor:pointer;">
                                    <input type="checkbox" name="hapus_foto" value="1"> Hapus foto profil
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($isStudent): ?>
                    <div class="profile-form-grid">
                        <div class="form-group">
                            <label>Nama Lengkap *</label>
                            <input type="text" name="nama" required value="<?= e($user['nama']) ?>">
                        </div>
                        <div class="form-group">
                            <label>NIS (Nomor Induk Siswa)</label>
                            <input type="text" readonly value="<?= e($user['nis'] ?: '-') ?>" title="NIS dikelola oleh admin sekolah">
                        </div>
                    </div>

                    <div class="profile-form-grid">
                        <div class="form-group">
                            <label>Kelas / Jurusan *</label>
                            <input type="text" name="kelas" required value="<?= e($user['kelas'] ?: '') ?>" placeholder="Contoh: XII RPL 1">
                        </div>
                        <div class="form-group">
                            <label>No. WhatsApp / HP</label>
                            <input type="text" name="no_hp" value="<?= e($user['no_hp'] ?: '') ?>" placeholder="Contoh: 081234567890">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Alamat Rumah</label>
                        <textarea name="alamat" rows="2" placeholder="Alamat tempat tinggal siswa..."><?= e($user['alamat'] ?: '') ?></textarea>
                    </div>
                <?php else: ?>
                    <div class="profile-form-grid">
                        <div class="form-group">
                            <label>Nama Lengkap *</label>
                            <input type="text" name="nama" required value="<?= e($user['nama']) ?>" placeholder="Nama lengkap admin/petugas">
                        </div>
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" readonly value="<?= e($user['username']) ?>" style="background:#f1f5f9;cursor:not-allowed;" title="Username akun">
                        </div>
                    </div>

                    <div class="profile-form-grid">
                        <div class="form-group">
                            <label>No. WhatsApp / HP</label>
                            <input type="text" name="no_hp" value="<?= e($user['no_hp'] ?: '') ?>" placeholder="Contoh: 081234567890">
                        </div>
                        <div class="form-group">
                            <label>Hak Akses / Peran</label>
                            <input type="text" readonly value="<?= e(strtoupper($user['role'])) ?>" style="background:#f1f5f9;cursor:not-allowed;font-weight:600;color:#1769AA;" title="Hak akses sistem">
                        </div>
                    </div>
                <?php endif; ?>

                <div style="background:#f8fafc;padding:10px 14px;border-radius:6px;border:1px solid #e2e8f0;font-size:12px;color:#64748b;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div>Status Kartu Smart RFID: <strong style="font-family:monospace;color:#1e293b;"><?= !empty($user['rfid_uid']) ? e($user['rfid_uid']) . ' (TERDAFTAR)' : 'BELUM TERDAFTAR' ?></strong></div>
                    <div>Peran Akun: <strong style="text-transform:uppercase;color:#1769AA;"><?= e($user['role']) ?></strong></div>
                </div>
            </div>
        </div>

        <?php if ($isStudent): ?>
        <!-- PANEL INFORMASI DENDA & TATA TERTIB LAB (KHUSUS SISWA) -->
        <div class="panel" style="border-left:4px solid <?= $totalDenda > 0 ? '#dc2626' : '#16a34a' ?>;">
            <div class="panel-header" style="background:<?= $totalDenda > 0 ? '#fef2f2' : '#f0fdf4' ?>;">
                <div>
                    <h2 style="color:<?= $totalDenda > 0 ? '#991b1b' : '#166534' ?>;margin:0;">
                        Status Denda & Catatan Laboratorium
                    </h2>
                    <p style="font-size:12px;color:#64748b;margin:2px 0 0 0;">
                        Informasi keterlambatan pengembalian dan ganti rugi fisik alat laboratorium.
                    </p>
                </div>
                <div>
                    <?php if ($totalDenda > 0): ?>
                        <span style="font-size:11px;color:#991b1b;display:block;">Total Denda:</span>
                        <strong style="font-size:16px;color:#dc2626;">Rp <?= number_format($totalDenda, 0, ',', '.') ?></strong>
                    <?php else: ?>
                        <span style="background:#dcfce7;color:#15803d;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:700;border:1px solid #86efac;">
                            Bebas Denda / Tertib
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="panel-body">
                <?php if ($totalDenda > 0): ?>
                    <div style="background:#fff5f5;border:1px solid #fecaca;padding:12px 16px;border-radius:6px;margin-bottom:14px;color:#991b1b;font-size:13px;">
                        <strong>Perhatian:</strong> Akun Anda memiliki catatan denda keterlambatan atau penggantian fisik alat laboratorium. Silakan selesaikan kewajiban di ruang petugas laboratorium.
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:10px;">
                        <?php foreach ($myFines as $f): ?>
                            <div style="border:1px solid #fed7aa;background:#fffaf0;border-radius:6px;padding:12px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <span style="font-family:monospace;font-weight:700;color:#1e40af;"><?= e($f['kode_peminjaman']) ?></span>
                                    <strong style="color:#c2410c;font-size:13px;">Rp <?= number_format((float)$f['denda'], 0, ',', '.') ?></strong>
                                </div>
                                <div style="font-size:12px;color:#475569;margin-top:4px;">
                                    Tgl Kembali: <?= e(date('d-m-Y', strtotime($f['tanggal_pengembalian']))) ?> &bull; Kondisi: <strong><?= e($f['kondisi_barang']) ?></strong>
                                    <?php if ((int)$f['terlambat_hari'] > 0): ?>
                                        &bull; Telat <?= (int)$f['terlambat_hari'] ?> hari
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($f['catatan'])): ?>
                                    <div style="font-size:11px;color:#78350f;margin-top:6px;background:#fef3c7;padding:4px 8px;border-radius:4px;">
                                        Catatan: <em><?= e($f['catatan']) ?></em>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="font-size:13px;color:#166534;display:flex;align-items:center;gap:8px;">
                        <span style="font-size:18px;">&#10004;</span>
                        <span>Tidak ada tagihan denda keterlambatan maupun catatan kerusakan alat pada akun Anda. Terima kasih telah menjaga inventaris laboratorium dengan baik!</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- PANEL 2: GANTI PASSWORD (OPSIONAL) -->
        <div class="panel">
            <div class="panel-header">
                <h2>Ganti Kata Sandi (Password)</h2>
                <small style="color:#64748b;">Kosongkan jika tidak ingin mengubah kata sandi.</small>
            </div>
            <div class="panel-body">
                <div class="form-group">
                    <label>Password Lama</label>
                    <input type="password" name="old_password" placeholder="Masukkan kata sandi lama Anda...">
                </div>

                <div class="profile-form-grid">
                    <div class="form-group">
                        <label>Password Baru (Minimal 6 Karakter)</label>
                        <input type="password" name="new_password" placeholder="Password baru...">
                    </div>
                    <div class="form-group">
                        <label>Konfirmasi Password Baru</label>
                        <input type="password" name="confirm_password" placeholder="Ketik ulang password baru...">
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions-bar" style="display:flex;justify-content:flex-end;gap:10px;">
            <a href="<?= e($dashboardUrl) ?>" class="btn">Batal</a>
            <button type="submit" class="btn btn-primary" style="padding:10px 22px;font-size:14px;">Simpan Perubahan Profil</button>
        </div>
    </form>
</div>

<script>
function previewPhoto(input) {
    const previewImg = document.getElementById('previewImg');
    const placeholder = document.getElementById('placeholderInitial');
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (file.size > 2 * 1024 * 1024) {
            alert('Ukuran foto maksimal 2 MB.');
            input.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewImg.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
        };
        reader.readAsDataURL(file);
    }
}

function confirmLogoutUser(e) {
    if (e) e.preventDefault();
    if (!window.Swal) {
        if (confirm('Apakah Anda yakin ingin keluar dari akun SIPALA?')) {
            window.location.href = 'logout.php';
        }
        return;
    }
    Swal.fire({
        title: 'Keluar dari SIPALA?',
        text: 'Anda akan mengakhiri sesi akun saat ini.',
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
</script>
<script src="assets/js/sweetalert2.all.min.js"></script>
<script src="assets/js/image-cropper-modal.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.SipalaCropper) {
        SipalaCropper.setup({
            inputId: 'foto_file',
            previewId: 'previewImg',
            placeholderId: 'placeholderInitial',
            hiddenInputId: 'foto_cropped',
            aspectRatio: 1, // 1:1 square
            shape: 'circle',
            title: 'Sesuaikan Posisi & Potong Foto Profil'
        });
    }
});
</script>
</body>
</html>
