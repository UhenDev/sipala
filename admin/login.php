<?php

/**
 * FILE       : admin/login.php
 * MODUL      : Autentikasi Khusus Petugas & Administrator SIPALA
 * FUNGSI     : Menampilkan dan memproses autentikasi khusus staf/petugas/admin
 *              melalui Kartu RFID Petugas atau Username & Password.
 * AKSES      : Publik / Staf
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Jika pengguna sudah memiliki sesi aktif
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';
    if (in_array($role, ['admin', 'petugas'], true)) {
        header('Location: dashboard.php');
        exit;
    }
    // Jika siswa sudah login, arahkan ke katalog peminjam
    if ($role === 'peminjam') {
        header('Location: ../index.php');
        exit;
    }
}

$error = '';
$activeTab = 'rfid';
$pdo = db();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1');

// Proteksi Brute-Force
$failedAttempts = (int)($_SESSION['admin_login_failed_attempts'] ?? 0);
$lastFailedTime = (int)($_SESSION['admin_login_last_failed_time'] ?? 0);
$lockoutTime = 30;
$isLockedOut = ($failedAttempts >= 5 && (time() - $lastFailedTime) < $lockoutTime);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLockedOut) {
    $remaining = $lockoutTime - (time() - $lastFailedTime);
    $lockoutMsg = 'Terlalu banyak percobaan masuk. Tunggu ' . $remaining . ' detik sebelum mencoba kembali.';
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $lockoutMsg]);
        exit;
    }
    $error = $lockoutMsg;
}

// -------------------------------------------------------------
// 1. AUTENTIKASI VIA KARTU RFID PETUGAS / ADMIN
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLockedOut && isset($_POST['login_rfid'])) {
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
            $error = 'Silakan tempelkan kartu RFID petugas ke alat reader.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => $error]);
                exit;
            }
        } else {
            try {
                $stmt = $pdo->prepare('
                    SELECT id, nama, username, role, status, nis, kelas, rfid_uid, foto 
                    FROM users 
                    WHERE rfid_uid = ? 
                    LIMIT 1
                ');
                $stmt->execute([$rfidUid]);
                $user = $stmt->fetch();

                if (!$user) {
                    audit_log('LOGIN_FAILED', 'AUTH', null, 'Login Admin RFID gagal: kartu tidak terdaftar (' . substr($rfidUid, 0, 4) . '***)');
                    $_SESSION['admin_login_failed_attempts'] = ((int)($_SESSION['admin_login_failed_attempts'] ?? 0)) + 1;
                    $_SESSION['admin_login_last_failed_time'] = time();
                    $error = 'Kartu RFID tidak terdaftar sebagai petugas / admin lab.';
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error' => $error]);
                        exit;
                    }
                } elseif ($user['status'] !== 'aktif') {
                    $error = 'Akun sedang dinonaktifkan. Silakan hubungi kepala lab.';
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error' => $error]);
                        exit;
                    }
                } elseif (!in_array($user['role'], ['admin', 'petugas'], true)) {
                    // Penolakan jika kartu milik siswa
                    $error = 'Akses ditolak: Kartu ini adalah akun Siswa/Peminjam. Silakan gunakan portal login siswa.';
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error' => $error]);
                        exit;
                    }
                } else {
                    session_regenerate_id(true);
                    unset($_SESSION['admin_login_failed_attempts'], $_SESSION['admin_login_last_failed_time']);
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['nama'] = $user['nama'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['rfid_uid'] = $user['rfid_uid'];
                    $_SESSION['foto'] = $user['foto'] ?? '';
                    $welcomeMsg = 'Selamat datang, ' . $user['nama'] . '! Anda berhasil masuk sebagai ' . ucfirst($user['role']) . '.';
                    $_SESSION['login_success'] = $welcomeMsg;

                    audit_log('LOGIN_SUCCESS', 'AUTH', (int)$user['id'], 'Login Admin berhasil via RFID', (int)$user['id']);

                    $redirectUrl = 'dashboard.php';

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
                error_log('Login Admin RFID Exception: ' . $e->getMessage());
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

// -------------------------------------------------------------
// 2. AUTENTIKASI VIA USERNAME & PASSWORD ADMIN
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLockedOut && isset($_POST['login_akun'])) {
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
            $error = 'Username dan password wajib diisi.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => $error]);
                exit;
            }
        } else {
            try {
                $stmt = $pdo->prepare('
                    SELECT id, nama, username, password, role, status, nis, kelas, rfid_uid, foto 
                    FROM users 
                    WHERE username = ? 
                    LIMIT 1
                ');
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if (!$user || !password_verify($password, $user['password'])) {
                    audit_log('LOGIN_FAILED', 'AUTH', null, 'Login Admin Akun gagal: kredensial salah (' . $username . ')');
                    $_SESSION['admin_login_failed_attempts'] = ((int)($_SESSION['admin_login_failed_attempts'] ?? 0)) + 1;
                    $_SESSION['admin_login_last_failed_time'] = time();
                    $error = 'Username atau password petugas tidak cocok.';
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error' => $error]);
                        exit;
                    }
                } elseif ($user['status'] !== 'aktif') {
                    $error = 'Akun Anda sedang dinonaktifkan. Silakan hubungi kepala lab.';
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error' => $error]);
                        exit;
                    }
                } elseif (!in_array($user['role'], ['admin', 'petugas'], true)) {
                    // Penolakan jika akun siswa
                    $error = 'Akses ditolak: Akun Siswa/Peminjam tidak dapat masuk ke panel admin. Gunakan portal siswa.';
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error' => $error]);
                        exit;
                    }
                } else {
                    session_regenerate_id(true);
                    unset($_SESSION['admin_login_failed_attempts'], $_SESSION['admin_login_last_failed_time']);
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['nama'] = $user['nama'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['rfid_uid'] = $user['rfid_uid'];
                    $_SESSION['foto'] = $user['foto'] ?? '';
                    $welcomeMsg = 'Selamat datang, ' . $user['nama'] . '! Anda berhasil masuk sebagai ' . ucfirst($user['role']) . '.';
                    $_SESSION['login_success'] = $welcomeMsg;

                    audit_log('LOGIN_SUCCESS', 'AUTH', (int)$user['id'], 'Login Admin berhasil via Akun: ' . $user['username'], (int)$user['id']);

                    $redirectUrl = 'dashboard.php';

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
                error_log('Login Admin Akun Exception: ' . $e->getMessage());
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
    <title>Masuk Petugas & Admin - SIPALA Lab Komputer RPL</title>
    <link rel="icon" type="image/jpeg" href="../assets/img/logo.jpg?v=2">
    <link rel="shortcut icon" type="image/jpeg" href="../assets/img/logo.jpg?v=2">
    <link rel="apple-touch-icon" href="../assets/img/logo.jpg?v=2">
    
    <!-- Font Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #1e40af;
            --primary-hover: #1d4ed8;
            --navy-dark: #0f172a;
            --navy-surface: #1e293b;
            --bg-page: #f8fafc;
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
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-body);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            line-height: 1.5;
        }

        .login-card {
            width: 100%;
            max-width: 1040px;
            background: var(--surface);
            border-radius: 18px;
            border: 1px solid var(--border-light);
            box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.12), 0 10px 16px -6px rgba(15, 23, 42, 0.05);
            display: flex;
            overflow: hidden;
            min-height: 560px;
        }

        .brand-panel {
            flex: 0 0 430px;
            color: #ffffff;
            padding: 48px 38px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: 
                linear-gradient(
                    to bottom,
                    rgba(15, 23, 42, 0.88) 0%,
                    rgba(15, 23, 42, 0.30) 35%,
                    rgba(15, 23, 42, 0.30) 60%,
                    rgba(15, 23, 42, 0.94) 100%
                ),
                url('../assets/img/login-bg.jpg') center 25% / cover no-repeat;
            border-right: 1px solid rgba(0, 0, 0, 0.08);
        }

        .brand-header {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .brand-logo {
            width: 68px;
            height: 58px;
            border-radius: 10px;
            object-fit: contain;
            background: #ffffff;
            padding: 3px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }

        .brand-title h1 {
            font-size: 21px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: 0.3px;
            line-height: 1.25;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.5);
        }

        .brand-body {
            margin: auto 0;
            padding: 24px 0;
        }

        .badge-admin-panel {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            background: rgba(220, 38, 38, 0.6);
            border: 1px solid rgba(254, 202, 202, 0.3);
            border-radius: 7px;
            font-size: 11.5px;
            font-weight: 600;
            color: #fee2e2;
            margin-bottom: 12px;
        }

        .brand-body p {
            font-size: 13.5px;
            color: #f1f5f9;
            line-height: 1.65;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.6);
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            padding: 18px 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .brand-footer {
            padding-top: 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.15);
            font-size: 12px;
            color: #cbd5e1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
        }

        .brand-footer span {
            color: #ffffff;
            font-weight: 600;
        }

        .form-panel {
            flex: 1;
            padding: 48px 46px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: #ffffff;
        }

        .form-head {
            margin-bottom: 24px;
        }

        .admin-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .form-head h2 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-title);
            line-height: 1.25;
        }

        .form-head p {
            font-size: 13.5px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .alert-error {
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger);
            padding: 11px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 18px;
            line-height: 1.45;
        }

        .tab-switcher {
            display: flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 9px;
            margin-bottom: 22px;
            border: 1px solid var(--border-light);
        }

        .tab-item {
            flex: 1;
            padding: 10px 14px;
            border: none;
            background: transparent;
            color: var(--text-muted);
            font-family: inherit;
            font-size: 13.5px;
            font-weight: 600;
            border-radius: 7px;
            cursor: pointer;
            text-align: center;
            transition: color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
        }

        .tab-item:hover {
            color: var(--text-title);
        }

        .tab-item.active {
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        .tab-body {
            position: relative;
            min-height: 232px;
        }

        .auth-form {
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group:last-of-type {
            margin-bottom: 0;
        }

        .form-label-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-title);
        }

        .form-control {
            width: 100%;
            height: 46px;
            padding: 10px 15px;
            border-radius: 8px;
            border: 1px solid var(--border);
            font-family: inherit;
            font-size: 14px;
            color: var(--text-title);
            background: #ffffff;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
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
            padding-right: 46px;
        }

        .btn-eye-toggle {
            position: absolute;
            right: 0;
            top: 0;
            height: 100%;
            width: 46px;
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

        .form-hint {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 7px;
            line-height: 1.45;
        }

        .btn-submit {
            width: 100%;
            height: 46px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            border: 1px solid var(--primary);
            background: var(--primary);
            color: #ffffff;
            font-family: inherit;
            font-size: 14.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease;
            margin-top: 18px;
        }

        .btn-submit:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
        }

        .btn-submit:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        .form-footer {
            margin-top: 28px;
            padding-top: 16px;
            border-top: 1px solid var(--border-light);
            text-align: center;
            font-size: 13px;
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
            font-size: 12.5px;
        }

        .foot-back a {
            color: var(--text-muted);
            text-decoration: none;
        }

        .foot-back a:hover {
            color: var(--text-title);
        }

        @media (max-width: 860px) {
            .login-card {
                flex-direction: column;
                max-width: 480px;
                min-height: auto;
            }
            .brand-panel {
                flex: none;
                padding: 28px 24px;
                border-right: none;
                border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            }
            .brand-body {
                padding: 16px 0;
            }
            .form-panel {
                padding: 28px 24px;
            }
            .tab-body {
                height: auto;
                min-height: auto;
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

    <!-- PANEL KIRI: IDENTITAS RESMI ADMIN & PETUGAS LAB RPL -->
    <div class="brand-panel">
        
        <div class="brand-header">
            <img src="../assets/img/logo.jpg" alt="Logo SIPALA" class="brand-logo">
            <div class="brand-title">
                <h1>Sistem Informasi Peminjaman Alat</h1>
            </div>
        </div>

        <div class="brand-body">
            <div class="badge-admin-panel">
                <span>🛡️ Area Petugas & Admin</span>
            </div>
            <p>
                Akses khusus pengelolaan sirkulasi alat, pendataan inventaris, dan administrasi laboratorium Rekayasa Perangkat Lunak.
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

    <!-- PANEL KANAN: FORM AUTENTIKASI ADMIN (RFID & USERNAME) -->
    <div class="form-panel">
        
        <div>
            <div class="form-head">
                <span class="admin-tag">Khusus Petugas / Administrator</span>
                <h2>Masuk ke SIPALA Admin</h2>
                <p>Silakan tempelkan kartu RFID staf atau masukkan akun petugas.</p>
            </div>

            <!-- Pesan Kesalahan Autentikasi -->
            <?php if ($error !== ''): ?>
                <div class="alert-error" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <!-- Tab Switcher RFID & Username -->
            <div class="tab-switcher">
                <button type="button" 
                        class="tab-item <?= $activeTab === 'rfid' ? 'active' : '' ?>" 
                        id="tabRfidBtn" 
                        onclick="switchTab('rfid')">
                    Kartu RFID Petugas
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
                                    <label class="form-label" for="loginRfidInput">Nomor Kartu / UID RFID Petugas</label>
                                </div>
                                <input type="password" 
                                       name="rfid_uid" 
                                       id="loginRfidInput" 
                                       placeholder="Tempelkan kartu RFID petugas..." 
                                       class="form-control rfid-autofocus" 
                                       data-rfid-scanner="true" 
                                       data-auto-submit="true"
                                       autocomplete="off">
                                <p class="form-hint">
                                    Dekatkan kartu petugas ke USB RFID reader lab untuk masuk otomatis, atau ketik UID kartu secara manual.
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
                    <form method="post" id="formAkunLogin" class="auth-form" autocomplete="off">
                        <?= csrf_field() ?>
                        <input type="hidden" name="login_akun" value="1">

                        <div class="form-fields">
                            <div class="form-group">
                                <div class="form-label-row">
                                    <label class="form-label" for="usernameInput">Username Petugas</label>
                                </div>
                                <input type="text" 
                                       id="usernameInput" 
                                       name="username" 
                                       class="form-control" 
                                       placeholder="Masukkan username akun petugas" 
                                       autocomplete="off"
                                       autocorrect="off"
                                       autocapitalize="none"
                                       spellcheck="false"
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
                                           autocomplete="new-password"
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
                Bukan petugas lab? <a href="../login.php">Masuk ke Portal Siswa</a>
            </div>
            <div class="foot-back">
                <a href="../index.php">&larr; Kembali ke Katalog Lab</a>
            </div>
        </div>

    </div>

</div>

<!-- Script SweetAlert2, Pembaca RFID & Interaksi Form -->
<script src="../assets/js/sweetalert2.all.min.js"></script>
<script src="../assets/js/rfid.js"></script>
<script>
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

document.addEventListener('DOMContentLoaded', () => {
    const formRfid = document.getElementById('formRfidLogin');
    const formAkun = document.getElementById('formAkunLogin');

    async function handleLoginSubmit(form, processTitle, processText) {
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

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
                window.location.href = data.redirect || 'dashboard.php';
                return;
            } else {
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
                'Sedang membaca dan memvalidasi kartu identitas petugas.'
            );
        });
    }

    if (formAkun) {
        formAkun.addEventListener('submit', (e) => {
            e.preventDefault();
            handleLoginSubmit(
                formAkun,
                'Memproses Masuk...',
                'Sedang memverifikasi data akun petugas.'
            );
        });
    }
});
</script>

</body>
</html>
