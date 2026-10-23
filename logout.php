<?php

/**
 * FILE       : logout.php
 * MODUL      : Penghentian Sesi Pengguna
 * FUNGSI     : Menghancurkan seluruh variabel sesi pengguna, menghapus cookie browser, dan mengalihkan ke halaman login
 * AKSES      : Seluruh Pengguna yang Sedang Login
 *
 * ALUR:
 * 1. Mengaktifkan sesi `sipala_session`.
 * 2. Mengosongkan array global $_SESSION.
 * 3. Menghapus cookie sesi dari peramban dengan waktu kedaluwarsa lampau.
 * 4. Menghancurkan sesi server melalui session_destroy().
 * 5. Mengalihkan pengguna kembali ke login.php.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_name('sipala_session');
    session_start();
}

/*
|--------------------------------------------------------------------------
| Hapus semua data session
|--------------------------------------------------------------------------
*/

$_SESSION = [];

/*
|--------------------------------------------------------------------------
| Hapus cookie session jika digunakan
|--------------------------------------------------------------------------
*/

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

/*
|--------------------------------------------------------------------------
| Hancurkan session
|--------------------------------------------------------------------------
*/

session_destroy();

/*
|--------------------------------------------------------------------------
| Arahkan kembali ke halaman login
|--------------------------------------------------------------------------
*/

header('Location: login.php');
exit;