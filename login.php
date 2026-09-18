<?php

/**
 * FILE       : login.php
 * MODUL      : Autentikasi SIPALA
 * FUNGSI     : Menampilkan dan memproses login pengguna SIPALA
 *              melalui RFID atau username dan password.
 * AKSES      : Publik
 * ALUR       : Pengguna memilih metode login → autentikasi →
 *              session → redirect ke halaman sesuai hak akses.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Memeriksa apakah pengguna sudah memiliki sesi aktif; jika ya, arahkan sesuai peran
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';
    if (in_array($role, ['admin', 'petugas'], true)) {
        header('Location: admin/dashboard.php');
        exit;
    }
    if ($role === 'peminjam') {
        header('Location: index.php');
        exit;
    }
}

$error = '';
$activeTab = 'rfid';
$pdo = db();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1');

// Proteksi Brute-Force: Cek apakah sesi sedang terkena penundaan akibat terlalu banyak kegagalan
$failedAttempts = (int)($_SESSION['login_failed_attempts'] ?? 0);
$lastFailedTime = (int)($_SESSION['login_last_failed_time'] ?? 0);
$lockoutTime = 30; // 30 detik cooldown jika gagal 5x berturut-turut
$isLockedOut = ($failedAttempts >= 5 && (time() - $lastFailedTime) < $lockoutTime);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLockedOut) {
    $remaining = $lockoutTime - (time() - $lastFailedTime);
    $lockoutMsg = 'Terlalu banyak percobaan masuk yang gagal. Silakan tunggu ' . $remaining . ' detik sebelum mencoba kembali.';
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $lockoutMsg]);
        exit;
    }
    $error = $lockoutMsg;
}

// =========================================================================
// 1. AUTENTIKASI METODE 1: TAP KARTU IDENTITAS RFID SISWA / PETUGAS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_rfid'])) {
    if (!verify_csrf()) {
        $error = 'Sesi formulir kedaluwarsa. Silakan muat ulang halaman.';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } else {
        $rfidUid = trim((string)($_POST['rfid_uid'] ?? ''));

        if ($rfidUid === '') {
            $error = 'Silakan tempelkan kartu RFID ke alat reader.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => $error]);
                exit;
            }
        } else {
            try {
                // Mencocokkan nomor UID kartu RFID terhadap pengguna aktif di database
                $stmt = $pdo->prepare('
                    SELECT id, nama, username, role, status, nis, kelas, rfid_uid, foto 
                    FROM users 
                    WHERE rfid_uid = ? 
                    LIMIT 1
                ');
                $stmt->execute([$rfidUid]);
                $user = $stmt->fetch();

                if (!$user) {
                    audit_log('LOGIN_FAILED', 'AUTH', null, 'Login RFID gagal: kartu tidak terdaftar (' . substr($rfidUid, 0, 4) . '***)');
                    $_SESSION['login_failed_attempts'] = ((int)($_SESSION['login_failed_attempts'] ?? 0)) + 1;
                    $_SESSION['login_last_failed_time'] = time();
                    $error = 'Kartu RFID belum terdaftar. Silakan hubungi petugas Lab Komputer RPL.';
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error' => $error]);
                        exit;
                    }
                } elseif ($user['status'] !== 'aktif') {
                    audit_log('LOGIN_FAILED', 'AUTH', (int)$user['id'], 'Login ditolak: akun dinonaktifkan');
                    $error = 'Akun Anda sedang dinonaktifkan. Silakan hubungi petugas Lab.';
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error' => $error]);
                        exit;
                    }
                } else {
                    // Regenerasi ID sesi untuk mencegah session fixation attack
                    session_regenerate_id(true);
                    unset($_SESSION['login_failed_attempts'], $_SESSION['login_last_failed_time']);
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['nama'] = $user['nama'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['nis'] = $user['nis'];
                    $_SESSION['kelas'] = $user['kelas'];
                    $_SESSION['rfid_uid'] = $user['rfid_uid'];
                    $_SESSION['foto'] = $user['foto'] ?? '';
                    $welcomeMsg = 'Selamat datang, ' . $user['nama'] . '! Anda berhasil masuk ke sistem.';
                    $_SESSION['login_success'] = $welcomeMsg;

                    audit_log('LOGIN_SUCCESS', 'AUTH', (int)$user['id'], 'Login berhasil via Kartu RFID', (int)$user['id']);

                    $redirectUrl = in_array($user['role'], ['admin', 'petugas'], true) 
                        ? 'admin/dashboard.php' 
                        : 'index.php';

                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success' => true,
                            'message' => $welcomeMsg,
                            'nama' => $user['nama'],
                            'role' => $user['role'],
                            'redirect' => $redirectUrl
                        ]);
                        exit;
                    }

                    header('Location: ' . $redirectUrl);
                    exit;
                }
            } catch (Throwable $e) {
                error_log('Login RFID Exception: ' . $e->getMessage());
                $error = 'Terjadi kesalahan sistem saat memproses kartu. Silakan coba lagi.';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => $error]);
                    exit;
                }
            }
        }
    }
}

// =========================================================================
// 2. AUTENTIKASI METODE 2: KREDENSIAL USERNAME / NIS & PASSWORD
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_akun'])) {
    $activeTab = 'akun';
    if (!verify_csrf()) {
        $error = 'Sesi formulir kedaluwarsa. Silakan muat ulang halaman.';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Username/NIS dan Password wajib diisi.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => $error]);
                exit;
            }
        } else {
            try {
                // Mencocokkan username atau NIS siswa
                $stmt = $pdo->prepare('
                    SELECT id, nama, username, password, role, status, nis, kelas, rfid_uid, foto 
                    FROM users 
                    WHERE username = ? OR nis = ? 
                    LIMIT 1
                ');
                $stmt->execute([$username, $username]);
                $user = $stmt->fetch();

                // Verifikasi keamanan hash kata sandi menggunakan algoritma Bcrypt
                if (!$user || !password_verify($password, $user['password'])) {
                    audit_log('LOGIN_FAILED', 'AUTH', null, 'Login gagal untuk identitas: ' . $username);
                    $_SESSION['login_failed_attempts'] = ((int)($_SESSION['login_failed_attempts'] ?? 0)) + 1;
                    $_SESSION['login_last_failed_time'] = time();
                    $error = 'Username / NIS atau Password salah.';
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error' => $error]);
                        exit;
                    }
                } elseif ($user['status'] !== 'aktif') {
                    audit_log('LOGIN_FAILED', 'AUTH', (int)$user['id'], 'Login ditolak: akun dinonaktifkan');
                    $error = 'Akun Anda sedang dinonaktifkan. Silakan hubungi petugas Lab.';
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error' => $error]);
                        exit;
                    }
                } else {
                    session_regenerate_id(true);
                    unset($_SESSION['login_failed_attempts'], $_SESSION['login_last_failed_time']);
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['nama'] = $user['nama'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['nis'] = $user['nis'];
                    $_SESSION['kelas'] = $user['kelas'];
                    $_SESSION['rfid_uid'] = $user['rfid_uid'];
                    $_SESSION['foto'] = $user['foto'] ?? '';
                    $welcomeMsg = 'Selamat datang, ' . $user['nama'] . '! Anda berhasil masuk ke sistem.';
                    $_SESSION['login_success'] = $welcomeMsg;

                    audit_log('LOGIN_SUCCESS', 'AUTH', (int)$user['id'], 'Login berhasil via Akun: ' . $user['username'], (int)$user['id']);

                    $redirectUrl = in_array($user['role'], ['admin', 'petugas'], true) 
                        ? 'admin/dashboard.php' 
                        : 'index.php';

                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success' => true,
                            'message' => $welcomeMsg,
                            'nama' => $user['nama'],
                            'role' => $user['role'],
                            'redirect' => $redirectUrl
                        ]);
                        exit;
                    }

                    header('Location: ' . $redirectUrl);
                    exit;
                }
            } catch (Throwable $e) {
                error_log('Login Akun Exception: ' . $e->getMessage());
                $error = 'Terjadi kesalahan sistem saat memproses login. Silakan coba lagi.';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => $error]);
                    exit;
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk ke SIPALA - Lab Komputer RPL</title>
    <link rel="icon" type="image/jpeg" href="assets/img/logo.jpg?v=2">
    <link rel="shortcut icon" type="image/jpeg" href="assets/img/logo.jpg?v=2">
    <link rel="apple-touch-icon" href="assets/img/logo.jpg?v=2">
    <style>
        :root {
            --primary: #1e40af;
            --primary-hover: #1d4ed8;
            --navy-dark: #0f172a;
            --navy-surface: #1e293b;
            --bg-page: #f1f5f9;
            --surface: #ffffff;
            --text-title: #0f172a;
            --text-body: #334155;
            --text-muted: #64748b;
            --border: #cbd5e1;
            --border-light: #e2e8f0;
            --danger: #b91c1c;
            --danger-bg: #fef2f2;
            --danger-border: #fecaca;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-body);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            line-height: 1.5;
        }

        /* Container Card Split Rapi & Seimbang */
        .login-card {
            width: 100%;
            max-width: 840px;
            background: var(--surface);
            border-radius: 12px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08);
            display: flex;
            overflow: hidden;
            min-height: 480px;
        }

        /* Panel Kiri: Identitas Resmi Lab RPL */
        .brand-panel {
            flex: 0 0 350px;
            background: var(--navy-dark);
            color: #ffffff;
            padding: 40px 32px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }

        .brand-header {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-logo {
            width: 72px;
            height: 52px;
            border-radius: 8px;
            object-fit: contain;
            background: #ffffff;
            padding: 3px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            flex-shrink: 0;
        }

        .brand-title h1 {
            font-size: 20px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }

        .brand-title p {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 2px;
        }

        .brand-body {
            margin: auto 0;
            padding: 24px 0;
        }

        .brand-body h2 {
            font-size: 15px;
            font-weight: 600;
            color: #f8fafc;
            line-height: 1.45;
            margin-bottom: 10px;
        }

        .brand-body p {
            font-size: 13px;
            color: #94a3b8;
            line-height: 1.6;
        }

        .brand-footer {
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 11.5px;
            color: #64748b;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand-footer span {
            color: #94a3b8;
            font-weight: 500;
        }

        /* Panel Kanan: Area Form Masuk */
        .form-panel {
            flex: 1;
            padding: 40px 36px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: #ffffff;
        }

        .form-head {
            margin-bottom: 20px;
        }

        .form-head h2 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-title);
            line-height: 1.25;
        }

        .form-head p {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Pesan Peringatan */
        .alert-error {
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger);
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 500;
            margin-bottom: 16px;
            line-height: 1.4;
        }

        /* Tab Switcher: Rapi & Bersih */
        .tab-switcher {
            display: flex;
            background: #f1f5f9;
            padding: 3px;
            border-radius: 7px;
            margin-bottom: 20px;
            border: 1px solid var(--border-light);
        }

        .tab-item {
            flex: 1;
            padding: 8px 12px;
            border: none;
            background: transparent;
            color: var(--text-muted);
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            border-radius: 5px;
            cursor: pointer;
            text-align: center;
            transition: color 0.15s ease, background 0.15s ease;
        }

        .tab-item:hover {
            color: var(--text-title);
        }

        .tab-item.active {
            background: #ffffff;
            color: var(--text-title);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        /* Area Form Terkontrol & Konsisten (Tinggi Sama Persis di Kedua Tab) */
        .tab-body {
            position: relative;
            height: 196px; /* Ketinggian seragam presisi di kedua tab */
        }

        .auth-form {
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group:last-of-type {
            margin-bottom: 0;
        }

        .form-label-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 5px;
        }

        .form-label {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-title);
        }

        .btn-link {
            background: none;
            border: none;
            color: var(--primary);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
            font-family: inherit;
        }

        .btn-link:hover {
            text-decoration: underline;
        }

        .form-control {
            width: 100%;
            height: 40px;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid var(--border);
            font-family: inherit;
            font-size: 13.5px;
            color: var(--text-title);
            background: #ffffff;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.12);
        }

        .password-wrap {
            position: relative;
            width: 100%;
        }

        .password-wrap .form-control {
            padding-right: 38px;
        }

        .btn-eye-toggle {
            position: absolute;
            right: 0;
            top: 0;
            height: 100%;
            width: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0;
            transition: color 0.15s ease;
        }

        .btn-eye-toggle:hover {
            color: var(--text-title);
        }

        .btn-eye-toggle:focus-visible {
            outline: 2px solid var(--primary);
            border-radius: 4px;
        }

        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
            line-height: 1.4;
        }

        /* Tombol Masuk Utama */
        .btn-submit {
            width: 100%;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            border: 1px solid var(--primary);
            background: var(--primary);
            color: #ffffff;
            font-family: inherit;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s;
            margin-top: 16px;
        }

        .btn-submit:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
        }

        .btn-submit:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        /* Navigasi Footer Bawah */
        .form-footer {
            margin-top: 24px;
            padding-top: 14px;
            border-top: 1px solid var(--border-light);
            text-align: center;
            font-size: 12.5px;
            color: var(--text-muted);
        }

        .form-footer a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }

        .form-footer a:hover {
            text-decoration: underline;
        }

        .foot-back {
            margin-top: 8px;
            font-size: 12px;
        }

        .foot-back a {
            color: var(--text-muted);
            text-decoration: none;
        }

        .foot-back a:hover {
            color: var(--text-title);
        }

        /* Responsif untuk Layar Kecil / Mobile */
        @media (max-width: 768px) {
            .login-card {
                flex-direction: column;
                max-width: 420px;
                min-height: auto;
            }
            .brand-panel {
                flex: none;
                padding: 24px 20px;
                border-right: none;
                border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            }
            .brand-body {
                padding: 14px 0;
            }
            .form-panel {
                padding: 24px 20px;
            }
            .tab-body {
                height: auto;
            }
            .auth-form {
                height: auto;
            }
            .btn-submit {
                margin-top: 18px;
            }
        }
    </style>
</head>
<body>

<div class="login-card">

    <!-- =========================================================
         PANEL KIRI: IDENTITAS RESMI LAB KOMPUTER RPL
    ========================================================= -->
    <div class="brand-panel">
        
        <div class="brand-header">
            <img src="assets/img/logo.jpg" alt="Logo SIPALA" class="brand-logo">
            <div class="brand-title">
                <h1>Sistem Informasi Peminjaman Alat</h1>
                
            </div>
        </div>

        <div class="brand-body">
            <h2> </h2>
            <p>
                Platform pengelolaan inventaris dan sirkulasi peminjaman perangkat laboratorium Rekayasa Perangkat Lunak secara tertib, akuntabel, dan terdata.
            </p>
        </div>

        <div class="brand-footer">
            <div>
                <span>LAB KOMPUTER RPL</span><br>
                SMK NEGERI 1 PANYINGKIRAN
            </div>
            <div>&copy; <?= date('Y') ?> SIPALA</div>
        </div>

    </div>

    <!-- =========================================================
         PANEL KANAN: FORM AUTENTIKASI DENGAN DIMENSI KONSISTEN
    ========================================================= -->
    <div class="form-panel">
        
        <div>
            <div class="form-head">
                <h2>Masuk ke SIPALA</h2>
                <p>Silakan pilih metode autentikasi yang digunakan.</p>
            </div>

            <!-- Pesan Kesalahan Autentikasi -->
            <?php if ($error !== ''): ?>
                <div class="alert-error" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <!-- Tab Switcher -->
            <div class="tab-switcher">
                <button type="button" 
                        class="tab-item <?= $activeTab === 'rfid' ? 'active' : '' ?>" 
                        id="tabRfidBtn" 
                        onclick="switchTab('rfid')">
                    Kartu RFID
                </button>
                <button type="button" 
                        class="tab-item <?= $activeTab === 'akun' ? 'active' : '' ?>" 
                        id="tabAkunBtn" 
                        onclick="switchTab('akun')">
                    Username & Password
                </button>
            </div>

            <!-- Area Form: Dimensi Tetap Sama Persis di Kedua Tab -->
            <div class="tab-body">
                
                <!-- TAB 1: KARTU IDENTITAS RFID -->
                <div id="sectionRfid" style="<?= $activeTab === 'rfid' ? 'display:block;' : 'display:none;' ?>" class="tab-pane">
                    <form method="post" id="formRfidLogin" class="auth-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="login_rfid" value="1">

                        <div class="form-fields">
                            <div class="form-group">
                                <div class="form-label-row">
                                    <label class="form-label" for="loginRfidInput">Nomor Kartu / UID RFID</label>
                                </div>
                                <input type="password" 
                                       name="rfid_uid" 
                                       id="loginRfidInput" 
                                       placeholder="Tempelkan kartu RFID pada scanner..." 
                                       class="form-control rfid-autofocus" 
                                       data-rfid-scanner="true" 
                                       data-auto-submit="true"
                                       autocomplete="off">
                                <p class="form-hint">
                                    Dekatkan kartu ke USB RFID reader lab untuk masuk otomatis, atau ketik UID kartu secara manual.
                                </p>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit" id="btnRfidSubmit">
                            Masuk dengan RFID
                        </button>
                    </form>
                </div>

                <!-- TAB 2: USERNAME & PASSWORD -->
                <div id="sectionAkun" style="<?= $activeTab === 'akun' ? 'display:block;' : 'display:none;' ?>" class="tab-pane">
                    <form method="post" id="formAkunLogin" class="auth-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="login_akun" value="1">

                        <div class="form-fields">
                            <div class="form-group">
                                <div class="form-label-row">
                                    <label class="form-label" for="usernameInput">Username atau NIS</label>
                                </div>
                                <input type="text" 
                                       id="usernameInput" 
                                       name="username" 
                                       class="form-control" 
                                       placeholder="Masukkan username atau NIS siswa" 
                                       required>
                            </div>

                            <div class="form-group">
                                <div class="form-label-row">
                                    <label class="form-label" for="passwordInput">Password</label>
                                </div>
                                <div class="password-wrap">
                                    <input type="password" 
                                           id="passwordInput" 
                                           name="password" 
                                           class="form-control" 
                                           placeholder="Masukkan password akun" 
                                           required>
                                    <button type="button" 
                                            class="btn-eye-toggle" 
                                            id="pwdToggleBtn" 
                                            onclick="togglePasswordVisibility()" 
                                            title="Tampilkan password" 
                                            aria-label="Tampilkan atau sembunyikan password">
                                        <svg id="eyeIconOpen" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        <svg id="eyeIconClosed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                            <line x1="1" y1="1" x2="23" y2="23"></line>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit" id="btnAkunSubmit">
                            Masuk ke Akun
                        </button>
                    </form>
                </div>

            </div>
        </div>

        <!-- Footer Navigasi Bawah -->
        <div class="form-footer">
            <div>
                Belum memiliki akun siswa? <a href="register.php">Daftar Akun Baru</a>
            </div>
            <div class="foot-back">
                <a href="index.php">&larr; Kembali ke Katalog Lab</a>
            </div>
        </div>

    </div>

</div>

<!-- Script SweetAlert2, Pembaca RFID & Interaksi Form -->
<script src="assets/js/sweetalert2.all.min.js"></script>
<script src="assets/js/rfid.js"></script>
<script>
/**
 * Berpindah tab antara Kartu RFID dan Username & Password
 *
 * @param {string} type - 'rfid' atau 'akun'
 */
function switchTab(type) {
    const tabRfidBtn = document.getElementById('tabRfidBtn');
    const tabAkunBtn = document.getElementById('tabAkunBtn');
    const secRfid = document.getElementById('sectionRfid');
    const secAkun = document.getElementById('sectionAkun');
    const rfidInput = document.getElementById('loginRfidInput');
    const userInput = document.getElementById('usernameInput');

    if (type === 'rfid') {
        tabRfidBtn.classList.add('active');
        tabAkunBtn.classList.remove('active');
        secRfid.style.display = 'block';
        secAkun.style.display = 'none';
        if (rfidInput) {
            setTimeout(() => rfidInput.focus(), 80);
        }
    } else {
        tabAkunBtn.classList.add('active');
        tabRfidBtn.classList.remove('active');
        secAkun.style.display = 'block';
        secRfid.style.display = 'none';
        if (userInput) {
            setTimeout(() => userInput.focus(), 80);
        }
    }
}

/**
 * Toggle tampilkan atau sembunyikan password dengan ikon mata
 */
function togglePasswordVisibility() {
    const pwdInput = document.getElementById('passwordInput');
    const eyeOpen = document.getElementById('eyeIconOpen');
    const eyeClosed = document.getElementById('eyeIconClosed');
    const toggleBtn = document.getElementById('pwdToggleBtn');
    if (!pwdInput) return;

    if (pwdInput.type === 'password') {
        pwdInput.type = 'text';
        if (eyeOpen) eyeOpen.style.display = 'none';
        if (eyeClosed) eyeClosed.style.display = 'block';
        if (toggleBtn) toggleBtn.title = 'Sembunyikan password';
    } else {
        pwdInput.type = 'password';
        if (eyeOpen) eyeOpen.style.display = 'block';
        if (eyeClosed) eyeClosed.style.display = 'none';
        if (toggleBtn) toggleBtn.title = 'Tampilkan password';
    }
}

// Inisialisasi Event Listener Login dengan Alert Proses & Berhasil
document.addEventListener('DOMContentLoaded', () => {
    const formRfid = document.getElementById('formRfidLogin');
    const formAkun = document.getElementById('formAkunLogin');

    async function handleLoginSubmit(form, processTitle, processText) {
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        // 1. TAMPILKAN ALERT PROSES LOGIN (SWEETALERT2 LOADING)
        if (window.Swal) {
            Swal.fire({
                title: processTitle,
                text: processText,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
        }

        try {
            const formData = new FormData(form);
            formData.append('ajax', '1');

            const response = await fetch('login.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (data.success) {
                // Langsung redirect ke halaman tujuan (admin/pengguna) agar alert login sukses muncul sekali di halaman tujuan
                window.location.href = data.redirect || 'index.php';
                return;
            } else {
                // 3. TAMPILKAN ALERT GAGAL LOGIN
                if (submitBtn) submitBtn.disabled = false;
                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Masuk',
                        text: data.error || 'Terjadi kesalahan saat memproses login.',
                        confirmButtonColor: '#1e40af',
                        confirmButtonText: 'Coba Lagi'
                    });
                } else {
                    alert(data.error || 'Login gagal.');
                }
            }
        } catch (err) {
            console.error('AJAX login error, fallback ke submit biasa:', err);
            form.submit();
        }
    }

    if (formRfid) {
        formRfid.addEventListener('submit', (e) => {
            e.preventDefault();
            handleLoginSubmit(
                formRfid,
                'Memverifikasi Kartu...',
                'Sedang membaca dan memvalidasi kartu identitas RFID.'
            );
        });
    }

    if (formAkun) {
        formAkun.addEventListener('submit', (e) => {
            e.preventDefault();
            handleLoginSubmit(
                formAkun,
                'Memproses Masuk...',
                'Sedang memverifikasi data akun username dan password.'
            );
        });
    }
});
</script>

</body>
</html>
