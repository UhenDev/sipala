<?php

/**
 * FILE       : register.php
 * MODUL      : Pendaftaran Akun Siswa Mandiri
 * FUNGSI     : Memfasilitasi pendaftaran akun baru bagi siswa/mahasiswa peminjam alat laboratorium
 * AKSES      : Publik / Tamu (Guest)
 *
 * FITUR KEAMANAN:
 * - Anti-CSRF Token: Melindungi manipulasi formulir pendaftaran.
 * - Password Hashing: Menyimpan kata sandi dengan fungsi aman `password_hash($pwd, PASSWORD_BCRYPT)`.
 * - Validasi Unik: Memastikan username dan NIS belum pernah digunakan oleh akun lain.
 *
 * ALUR:
 * 1. Mengaktifkan sesi. Jika sudah login, pengguna dialihkan ke portal utama.
 * 2. Memvalidasi input formulir: nama lengkap, username, NIS, kelas, nomor kontak, dan password.
 * 3. Menyimpan akun baru dengan role default 'peminjam' dan status 'aktif'.
 * 4. Mengalihkan pengguna ke login.php dengan pesan sukses.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_name('sipala_session');
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

// Jika sudah login, arahkan sesuai role
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';
    if (in_array($role, ['admin', 'petugas'], true)) {
        header('Location: admin/dashboard.php');
        exit;
    }
    header('Location: index.php');
    exit;
}

$pdo = db();
$error = '';
$success = '';

// Ambil info nama instansi dari tabel pengaturan
$instansi = 'Laboratorium Sekolah';
try {
    $instansi = (string)($pdo->query("SELECT nilai FROM pengaturan WHERE nama = 'nama_instansi'")->fetchColumn() ?: 'Laboratorium Sekolah');
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Sesi form kedaluwarsa. Silakan muat ulang halaman dan coba lagi.';
    } else {
        $nis = trim((string)($_POST['nis'] ?? ''));
        $nama = trim((string)($_POST['nama'] ?? ''));
        $kelas = trim((string)($_POST['kelas'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $password_confirm = (string)($_POST['password_confirm'] ?? '');
        $no_hp = trim((string)($_POST['no_hp'] ?? ''));
        $alamat = trim((string)($_POST['alamat'] ?? ''));

        if ($nis === '' || $nama === '' || $kelas === '' || $username === '' || $password === '') {
            $error = 'NIS, Nama Lengkap, Kelas, Username, dan Password wajib diisi.';
        } elseif (strlen($password) < 4) {
            $error = 'Password minimal terdiri dari 4 karakter.';
        } elseif ($password !== $password_confirm) {
            $error = 'Konfirmasi password tidak cocok dengan password yang dimasukkan.';
        } else {
            try {
                // Cek keunikan NIS
                $stNis = $pdo->prepare('SELECT id FROM users WHERE nis = ? LIMIT 1');
                $stNis->execute([$nis]);
                if ($stNis->fetch()) {
                    $error = 'NIS ' . htmlspecialchars($nis) . ' sudah terdaftar di sistem. Silakan login atau hubungi admin.';
                } else {
                    // Cek keunikan Username
                    $stUser = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                    $stUser->execute([$username]);
                    if ($stUser->fetch()) {
                        $error = 'Username "' . htmlspecialchars($username) . '" sudah digunakan. Silakan pilih username lain.';
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $ins = $pdo->prepare('
                            INSERT INTO users (nama, nis, username, password, role, kelas, no_hp, alamat, status, created_at)
                            VALUES (?, ?, ?, ?, "peminjam", ?, ?, ?, "aktif", NOW())
                        ');
                        $ins->execute([
                            $nama,
                            $nis,
                            $username,
                            $hash,
                            $kelas,
                            $no_hp !== '' ? $no_hp : null,
                            $alamat !== '' ? $alamat : null
                        ]);
                        $newUserId = (int)$pdo->lastInsertId();

                        // Catat ke audit log
                        audit_log('REGISTER', 'AUTH', $newUserId, "Registrasi akun peminjam baru: $nama ($nis, $kelas)", $newUserId);

                        // Kirim notifikasi selamat datang
                        send_notification($newUserId, 'Selamat Datang di SIPALA!', 'Akun Anda berhasil didaftarkan. Anda kini dapat meminjam alat laboratorium langsung dari aplikasi.', 'success', 'index.php');

                        // Kirim notifikasi ke laboran
                        notify_all_admins('Peminjam Baru Terdaftar', "Siswa baru mendaftar: $nama ($nis - $kelas)", 'info', 'pengguna/index.php');

                        $_SESSION['login_success'] = 'Pendaftaran akun berhasil! Silakan masuk menggunakan username dan password Anda.';
                        header('Location: login.php');
                        exit;
                    }
                }
            } catch (Throwable $e) {
                error_log('Register error: ' . $e->getMessage());
                $error = 'Terjadi kendala sistem saat memproses pendaftaran. Silakan coba kembali beberapa saat lagi.';
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
    <title>Daftar Akun Siswa - SIPALA Lab Komputer RPL</title>
    <link rel="icon" type="image/jpeg" href="assets/img/logo.jpg?v=2">
    <link rel="shortcut icon" type="image/jpeg" href="assets/img/logo.jpg?v=2">
    <link rel="apple-touch-icon" href="assets/img/logo.jpg?v=2">
    
    <!-- Font Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #1e40af;
            --primary-hover: #1d4ed8;
            --navy-dark: #0f172a;
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
            min-height: 640px;
        }

        /* Panel Kiri: Identitas Resmi Lab RPL dengan Foto Background */
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
                    rgba(15, 23, 42, 0.85) 0%,
                    rgba(15, 23, 42, 0.25) 35%,
                    rgba(15, 23, 42, 0.25) 60%,
                    rgba(15, 23, 42, 0.90) 100%
                ),
                url('assets/img/login-bg.jpg') center 25% / cover no-repeat;
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

        .brand-title p {
            font-size: 13px;
            color: #cbd5e1;
            margin-top: 3px;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
        }

        .brand-body {
            margin: auto 0;
            padding: 24px 0;
        }

        .brand-badge-siswa {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            background: rgba(30, 64, 175, 0.6);
            border: 1px solid rgba(147, 197, 253, 0.3);
            border-radius: 7px;
            font-size: 11.5px;
            font-weight: 600;
            color: #dbeafe;
            margin-bottom: 12px;
        }

        .brand-body p {
            font-size: 13.5px;
            color: #f1f5f9;
            line-height: 1.65;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.6);
            background: rgba(15, 23, 42, 0.5);
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

        /* Panel Kanan: Area Form Pendaftaran Siswa */
        .form-panel {
            flex: 1;
            padding: 44px 44px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: #ffffff;
        }

        .form-head {
            margin-bottom: 22px;
        }

        .portal-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
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

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 0;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-title);
            margin-bottom: 6px;
        }

        .form-label span.req {
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            height: 44px;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            font-family: inherit;
            font-size: 13.5px;
            color: var(--text-title);
            background: #ffffff;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.12);
        }

        textarea.form-control {
            height: 52px;
            min-height: 52px;
            padding: 10px 14px;
            resize: vertical;
            line-height: 1.4;
        }

        .password-wrap {
            position: relative;
            width: 100%;
        }

        .password-wrap .form-control {
            padding-right: 44px;
        }

        .btn-eye-toggle {
            position: absolute;
            right: 0;
            top: 0;
            height: 100%;
            width: 44px;
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

        .form-footer {
            margin-top: 24px;
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

        @media (max-width: 900px) {
            .login-card {
                flex-direction: column;
                max-width: 520px;
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
                padding: 30px 22px;
            }
            .form-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .form-group.full {
                grid-column: 1;
            }
            .btn-submit {
                margin-top: 16px;
            }
        }
    </style>
</head>
<body>

<div class="login-card">

    <!-- PANEL KIRI: FOTO ASLI LAB KOMPUTER RPL -->
    <div class="brand-panel">
        
        <div class="brand-header">
            <img src="assets/img/logo.jpg" alt="Logo SIPALA" class="brand-logo">
            <div class="brand-title">
                <h1>Sistem Informasi Peminjaman Alat</h1>
            </div>
        </div>

        <div class="brand-body">
            <div class="brand-badge-siswa">
                <span>👤 Portal Siswa / Peminjam</span>
            </div>
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

    <!-- PANEL KANAN: FORM REGISTRASI SISWA -->
    <div class="form-panel">
        
        <div>
            <div class="form-head">
                <span class="portal-badge">Peminjam Alat</span>
                <h2>Daftar Akun Siswa</h2>
                <p>Lengkapi data Anda untuk mulai meminjam alat laboratorium.</p>
            </div>

            <!-- Pesan Kesalahan Validasi -->
            <?php if ($error !== ''): ?>
                <div class="alert-error" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php" id="formRegister" autocomplete="off">
                <?= csrf_field() ?>

                <div class="form-grid">
                    
                    <!-- Baris 1: NIS & Kelas -->
                    <div class="form-group">
                        <label class="form-label" for="nis">NIS (Nomor Induk Siswa) <span class="req">*</span></label>
                        <input type="text" 
                               id="nis" 
                               name="nis" 
                               class="form-control" 
                               required 
                               placeholder="Contoh: 20241001" 
                               value="<?= htmlspecialchars($_POST['nis'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="kelas">Kelas / Jurusan <span class="req">*</span></label>
                        <input type="text" 
                               id="kelas" 
                               name="kelas" 
                               class="form-control" 
                               required 
                               placeholder="Contoh: XII RPL 1" 
                               value="<?= htmlspecialchars($_POST['kelas'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="off">
                    </div>

                    <!-- Baris 2: Nama Lengkap (Lebar Penuh) -->
                    <div class="form-group full">
                        <label class="form-label" for="nama">Nama Lengkap <span class="req">*</span></label>
                        <input type="text" 
                               id="nama" 
                               name="nama" 
                               class="form-control" 
                               required 
                               placeholder="Nama lengkap sesuai data siswa sekolah" 
                               value="<?= htmlspecialchars($_POST['nama'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="off">
                    </div>

                    <!-- Baris 3: Username & Nomor WhatsApp/HP -->
                    <div class="form-group">
                        <label class="form-label" for="username">Username Akun <span class="req">*</span></label>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               class="form-control" 
                               required 
                               placeholder="Username unik untuk login" 
                               value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="no_hp">No. WhatsApp / HP</label>
                        <input type="tel" 
                               id="no_hp" 
                               name="no_hp" 
                               class="form-control" 
                               placeholder="Contoh: 08xxxxxxxxxx" 
                               value="<?= htmlspecialchars($_POST['no_hp'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="off">
                    </div>

                    <!-- Baris 4: Password & Konfirmasi Password -->
                    <div class="form-group">
                        <label class="form-label" for="passwordInput">Password <span class="req">*</span></label>
                        <div class="password-wrap">
                            <input type="password" 
                                   id="passwordInput" 
                                   name="password" 
                                   class="form-control" 
                                   required 
                                   placeholder="Minimal 4 karakter"
                                   autocomplete="new-password">
                            <button type="button" 
                                    class="btn-eye-toggle" 
                                    id="pwdToggleBtn1" 
                                    onclick="togglePassword('passwordInput', 'eyeOpen1', 'eyeClosed1', 'pwdToggleBtn1')" 
                                    title="Tampilkan password" 
                                    aria-label="Tampilkan atau sembunyikan password">
                                <svg id="eyeOpen1" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                <svg id="eyeClosed1" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                    <line x1="1" y1="1" x2="23" y2="23"></line>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="passwordConfirmInput">Konfirmasi Password <span class="req">*</span></label>
                        <div class="password-wrap">
                            <input type="password" 
                                   id="passwordConfirmInput" 
                                   name="password_confirm" 
                                   class="form-control" 
                                   required 
                                   placeholder="Ketik ulang password"
                                   autocomplete="new-password">
                            <button type="button" 
                                    class="btn-eye-toggle" 
                                    id="pwdToggleBtn2" 
                                    onclick="togglePassword('passwordConfirmInput', 'eyeOpen2', 'eyeClosed2', 'pwdToggleBtn2')" 
                                    title="Tampilkan konfirmasi password" 
                                    aria-label="Tampilkan atau sembunyikan konfirmasi password">
                                <svg id="eyeOpen2" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                <svg id="eyeClosed2" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                    <line x1="1" y1="1" x2="23" y2="23"></line>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Baris 5: Alamat Tinggal (Lebar Penuh) -->
                    <div class="form-group full">
                        <label class="form-label" for="alamat">Alamat Tinggal</label>
                        <textarea id="alamat" 
                                  name="alamat" 
                                  class="form-control" 
                                  placeholder="Alamat rumah / domisili tempat tinggal"><?= htmlspecialchars($_POST['alamat'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                </div>

                <button type="submit" class="btn-submit" id="btnRegisterSubmit">
                    Daftar Akun Sekarang
                </button>
            </form>
        </div>

        <!-- Footer Navigasi Bawah -->
        <div class="form-footer">
            <div>
                Sudah memiliki akun? <a href="login.php">Masuk di sini</a>
            </div>
            <div class="foot-back">
                <a href="index.php">&larr; Kembali ke Katalog Lab</a>
            </div>
        </div>

    </div>

</div>

<script>
function togglePassword(inputId, openIconId, closedIconId, btnId) {
    const input = document.getElementById(inputId);
    const eyeOpen = document.getElementById(openIconId);
    const eyeClosed = document.getElementById(closedIconId);
    const btn = document.getElementById(btnId);
    if (!input) return;

    if (input.type === 'password') {
        input.type = 'text';
        if (eyeOpen) eyeOpen.style.display = 'none';
        if (eyeClosed) eyeClosed.style.display = 'block';
        if (btn) btn.title = 'Sembunyikan password';
    } else {
        input.type = 'password';
        if (eyeOpen) eyeOpen.style.display = 'block';
        if (eyeClosed) eyeClosed.style.display = 'none';
        if (btn) btn.title = 'Tampilkan password';
    }
}
</script>

</body>
</html>
