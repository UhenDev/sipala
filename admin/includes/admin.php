<?php

/**
 * FILE       : admin/includes/admin.php
 * MODUL      : Kerangka Layout & Middleware Autentikasi Admin
 * FUNGSI     : Memverifikasi hak akses pengguna admin/petugas, inisialisasi sesi, dan merender header/sidebar back-office
 * AKSES      : Seluruh Halaman Back-Office (admin/*)
 *
 * FITUR KEAMANAN:
 * - Session Hijacking Prevention: Menggunakan session_name('sipala_session') terisolasi.
 * - Authorization Check: Memeriksa role pengguna di backend. Jika bukan admin/petugas, seketika dialihkan ke login.php.
 * - XSS Protection: Menyediakan helper e() untuk sanitasi output HTML.
 *
 * ALUR:
 * 1. Mengaktifkan sesi dan memuat dependensi konfigurasi database, CSRF, dan helper functions.
 * 2. Memvalidasi keberadaan $_SESSION['user_id'] dan role pengguna.
 * 3. Menyediakan fungsi page_start() dan page_end() sebagai kerangka layout terpadu antarmuka back-office.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Menentukan base URL proyek secara dinamis untuk mencegah redirect 404
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$posAdmin = strpos($scriptDir, '/admin');
$baseUrl = ($posAdmin !== false) ? substr($scriptDir, 0, $posAdmin) : '';
$loginUrl = ($baseUrl !== '' ? $baseUrl : '') . '/login.php';

// Memastikan pengguna telah terautentikasi dan memiliki peranan admin atau petugas
if (!isset($_SESSION['user_id'])) { 
    header('Location: ' . $loginUrl); 
    exit; 
}
if (!in_array($_SESSION['role'] ?? '', ['admin','petugas'], true)) { 
    header('Location: ' . $loginUrl); 
    exit; 
}

$pdo = db();

if (!function_exists('e')) {
    function e(mixed $v): string { 
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); 
    }
}

if (!function_exists('flash')) {
    function flash(string $type, string $msg): void { 
        $_SESSION['flash_type'] = $type; 
        $_SESSION['flash_msg'] = $msg; 
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): never { 
        header('Location: ' . $url); 
        exit; 
    }
}

if (!function_exists('require_admin')) {
    function require_admin(): void {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            flash('error', 'Akses ditolak: Fitur ini hanya dapat diakses oleh Administrator.');
            $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
            $posAdmin = strpos($scriptDir, '/admin');
            $baseUrl = ($posAdmin !== false) ? substr($scriptDir, 0, $posAdmin) : '';
            redirect(($baseUrl !== '' ? $baseUrl : '') . '/admin/dashboard.php');
        }
    }
}

function flash_html(): string { 
    $m = $_SESSION['flash_msg'] ?? ''; 
    $t = $_SESSION['flash_type'] ?? 'success'; 
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']); 
    if ($m === '') return '';
    return '<script>document.addEventListener("DOMContentLoaded", function(){ if(window.showToast) showToast("' . e($t) . '", "' . e(addslashes($m)) . '"); });</script>' .
           '<div class="alert ' . e($t) . '" id="flash">' . e($m) . '<button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;float:right;font-size:16px;line-height:1;">&times;</button></div>'; 
}

function page_start(string $title, string $subtitle, string $active): void { 
    global $pdo; 
    $adminPrefix = (basename(dirname($_SERVER['SCRIPT_NAME'])) === 'admin') ? '' : '../'; 
    $name = (string)($_SESSION['nama'] ?? $_SESSION['username'] ?? 'Pengguna'); 
    $role = (string)($_SESSION['role'] ?? ''); 
    $initial = strtoupper(substr($name, 0, 1));

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $posAdmin = strpos($scriptDir, '/admin');
    $baseUrl = ($posAdmin !== false) ? substr($scriptDir, 0, $posAdmin) : '';
    $faviconUrl = ($baseUrl !== '' ? $baseUrl : '') . '/assets/img/logo.jpg?v=2'; 

    $userFoto = '';
    if (isset($_SESSION['user_id'])) {
        try {
            $stMe = $pdo->prepare("SELECT foto FROM users WHERE id = ?");
            $stMe->execute([(int)$_SESSION['user_id']]);
            $userFoto = (string)($stMe->fetchColumn() ?: '');
            $_SESSION['foto'] = $userFoto;
        } catch (Throwable $e) {}
    }
?>
<!doctype html>
<html lang="id">
<head>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - SIPALA</title>
    <link rel="icon" type="image/jpeg" href="<?= e($faviconUrl) ?>">
    <link rel="shortcut icon" type="image/jpeg" href="<?= e($faviconUrl) ?>">
    <link rel="apple-touch-icon" href="<?= e($faviconUrl) ?>">
    <link rel="stylesheet" href="<?= e($adminPrefix) ?>../assets/css/rfid.css">
    <link rel="stylesheet" href="<?= e($adminPrefix) ?>../assets/css/image-cropper-modal.css">
    <link rel="stylesheet" href="<?= e($adminPrefix) ?>../assets/css/qr-modal.css">
    <link rel="stylesheet" href="<?= e($adminPrefix) ?>../assets/css/wa-modal.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: Arial, Helvetica, sans-serif; 
            background: #f4f6f8; 
            color: #1f2937; 
            overflow-x: hidden;
        }
        a { text-decoration: none; color: inherit; }

        /* =========================================================
           SIDEBAR (Responsive Off-Canvas Drawer)
        ========================================================= */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100vh;
            background: #ffffff;
            border-right: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            z-index: 1000;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }


        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(2px);
            z-index: 990;
            display: none;
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .sidebar-overlay.active {
            display: block;
            opacity: 1;
        }

        .sidebar-close-btn {
            display: none;
            background: none;
            border: none;
            font-size: 26px;
            line-height: 1;
            color: #64748b;
            cursor: pointer;
            padding: 4px 8px;
            margin-left: auto;
            border-radius: 6px;
        }

        .sidebar-close-btn:hover {
            color: #0f172a;
            background: #f1f5f9;
        }

        .sidebar-toggle-btn {
            display: none;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            cursor: pointer;
            font-size: 20px;
            flex-shrink: 0;
            transition: all 0.15s ease;
        }

        .sidebar-toggle-btn:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .brand {
            height: 76px;
            padding: 0 20px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid #eef0f2;
        }

        .brand-mark {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: #1f4f82;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 15px;
            margin-right: 11px;
            flex-shrink: 0;
        }

        .brand-text strong {
            display: block;
            font-size: 18px;
            color: #172033;
            letter-spacing: .3px;
        }

        .brand-text span {
            display: block;
            font-size: 11px;
            color: #7b8492;
            margin-top: 3px;
        }

        .menu {
            padding: 18px 12px;
            flex: 1;
            overflow-y: auto;
        }

        .menu-title {
            padding: 0 12px;
            font-size: 10px;
            font-weight: bold;
            color: #9aa1ad;
            text-transform: uppercase;
            letter-spacing: .8px;
            margin: 5px 0 10px;
        }

        .menu-item {
            width: 100%;
            min-height: 44px;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 0 13px;
            margin-bottom: 4px;
            border-radius: 7px;
            color: #596273;
            font-size: 14px;
            border: none;
            background: transparent;
            cursor: pointer;
            text-align: left;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .menu-item:hover {
            background: #f2f5f8;
            color: #1f4f82;
        }

        .menu-item.active {
            background: #eaf1f8;
            color: #1f4f82;
            font-weight: 600;
        }

        .menu-icon {
            width: 21px;
            text-align: center;
            font-size: 17px;
        }

        .menu-item.logout {
            color: #a54848;
        }

        .menu-item.logout:hover {
            background: #fdf0f0;
            color: #a54848;
        }

        .sidebar-bottom {
            padding: 13px 12px;
            border-top: 1px solid #eef0f2;
        }

        /* =========================================================
           MAIN
        ========================================================= */

        .main { 
            margin-left: 250px; 
            min-height: 100vh; 
            display: flex; 
            flex-direction: column; 
            width: calc(100% - 250px);
            min-width: 0;
            transition: margin-left 0.3s ease, width 0.3s ease;
        }
        
        .topbar { 
            height: 76px; 
            background: #ffffff; 
            border-bottom: 1px solid #e5e7eb; 
            padding: 0 28px; 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            position: sticky; 
            top: 0; 
            z-index: 90; 
            gap: 16px;
        }
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }
        .topbar-title {
            min-width: 0;
        }
        .topbar h1 { 
            margin: 0; 
            font-size: 20px; 
            font-weight: 700; 
            color: #172033;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .topbar p { 
            margin: 2px 0 0; 
            font-size: 12px; 
            color: #7b8492; 
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .user { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            flex-shrink: 0;
        }
        .avatar { 
            width: 38px; 
            height: 38px; 
            border-radius: 50%; 
            background: #eaf1f8; 
            color: #1f4f82; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: bold; 
            font-size: 15px; 
            flex-shrink: 0;
        }
        .u-name { font-weight: 600; font-size: 14px; color: #172033; }
        .u-role { font-size: 12px; color: #7b8492; text-transform: capitalize; }
        
        .content { padding: 26px 28px; flex: 1; min-width: 0; }
        .head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; gap: 16px; flex-wrap: wrap; }
        .head h2 { margin: 0; font-size: 20px; color: #172033; font-weight: 700; }
        .head p { margin: 4px 0 0; font-size: 13px; color: #7b8492; }
        
        .panel { background: #ffffff; border-radius: 10px; border: 1px solid #e3e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.04); overflow: hidden; margin-bottom: 22px; }
        .panel-title { padding: 16px 20px; font-size: 15px; font-weight: 600; color: #172033; border-bottom: 1px solid #edf0f2; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%; }
        table { width: 100%; min-width: 600px; border-collapse: collapse; font-size: 13px; text-align: left; }
        th { background: #f8fafc; color: #596273; font-weight: 600; padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 12px; white-space: nowrap; }
        td { padding: 13px 16px; border-bottom: 1px solid #edf0f2; color: #374151; vertical-align: middle; }
        tr:hover td { background: #f8fafc; }
        
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid #dfe3e8; background: #ffffff; color: #475569; transition: all 0.15s ease; text-decoration: none; line-height: 1.3; min-height: 38px; }
        .btn:hover { background: #f1f5f9; border-color: #cbd5e1; color: #1e293b; }
        .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 5px; min-height: 32px; }
        .btn-primary { background: #1769AA; color: #ffffff; border-color: #1769AA; }
        .btn-primary:hover { background: #125488; border-color: #125488; color: #ffffff; }
        .btn-edit { background: #f0f7ff; color: #1769AA; border-color: #bae0ff; }
        .btn-edit:hover { background: #1769AA; color: #ffffff; border-color: #1769AA; }
        .btn-ok { background: #ecfdf5; color: #059669; border-color: #a7f3d0; }
        .btn-ok:hover { background: #059669; color: #ffffff; border-color: #059669; }
        .btn-danger { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .btn-danger:hover { background: #dc2626; color: #ffffff; border-color: #dc2626; }
        .actions { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        
        .badge { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
        .badge.MENUNGGU { background: #fef3c7; color: #b45309; }
        .badge.DISETUJUI { background: #e0f2fe; color: #0369a1; }
        .badge.DIPINJAM { background: #dbeafe; color: #1d4ed8; }
        .badge.SELESAI { background: #dcfce7; color: #15803d; }
        .badge.DITOLAK { background: #fee2e2; color: #b91c1c; }
        .badge.aktif { background: #dcfce7; color: #15803d; }
        .badge.nonaktif { background: #f1f5f9; color: #64748b; }
        .badge.admin { background: #ede9fe; color: #6d28d9; }
        .badge.petugas { background: #e0f2fe; color: #0369a1; }
        .badge.peminjam { background: #fef3c7; color: #b45309; }
        
        .alert { padding: 13px 16px; border-radius: 6px; font-size: 13px; font-weight: 500; margin-bottom: 20px; }
        .alert.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert.error { background: #fdf0f0; color: #a54848; border: 1px solid #fecaca; }
        
        .form-panel { background: #ffffff; border-radius: 10px; border: 1px solid #e3e7eb; padding: 24px; margin-bottom: 22px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
        .field { display: flex; flex-direction: column; gap: 6px; }
        .field.full { grid-column: 1 / -1; }
        .field label { font-size: 12px; font-weight: 600; color: #374151; }
        .field input, .field select, .field textarea { padding: 10px 12px; border: 1px solid #dfe3e8; border-radius: 6px; font-size: 13px; color: #172033; outline: none; background: #ffffff; font-family: inherit; }
        .field input:focus, .field select:focus, .field textarea:focus { border-color: #7fa4c7; box-shadow: 0 0 0 3px rgba(31,79,130,.08); }
        .field input[readonly] { background: #f8fafc; color: #64748b; cursor: not-allowed; }
        .form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; padding-top: 16px; border-top: 1px solid #edf0f2; flex-wrap: wrap; }
        .actions { display: flex; gap: 6px; align-items: center; }
        .empty { padding: 32px; text-align: center; color: #7b8492; font-style: italic; }
        
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 22px; }
        .stat { background: #ffffff; padding: 18px; border-radius: 8px; border: 1px solid #e3e7eb; }
        .stat small { display: block; font-size: 12px; color: #7b8492; margin-bottom: 5px; }
        .stat strong { font-size: 22px; color: #172033; font-weight: 700; }
        
        .modal { position: fixed; inset: 0; background: rgba(15,23,42,0.5); backdrop-filter: blur(2px); display: none; align-items: center; justify-content: center; z-index: 1050; padding: 16px; }
        .modal.show { display: flex; }
        .modal-box { background: #ffffff; border-radius: 10px; max-width: 480px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 24px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); border: 1px solid #e3e7eb; }
        .modal-box h3 { margin: 0 0 8px; font-size: 17px; color: #172033; }
        .modal-box p { margin: 0 0 18px; font-size: 13px; color: #7b8492; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; flex-wrap: wrap; }

        /* =========================================================
           RESPONSIVE BREAKPOINTS (Mobile & Tablet)
        ========================================================= */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            }
            .sidebar.open {
                transform: translateX(0);
            }
            .sidebar-close-btn {
                display: block;
            }
            .sidebar-toggle-btn {
                display: inline-flex;
            }
            .main {
                margin-left: 0;
                width: 100%;
            }
            .topbar {
                padding: 0 16px;
                height: 68px;
            }
            .content {
                padding: 18px 16px;
            }
        }

        @media (max-width: 640px) {
            .topbar {
                padding: 0 14px;
                height: 64px;
            }
            .topbar h1 {
                font-size: 17px;
            }
            .topbar p {
                display: none;
            }
            .user .u-role {
                display: none;
            }
            .content {
                padding: 14px;
            }
            .head {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }
            .head h2 {
                font-size: 18px;
            }
            .head .btn {
                width: 100%;
            }
            .stats {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .stat {
                padding: 14px;
            }
            .stat strong {
                font-size: 19px;
            }
            .form-panel {
                padding: 16px;
            }
            .grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .form-actions {
                flex-direction: column-reverse;
            }
            .form-actions .btn {
                width: 100%;
            }
            .modal-box {
                padding: 18px;
                max-width: 96vw;
            }
            .modal-actions {
                flex-direction: column-reverse;
            }
            .modal-actions .btn {
                width: 100%;
            }
        }

        @media (max-width: 420px) {
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <div class="brand-mark">SP</div>
        <div class="brand-text">
            <strong>SIPALA</strong>
            <span>Laboratorium Sekolah</span>
        </div>
        <button type="button" class="sidebar-close-btn" id="sidebarClose" aria-label="Tutup Menu">&times;</button>
    </div>
    <nav class="menu">
        <div class="menu-title">Operasional</div>
        <a class="menu-item <?= $active==='dashboard'?'active':''?>" href="<?= e($adminPrefix) ?>dashboard.php">
            <span class="menu-icon">&#x2302;</span><span>Dashboard</span>
        </a>
        <a class="menu-item <?= $active==='peminjaman'?'active':''?>" href="<?= e($adminPrefix) ?>peminjaman/index.php">
            <span class="menu-icon">&#x21c4;</span><span><?= $role === 'petugas' ? 'Menyetujui Peminjaman' : 'Data Peminjaman' ?></span>
        </a>
        <a class="menu-item <?= $active==='pengembalian'?'active':''?>" href="<?= e($adminPrefix) ?>pengembalian/index.php">
            <span class="menu-icon">&#x21a9;</span><span><?= $role === 'petugas' ? 'Memantau Pengembalian' : 'Data Pengembalian' ?></span>
        </a>
        <a class="menu-item <?= $active==='laporan'?'active':''?>" href="<?= e($adminPrefix) ?>laporan/index.php">
            <span class="menu-icon">&#x25a7;</span><span><?= $role === 'petugas' ? 'Mencetak Laporan' : 'Cetak Laporan' ?></span>
        </a>

        <?php if ($role === 'admin'): ?>
        <div class="menu-title" style="margin-top:14px;">Master Inventaris</div>
        <a class="menu-item <?= $active==='peralatan'?'active':''?>" href="<?= e($adminPrefix) ?>peralatan/index.php">
            <span class="menu-icon">&#x25a3;</span><span>CRUD Alat</span>
        </a>
        <a class="menu-item <?= $active==='kategori'?'active':''?>" href="<?= e($adminPrefix) ?>kategori/index.php">
            <span class="menu-icon">&#x25a4;</span><span>CRUD Kategori</span>
        </a>
        <a class="menu-item <?= $active==='unit'?'active':''?>" href="<?= e($adminPrefix) ?>unit/index.php">
            <span class="menu-icon">&#x25a6;</span><span>Unit Alat Fisik</span>
        </a>
        <a class="menu-item <?= $active==='rfid'?'active':''?>" href="<?= e($adminPrefix) ?>rfid/index.php">
            <span class="menu-icon">&#x25ce;</span><span>Kartu RFID</span>
        </a>

        <div class="menu-title" style="margin-top:14px;">Sistem & Otoritas</div>
        <a class="menu-item <?= $active==='pengguna'?'active':''?>" href="<?= e($adminPrefix) ?>pengguna/index.php">
            <span class="menu-icon">&#x2659;</span><span>CRUD User</span>
        </a>
        <a class="menu-item <?= $active==='log'?'active':''?>" href="<?= e($adminPrefix) ?>log/index.php">
            <span class="menu-icon">&#x25a5;</span><span>Log Aktifitas</span>
        </a>
        <a class="menu-item <?= $active==='pengaturan'?'active':''?>" href="<?= e($adminPrefix) ?>pengaturan.php">
            <span class="menu-icon">&#x2699;</span><span>Pengaturan Lab</span>
        </a>
        <?php endif; ?>

        <div class="menu-title" style="margin-top:14px;">Akun</div>
        <a class="menu-item <?= $active==='profil'?'active':''?>" href="<?= e($adminPrefix) ?>../profil.php">
            <span class="menu-icon">&#x263a;</span><span>Profil Saya</span>
        </a>
    </nav>
    <div class="sidebar-bottom">
        <button type="button" class="menu-item logout" id="logoutButton" data-logout-url="<?= e($adminPrefix) ?>../logout.php">
            <span class="menu-icon">&#x21aa;</span><span>Keluar</span>
        </button>
    </div>
</aside>

<?php
$unreadNotif = 0;
$latestNotifs = [];
if (isset($_SESSION['user_id'])) {
    try {
        $stN = $pdo->prepare("SELECT COUNT(*) FROM notifikasi WHERE user_id = ? AND is_read = 0");
        $stN->execute([(int)$_SESSION['user_id']]);
        $unreadNotif = (int)$stN->fetchColumn();

        $stListN = $pdo->prepare("SELECT id, judul, pesan, tipe, is_read, created_at FROM notifikasi WHERE user_id = ? ORDER BY id DESC LIMIT 5");
        $stListN->execute([(int)$_SESSION['user_id']]);
        $latestNotifs = $stListN->fetchAll();
    } catch (Throwable $e) {}
}
?>

<main class="main">
    <header class="topbar">
        <div class="topbar-left">
            <button type="button" class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Buka Menu">&#9776;</button>
            <div class="topbar-title">
                <h1><?= e($title) ?></h1>
                <p><?= e($subtitle) ?></p>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="position:relative;">
                <button type="button" id="notifBellBtn" style="position:relative;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;width:38px;height:38px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px;" title="Notifikasi">
                    &#128276;
                    <?php if ($unreadNotif > 0): ?>
                        <span id="notifBadge" style="position:absolute;top:-4px;right:-4px;background:#ef4444;color:#ffffff;font-size:10px;font-weight:700;border-radius:10px;padding:1px 5px;line-height:1.2;"><?= $unreadNotif ?></span>
                    <?php endif; ?>
                </button>
                <div id="notifDropdown" style="display:none;position:absolute;right:0;top:46px;width:320px;background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,0.1);z-index:1100;overflow:hidden;">
                    <div style="padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:700;color:#1e293b;">
                        <span>Notifikasi Internal</span>
                        <span id="notifUnreadText" style="font-size:11px;font-weight:600;color:#64748b;"><?= $unreadNotif ?> baru</span>
                    </div>
                    <div style="max-height:260px;overflow-y:auto;">
                        <?php if (empty($latestNotifs)): ?>
                            <div style="padding:20px;text-align:center;font-size:12px;color:#94a3b8;font-style:italic;">Tidak ada notifikasi baru</div>
                        <?php else: ?>
                            <?php foreach ($latestNotifs as $nt): ?>
                                <div class="notif-item" style="padding:10px 14px;border-bottom:1px solid #f1f5f9;font-size:12px;background:<?= $nt['is_read'] ? '#ffffff' : '#f0f9ff' ?>;">
                                    <strong style="display:block;color:#0f172a;margin-bottom:2px;"><?= e($nt['judul']) ?></strong>
                                    <span style="display:block;color:#475569;line-height:1.3;"><?= e($nt['pesan']) ?></span>
                                    <small style="display:block;color:#94a3b8;margin-top:4px;font-size:10px;"><?= e($nt['created_at']) ?></small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <a href="<?= e($adminPrefix) ?>../profil.php" class="user" style="text-decoration:none;color:inherit;cursor:pointer;" title="Klik untuk mengedit profil Anda">
                <?php if (!empty($userFoto) && file_exists(__DIR__ . '/../../' . $userFoto)): ?>
                    <img src="<?= e($adminPrefix) ?>../<?= e($userFoto) ?>" alt="<?= e($name) ?>" class="sipala-zoomable-img"
                         data-lightbox-src="<?= e($adminPrefix) ?>../<?= e($userFoto) ?>"
                         data-lightbox-title="<?= e($name) ?>"
                         data-lightbox-sub="Akun <?= ucfirst(e($role)) ?>"
                         data-lightbox-badge="Foto Profil Admin"
                         style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid #cbd5e1;flex-shrink:0;">
                <?php else: ?>
                    <div class="avatar"><?= e($initial) ?></div>
                <?php endif; ?>
                <div>
                    <div class="u-name"><?= e($name) ?></div>
                    <div class="u-role"><?= e($role) ?></div>
                </div>
            </a>
        </div>
    </header>
    <section class="content">
        <?= flash_html() ?>
<?php 
}

function page_end(): void { 
    $adminPrefix = (basename(dirname($_SERVER['SCRIPT_NAME'])) === 'admin') ? '' : '../'; 
    $logoutPath = $adminPrefix . '../logout.php'; 
?>
    </section>
</main>

<script src="<?= e($adminPrefix) ?>../assets/js/sweetalert2.all.min.js"></script>
<script src="<?= e($adminPrefix) ?>../assets/js/alerts.js"></script>
<script src="<?= e($adminPrefix) ?>../assets/js/rfid.js"></script>
<script src="<?= e($adminPrefix) ?>../assets/js/image-cropper-modal.js"></script>
<?php if (!empty($_SESSION['login_success'])): 
    $flashSuccess = $_SESSION['login_success'];
    unset($_SESSION['login_success']);
?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    if (typeof showToast === 'function') {
        showToast('success', <?= json_encode($flashSuccess, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);
    } else if (window.Swal) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: <?= json_encode($flashSuccess, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            showConfirmButton: false,
            timer: 3500,
            timerProgressBar: true
        });
    }
});
</script>
<?php endif; ?>
<script>
(function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('sidebarToggle');
    const closeBtn = document.getElementById('sidebarClose');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (overlay) overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    const notifBtn = document.getElementById('notifBellBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBadge = document.getElementById('notifBadge');
    const notifUnreadText = document.getElementById('notifUnreadText');
    let hasMarkedRead = false;

    if (notifBtn && notifDropdown) {
        notifBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpening = (notifDropdown.style.display === 'none' || notifDropdown.style.display === '');
            notifDropdown.style.display = isOpening ? 'block' : 'none';

            if (isOpening && !hasMarkedRead) {
                // 1. Langsung hilangkan angka badge notifikasi di UI
                if (notifBadge) {
                    notifBadge.style.display = 'none';
                }
                if (notifUnreadText) {
                    notifUnreadText.textContent = '0 baru';
                }

                // Ubah latar item notifikasi yang belum dibaca menjadi putih
                const unreadItems = notifDropdown.querySelectorAll('.notif-item');
                unreadItems.forEach(function(item) {
                    item.style.backgroundColor = '#ffffff';
                });

                hasMarkedRead = true;

                // 2. Kirim update ke backend agar database tersinkronisasi
                fetch('<?= e($adminPrefix) ?>api_notif_read.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).catch(function(err) {
                    console.error('Gagal memperbarui notifikasi:', err);
                });
            }
        });

        document.addEventListener('click', function(e) {
            if (!notifDropdown.contains(e.target) && e.target !== notifBtn) {
                notifDropdown.style.display = 'none';
            }
        });
    }

    if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSidebar();
    });

    const flash = document.getElementById('flash');
    if (flash) {
        setTimeout(function() { flash.remove(); }, 5000);
    }
})();
</script>
<script src="<?= e($adminPrefix) ?>../assets/js/qrcode.min.js"></script>
<script src="<?= e($adminPrefix) ?>../assets/js/qr-modal.js"></script>
<script src="<?= e($adminPrefix) ?>../assets/js/wa-modal.js"></script>
</body>
</html>
<?php 
}
