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

session_name('sipala_session');
if (session_status() === PHP_SESSION_NONE) {
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
    <title>Pendaftaran Peminjam - SIPALA</title>
    <link rel="icon" type="image/jpeg" href="assets/img/logo.jpg?v=2">
    <link rel="shortcut icon" type="image/jpeg" href="assets/img/logo.jpg?v=2">
    <link rel="apple-touch-icon" href="assets/img/logo.jpg?v=2">
    <style>
        :root {
            --primary: #1f4f82;
            --primary-hover: #173f69;
            --primary-light: #eef4fa;
            --dark: #0f172a;
            --text-main: #334155;
            --text-secondary: #64748b;
            --border: #e2e8f0;
            --white: #ffffff;
            --bg: #f8fafc;
            --danger: #ef4444;
            --danger-bg: #fef2f2;
            --danger-border: #fecaca;
            --danger-text: #b91c1c;
            --success-bg: #ecfdf5;
            --success-border: #a7f3d0;
            --success-text: #065f46;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .register-container {
            width: 100%;
            max-width: 540px;
        }
        .brand-section {
            text-align: center;
            margin-bottom: 24px;
        }
        .brand-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            background-color: var(--primary);
            color: var(--white);
            border-radius: 10px;
            margin-bottom: 12px;
            font-weight: 800;
            font-size: 18px;
        }
        .brand-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--dark);
        }
        .brand-subtitle {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 4px;
        }
        .register-card {
            background-color: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
            padding: 28px;
        }
        .card-header {
            margin-bottom: 20px;
            text-align: center;
        }
        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-main);
        }
        .card-desc {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 4px;
        }
        .alert-error {
            background-color: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger-text);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
            margin-bottom: 18px;
            line-height: 1.4;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 14px;
        }
        .form-group.full {
            grid-column: 1 / -1;
        }
        .form-group label {
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }
        .form-control {
            width: 100%;
            height: 42px;
            padding: 0 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-main);
            background-color: var(--white);
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .form-control:focus {
            border-color: #7fa4c7;
            box-shadow: 0 0 0 3px rgba(31, 79, 130, 0.1);
        }
        textarea.form-control {
            height: 68px;
            padding: 8px 12px;
            resize: vertical;
        }
        .btn-submit {
            width: 100%;
            height: 44px;
            background-color: var(--primary);
            color: var(--white);
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.15s;
            margin-top: 10px;
        }
        .btn-submit:hover {
            background-color: var(--primary-hover);
        }
        .login-footer {
            margin-top: 18px;
            text-align: center;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .login-footer a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }
        .login-footer a:hover {
            text-decoration: underline;
        }
        @media (max-width: 520px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .register-card {
                padding: 20px 16px;
            }
        }
    </style>
</head>
<body>

<div class="register-container">
    <div class="brand-section">
        <div class="brand-badge">SP</div>
        <h1 class="brand-title">SIPALA</h1>
        <p class="brand-subtitle"><?= htmlspecialchars($instansi, ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="register-card">
        <div class="card-header">
            <h2 class="card-title">Pendaftaran Akun Peminjam</h2>
            <p class="card-desc">Lengkapi biodata Anda untuk mulai meminjam alat laboratorium.</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert-error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php">
            <?= csrf_field() ?>

            <div class="form-grid">
                <div class="form-group">
                    <label for="nis">NIS (Nomor Induk Siswa) *</label>
                    <input type="text" id="nis" name="nis" class="form-control" required placeholder="Contoh: 20241001" value="<?= htmlspecialchars($_POST['nis'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="kelas">Kelas / Jurusan *</label>
                    <input type="text" id="kelas" name="kelas" class="form-control" required placeholder="Contoh: XII RPL 1" value="<?= htmlspecialchars($_POST['kelas'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group full">
                    <label for="nama">Nama Lengkap *</label>
                    <input type="text" id="nama" name="nama" class="form-control" required placeholder="Nama lengkap sesuai data sekolah" value="<?= htmlspecialchars($_POST['nama'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="username">Username Akun *</label>
                    <input type="text" id="username" name="username" class="form-control" required placeholder="Username unik untuk login" value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="no_hp">No. WhatsApp / HP</label>
                    <input type="tel" id="no_hp" name="no_hp" class="form-control" placeholder="08xxxxxxxxxx" value="<?= htmlspecialchars($_POST['no_hp'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" class="form-control" required placeholder="Minimal 4 karakter">
                </div>

                <div class="form-group">
                    <label for="password_confirm">Konfirmasi Password *</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-control" required placeholder="Ulangi password">
                </div>

                <div class="form-group full">
                    <label for="alamat">Alamat Tinggal</label>
                    <textarea id="alamat" name="alamat" class="form-control" placeholder="Alamat rumah / domisili"><?= htmlspecialchars($_POST['alamat'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>

            <button type="submit" class="btn-submit">Daftar Akun Sekarang</button>
        </form>

        <div class="login-footer">
            Sudah memiliki akun? <a href="login.php">Masuk di sini</a>
        </div>
    </div>
</div>

</body>
</html>
