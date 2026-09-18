<?php

/**
 * FILE       : includes/csrf.php
 * MODUL      : Keamanan Sistem (Security Layer)
 * FUNGSI     : Perlindungan terhadap serangan Cross-Site Request Forgery (CSRF)
 * AKSES      : Seluruh Formulir POST Aplikasi
 *
 * ALUR:
 * 1. Memastikan sesi PHP telah aktif.
 * 2. Menghasilkan token acak kriptografis 32-byte unik per sesi pengguna.
 * 3. Menyediakan helper input HTML tersembunyi (csrf_field) untuk seluruh formulir POST.
 * 4. Memvalidasi kecocokan token menggunakan fungsi timing-attack-safe (hash_equals).
 * 5. Menolak request dan menghentikan eksekusi jika token tidak cocok atau kedaluwarsa.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    if (session_name() !== 'sipala_session') {
        session_name('sipala_session');
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
               (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/**
 * Mendapatkan token CSRF saat ini dari session (atau membuat baru jika belum ada).
 *
 * @return string Token CSRF heksadesimal 64 karakter
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Menghasilkan elemen input HTML tersembunyi untuk form POST
 */
function csrf_field(): string
{
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Memvalidasi token CSRF dari request POST
 */
function verify_csrf(): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }

    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Memvalidasi CSRF atau menghentikan eksekusi dengan pesan kesalahan
 */
function require_csrf(): void
{
    if (!verify_csrf()) {
        if (function_exists('flash')) {
            flash('error', 'Permintaan kedaluwarsa atau token keamanan CSRF tidak valid. Silakan coba lagi.');
        }
        if (function_exists('redirect')) {
            redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
        }
        http_response_code(403);
        exit('Token keamanan (CSRF) tidak valid atau sesi telah berakhir. Silakan kembali dan muat ulang halaman.');
    }
}
