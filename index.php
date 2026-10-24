<?php

/**
 * FILE    : index.php
 * MODUL   : Portal Publik & Dashboard Peminjam SIPALA
 * DESAIN  : Modern Editorial + Interactive Laboratory Website
 * FUNGSI  : Menampilkan landing page informasi laboratorium modern untuk publik (katalog, statistik real-time, 4 langkah SOP, pelacakan pengajuan),
 *           serta dashboard personal siswa peminjam setelah login (kartu pelajar digital, monitoring pinjaman, denda, dan katalog praktikum).
 * AKSES   : Publik (Sebelum Login) / Siswa & Peminjam (Setelah Login)
 * ALUR    : Pengguna belum login -> melihat portal modern -> masuk via login/RFID
 *           Siswa telah login -> diarahkan ke dashboard peminjam personal.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_name('sipala_session');
    session_start();
}
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/qrcode.php';
require_once __DIR__ . '/includes/whatsapp.php';
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
    function e(mixed $v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// Helper sensor nama untuk privasi pelacakan publik (Guest Tracking)
if (!function_exists('mask_guest_name')) {
    function mask_guest_name(string $name): string {
        $words = explode(' ', trim($name));
        $masked = [];
        foreach ($words as $w) {
            $len = mb_strlen($w);
            if ($len <= 2) {
                $masked[] = $w;
            } else {
                $masked[] = mb_substr($w, 0, 2) . str_repeat('*', max(2, $len - 2));
            }
        }
        return implode(' ', $masked);
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
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_loan') {
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

    // Kebijakan Lab 1: Cek apakah siswa memiliki peminjaman aktif yang telah melewati batas tanggal kembali (terlambat)
    $stOverdue = $pdo->prepare("
        SELECT p.kode_peminjaman, p.tanggal_rencana_kembali,
               GROUP_CONCAT(pr.nama_barang SEPARATOR ', ') AS daftar_alat
        FROM peminjaman p
        JOIN detail_peminjaman dp ON dp.peminjaman_id = p.id
        JOIN peralatan pr ON pr.id = dp.peralatan_id
        WHERE p.user_id = ? AND p.status IN ('DIPINJAM', 'MENUNGGU_PENGEMBALIAN')
        AND p.tanggal_rencana_kembali < CURDATE()
        GROUP BY p.id, p.kode_peminjaman, p.tanggal_rencana_kembali
        LIMIT 1
    ");
    $stOverdue->execute([$borrowerUserId]);
    $overdue = $stOverdue->fetch();

    if ($overdue) {
        $_SESSION['flash_error'] = 'Pengajuan ditolak: Anda masih memiliki alat lab yang belum dikembalikan dan telah melewati tenggat waktu (' . $overdue['kode_peminjaman'] . ' - ' . $overdue['daftar_alat'] . '). Harap segera mengembalikan alat tersebut ke ruang lab terlebih dahulu!';
        header('Location: index.php');
        exit;
    }

    // Kebijakan Lab 2: Cek apakah siswa memiliki tagihan denda keterlambatan yang belum lunas
    $stUnpaidFines = $pdo->prepare("
        SELECT COALESCE(SUM(pg.denda), 0) AS total_denda, COUNT(*) AS count_denda
        FROM pengembalian pg
        JOIN peminjaman p ON p.id = pg.peminjaman_id
        WHERE p.user_id = ? AND pg.status_denda = 'BELUM_LUNAS' AND pg.denda > 0
    ");
    $stUnpaidFines->execute([$borrowerUserId]);
    $unpaid = $stUnpaidFines->fetch();

    if ($unpaid && (int)$unpaid['total_denda'] > 0) {
        $_SESSION['flash_error'] = 'Pengajuan ditolak: Anda memiliki tagihan denda keterlambatan yang belum dilunasi sebesar Rp ' . number_format((float)$unpaid['total_denda'], 0, ',', '.') . '. Harap melunasi denda kepada petugas lab sebelum meminjam alat baru.';
        header('Location: index.php');
        exit;
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

        // Notifikasi WhatsApp Bot Otomatis
        try {
            // 1. Alert ke Admin Lab
            send_wa_notification('pengajuan', [
                'nama' => $borrowerNama,
                'nis' => $userNis !== '-' ? $userNis : ($guestNis ?? '-'),
                'kelas' => $userKelas !== '-' ? $userKelas : ($guestKelas ?? '-'),
                'kode_peminjaman' => $kodePinjam,
                'alat' => $alat['nama_barang'],
                'jumlah' => $jumlah,
                'tgl_kembali' => $tglKembali,
                'keperluan' => $keperluan,
                'peminjaman_id' => $peminjamanId
            ]);

            // 2. Konfirmasi ke Siswa jika nomor HP tersedia
            $targetStudentPhone = !empty($userNoHp) ? $userNoHp : (!empty($guestHp) ? $guestHp : '');
            if (!empty($targetStudentPhone)) {
                send_wa_notification('pengajuan_siswa', [
                    'nama' => $borrowerNama,
                    'no_hp' => $targetStudentPhone,
                    'kode_peminjaman' => $kodePinjam,
                    'alat' => $alat['nama_barang'],
                    'jumlah' => $jumlah,
                    'tgl_kembali' => $tglKembali,
                    'peminjaman_id' => $peminjamanId
                ]);
            }
        } catch (Throwable $waErr) {
            error_log('Gagal trigger notif WA pengajuan: ' . $waErr->getMessage());
        }

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
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_loan') {
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

// PROSES KEMBALIKAN ALAT (OLEH SISWA/PEMINJAM)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return_loan') {
    if (!verify_csrf()) {
        $_SESSION['flash_error'] = 'Sesi form kedaluwarsa.';
        header('Location: index.php');
        exit;
    }

    $retId = (int)($_POST['loan_id'] ?? 0);
    try {
        $stChk = $pdo->prepare("SELECT id, kode_peminjaman, status, user_id FROM peminjaman WHERE id = ?");
        $stChk->execute([$retId]);
        $loanToReturn = $stChk->fetch();

        if (!$loanToReturn) {
            $_SESSION['flash_error'] = 'Data peminjaman tidak ditemukan.';
        } elseif ($loanToReturn['status'] !== 'DIPINJAM') {
            $_SESSION['flash_error'] = 'Hanya peminjaman berstatus DIPINJAM yang dapat diajukan pengembalian.';
        } elseif (!$isLoggedIn || (int)$loanToReturn['user_id'] !== $userId) {
            $_SESSION['flash_error'] = 'Anda tidak memiliki hak untuk mengembalikan transaksi ini.';
        } else {
            $updRet = $pdo->prepare("UPDATE peminjaman SET status = 'MENUNGGU_PENGEMBALIAN' WHERE id = ?");
            $updRet->execute([$retId]);

            audit_log('RETURN_REQUEST', 'PEMINJAMAN', $retId, "Siswa mengajukan pengembalian alat {$loanToReturn['kode_peminjaman']}", $userId);
            notify_all_admins("Pengembalian Alat: {$loanToReturn['kode_peminjaman']}", "Siswa {$namaPengguna} mengajukan pengembalian alat. Silakan verifikasi fisik barang.", 'info', 'pengembalian/index.php');
            $_SESSION['flash_error'] = '';
            $_SESSION['login_success'] = 'Permohonan pengembalian ' . $loanToReturn['kode_peminjaman'] . ' berhasil dikirim! Silakan bawa alat fisik ke ruang lab untuk diserahkan ke petugas.';
        }
    } catch (Throwable $e) {
        error_log('Gagal memproses pengembalian siswa: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Gagal memproses pengembalian karena kendala sistem. Silakan coba lagi.';
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
$hasMyOverdue = false;
$totalUnpaidFine = 0;
$hasUnpaidFine = false;
$isBorrowingSuspended = false;

if ($isLoggedIn && $userId > 0) {
    // 1. Menunggu Persetujuan
    $stmtWait = $pdo->prepare('
        SELECT p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_rencana_kembali, p.status, p.keperluan,
               COALESCE(SUM(d.jumlah), 0) AS total_item,
               GROUP_CONCAT(CONCAT(COALESCE(pr.nama_barang, d.nama_barang_snapshot, "Alat"), " (", d.jumlah, " unit)") SEPARATOR ", ") AS daftar_alat
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
               GROUP_CONCAT(CONCAT(COALESCE(pr.nama_barang, d.nama_barang_snapshot, "Alat"), " (", d.jumlah, " unit)") SEPARATOR ", ") AS daftar_alat
        FROM peminjaman p 
        LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id 
        LEFT JOIN peralatan pr ON pr.id = d.peralatan_id
        WHERE p.user_id = ? AND p.status = "DISETUJUI" 
        GROUP BY p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_rencana_kembali, p.status, p.keperluan
        ORDER BY p.id DESC
    ');
    $stmtAppr->execute([$userId]);
    $myApprovedLoans = $stmtAppr->fetchAll();

    // 3. Peminjaman Aktif (Sedang Dipinjam - sertakan kode unit fisik)
    $stmtActive = $pdo->prepare('
        SELECT p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.status, p.keperluan,
               COALESCE(SUM(d.jumlah), 0) AS total_item,
               GROUP_CONCAT(CONCAT(COALESCE(pr.nama_barang, d.nama_barang_snapshot, "Alat"), IF(COALESCE(up.kode_unit, d.kode_unit_snapshot) IS NOT NULL AND COALESCE(up.kode_unit, d.kode_unit_snapshot) != "", CONCAT(" [Unit: ", COALESCE(up.kode_unit, d.kode_unit_snapshot), "]"), CONCAT(" (", d.jumlah, " unit)"))) SEPARATOR ", ") AS daftar_alat
        FROM peminjaman p 
        LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id 
        LEFT JOIN peralatan pr ON pr.id = d.peralatan_id
        LEFT JOIN unit_peralatan up ON up.id = d.unit_peralatan_id
        WHERE p.user_id = ? AND p.status IN ("DIPINJAM", "MENUNGGU_PENGEMBALIAN") 
        GROUP BY p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.status, p.keperluan
        ORDER BY p.id DESC
    ');
    $stmtActive->execute([$userId]);
    $myActiveLoans = $stmtActive->fetchAll();

    // 4. Riwayat Selesai
    $stmtHist = $pdo->prepare('
        SELECT p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_realisasi_kembali, p.status, p.keperluan,
               COALESCE(SUM(d.jumlah), 0) AS total_item,
               GROUP_CONCAT(CONCAT(COALESCE(pr.nama_barang, d.nama_barang_snapshot, "Alat"), IF(COALESCE(up.kode_unit, d.kode_unit_snapshot) IS NOT NULL AND COALESCE(up.kode_unit, d.kode_unit_snapshot) != "", CONCAT(" [Unit: ", COALESCE(up.kode_unit, d.kode_unit_snapshot), "]"), CONCAT(" (", d.jumlah, " unit)"))) SEPARATOR ", ") AS daftar_alat
        FROM peminjaman p 
        LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id 
        LEFT JOIN peralatan pr ON pr.id = d.peralatan_id
        LEFT JOIN unit_peralatan up ON up.id = d.unit_peralatan_id
        WHERE p.user_id = ? AND p.status = "SELESAI" 
        GROUP BY p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_realisasi_kembali, p.status, p.keperluan
        ORDER BY p.id DESC 
        LIMIT 5
    ');
    $stmtHist->execute([$userId]);
    $myHistory = $stmtHist->fetchAll();

    // 5. Riwayat Denda Siswa (Termasuk Status Pelunasan)
    $stmtFines = $pdo->prepare('
        SELECT pg.id, pg.tanggal_pengembalian, pg.terlambat_hari, pg.denda, pg.status_denda, pg.kondisi_barang, pg.catatan,
               p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali,
               GROUP_CONCAT(CONCAT(COALESCE(pr.nama_barang, d.nama_barang_snapshot, "Alat"), " (", d.jumlah, " unit)") SEPARATOR ", ") AS daftar_alat
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

    // 6. Notifikasi Siswa
    $stNCount = $pdo->prepare("SELECT COUNT(*) FROM notifikasi WHERE user_id = ? AND is_read = 0");
    $stNCount->execute([$userId]);
    $unreadNotifCount = (int)$stNCount->fetchColumn();

    $stNList = $pdo->prepare("SELECT id, judul, pesan, tipe, is_read, created_at FROM notifikasi WHERE user_id = ? ORDER BY id DESC LIMIT 10");
    $stNList->execute([$userId]);
    $studentNotifs = $stNList->fetchAll();

    // 7. Evaluasi Kepatuhan & Status Penangguhan Peminjam
    $hasMyOverdue = false;
    $todayDate = date('Y-m-d');
    foreach ($myActiveLoans as $al) {
        if ($todayDate > $al['tanggal_rencana_kembali']) {
            $hasMyOverdue = true;
            break;
        }
    }

    $totalUnpaidFine = 0;
    foreach ($myFines as $mf) {
        if ($mf['status_denda'] === 'BELUM_LUNAS') {
            $totalUnpaidFine += (int)$mf['denda'];
        }
    }
    $hasUnpaidFine = ($totalUnpaidFine > 0);
    $isBorrowingSuspended = ($hasMyOverdue || $hasUnpaidFine);
}

// 100% REAL DATABASE STATS (Untuk Tampilan Publik & Statistik)
// Mengutamakan perhitungan unit fisik dari tabel unit_peralatan jika tersedia
$totalJenisAlat = (int)$pdo->query("SELECT COUNT(*) FROM peralatan WHERE status = 'aktif'")->fetchColumn();

$hasPhysicalUnits = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan")->fetchColumn() > 0;
if ($hasPhysicalUnits) {
    $totalUnitTersedia = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM unit_peralatan up 
        JOIN peralatan p ON p.id = up.peralatan_id 
        WHERE up.status = 'TERSEDIA' AND p.status = 'aktif'
    ")->fetchColumn();
    $totalUnitDipinjam = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM unit_peralatan up 
        JOIN peralatan p ON p.id = up.peralatan_id 
        WHERE up.status = 'DIPINJAM' AND p.status = 'aktif'
    ")->fetchColumn();
} else {
    $totalUnitTersedia = (int)$pdo->query("SELECT COALESCE(SUM(jumlah_tersedia), 0) FROM peralatan WHERE status = 'aktif'")->fetchColumn();
    $totalUnitDipinjam = (int)$pdo->query("
        SELECT COALESCE(SUM(dp.jumlah), 0) 
        FROM detail_peminjaman dp 
        JOIN peminjaman p ON p.id = dp.peminjaman_id 
        WHERE p.status = 'DIPINJAM'
    ")->fetchColumn();
}

// Lacak status peminjaman via Kode Transaksi (Khusus Tamu / Publik)
$trackCode = trim((string)($_GET['track_kode'] ?? ''));
$trackedLoan = null;
if ($trackCode !== '') {
    $stmtTrack = $pdo->prepare('
        SELECT p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_pinjam, p.tanggal_rencana_kembali, 
               p.tanggal_realisasi_kembali, p.status, p.keperluan, p.alasan_penolakan,
               u.nama AS nama_peminjam, u.nis, u.kelas,
               COALESCE(SUM(d.jumlah), 0) AS total_item,
               GROUP_CONCAT(CONCAT(COALESCE(pr.nama_barang, d.nama_barang_snapshot, "Alat"), " (", d.jumlah, " unit)") SEPARATOR ", ") AS daftar_alat
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
    <title><?= $isLoggedIn ? 'Dashboard Siswa Peminjam — SIPALA Lab RPL' : 'SIPALA — Sistem Informasi Peminjaman Alat Lab Komputer RPL' ?></title>
    <meta name="description" content="SIPALA — Portal Informasi dan Sistem Peminjaman Alat Laboratorium Komputer Rekayasa Perangkat Lunak SMK Negeri 1 Panyingkiran.">
    <link rel="icon" type="image/jpeg" href="assets/img/logo.jpg?v=2">
    <link rel="shortcut icon" type="image/jpeg" href="assets/img/logo.jpg?v=2">
    <link rel="apple-touch-icon" href="assets/img/logo.jpg?v=2">

    <!-- Google Fonts: Plus Jakarta Sans, Caveat (Handwritten Quote), JetBrains Mono (Codes) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=JetBrains+Mono:wght@400;500;700&family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">

    <!-- Stylesheets -->
    <link rel="stylesheet" href="assets/css/index.css?v=<?= filemtime(__DIR__ . '/assets/css/index.css') ?>">
    <link rel="stylesheet" href="assets/css/image-cropper-modal.css?v=<?= filemtime(__DIR__ . '/assets/css/image-cropper-modal.css') ?>">
    <link rel="stylesheet" href="assets/css/qr-modal.css?v=<?= filemtime(__DIR__ . '/assets/css/qr-modal.css') ?>">
</head>
<body>

<!-- ==========================================================================
     HEADER / NAVBAR MODERN (STICKY GLASS + RFID TRIGGER)
     ========================================================================== -->
<header class="site-header" id="siteHeader">
    <div class="container">
        <nav class="nav-container">
            <a href="index.php" class="brand-link" title="Beranda SIPALA SMKN 1 Panyingkiran">
                <?php if ($hasOfficialLogo): ?>
                    <img src="assets/img/logo.jpg" alt="Logo SIPALA" class="brand-badge">
                <?php else: ?>
                    <div class="brand-badge" style="display:flex;align-items:center;justify-content:center;background:var(--color-blue-primary);color:#fff;font-weight:800;">SP</div>
                <?php endif; ?>
                <div class="brand-titles">
                    <span class="brand-name">SIPALA</span>
                    <span class="brand-sub">SMK NEGERI 1 PANYINGKIRAN</span>
                </div>
            </a>

            <!-- Desktop Menu -->
            <ul class="desktop-nav">
                <?php if ($isLoggedIn): ?>
                    <li><a href="#dashboard-saya" class="nav-item active">Dashboard Saya</a></li>
                    <li><a href="#katalog" class="nav-item">Katalog Praktikum</a></li>
                    <li><a href="profil.php" class="nav-item">Profil Akun</a></li>
                <?php else: ?>
                    <li><a href="#hero" class="nav-item active">Beranda</a></li>
                    <li><a href="#katalog" class="nav-item">Katalog</a></li>
                    <li><a href="#cara-pinjam" class="nav-item">Cara Peminjaman</a></li>
                    <li><a href="#lacak" class="nav-item">Lacak Pengajuan</a></li>
                    <li><a href="#ketentuan" class="nav-item">Ketentuan</a></li>
                <?php endif; ?>
            </ul>

            <!-- Navbar Action Buttons -->
            <div class="nav-actions">
                <?php if ($isLoggedIn): ?>
                    <!-- Lonceng Notifikasi Siswa -->
                    <div class="notif-bell-wrap">
                        <button type="button" class="btn-notif-bell" id="studentNotifBellBtn" title="Pemberitahuan Transaksi">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            </svg>
                            <?php if (!empty($unreadNotifCount) && $unreadNotifCount > 0): ?>
                                <span class="notif-badge" id="studentNotifBadge"><?= $unreadNotifCount > 9 ? '9+' : $unreadNotifCount ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="notif-dropdown" id="studentNotifDropdown">
                            <div class="notif-header">
                                <span>Pemberitahuan Saya</span>
                                <?php if (!empty($unreadNotifCount) && $unreadNotifCount > 0): ?>
                                    <span class="notif-subtext" id="studentNotifSubtext"><?= $unreadNotifCount ?> baru</span>
                                <?php endif; ?>
                            </div>
                            <div class="notif-body" id="studentNotifList">
                                <?php if (empty($studentNotifs)): ?>
                                    <div class="notif-empty">Belum ada pemberitahuan baru</div>
                                <?php else: ?>
                                    <?php foreach ($studentNotifs as $nt): ?>
                                        <div class="notif-item <?= (int)$nt['is_read'] === 0 ? 'unread' : '' ?>">
                                            <div class="notif-title"><?= e($nt['judul']) ?></div>
                                            <div class="notif-desc"><?= e($nt['pesan']) ?></div>
                                            <div class="notif-time"><?= date('d M Y, H:i', strtotime($nt['created_at'])) ?> WIB</div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- User Pill -->
                    <a href="profil.php" class="user-pill" title="Profil Siswa: <?= e($userName) ?>">
                        <?php if (!empty($userFoto) && file_exists(__DIR__ . '/' . $userFoto)): ?>
                            <img src="<?= e($userFoto) ?>" alt="<?= e($userName) ?>" class="user-pill-avatar" style="object-fit:cover;">
                        <?php else: ?>
                            <div class="user-pill-avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
                        <?php endif; ?>
                        <div class="user-pill-meta">
                            <strong><?= e($userName) ?></strong>
                            <span><?= e($userKelas) ?> &bull; NIS: <?= e($userNis) ?></span>
                        </div>
                    </a>

                    <!-- Tombol Keluar -->
                    <a href="logout.php" onclick="confirmLogoutStudent(event)" class="btn btn-sm" style="color:var(--color-danger);border-color:var(--color-danger-border);background:rgba(239,68,68,0.1);">
                        Keluar
                    </a>
                <?php else: ?>
                    <a href="login.php" class="btn-nav-rfid">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                            <line x1="2" y1="10" x2="22" y2="10"></line>
                        </svg>
                        <span>Masuk / Tap Kartu RFID</span>
                    </a>
                <?php endif; ?>

                <!-- Tombol Mobile Toggle (Hamburger) -->
                <button type="button" class="mobile-toggle-btn" id="mobileMenuBtn" aria-label="Buka Menu Navigasi">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </nav>
    </div>

    <!-- Drawer Menu Mobile -->
    <div class="mobile-nav-drawer" id="mobileNavDrawer">
        <?php if ($isLoggedIn): ?>
            <a href="#dashboard-saya" class="mobile-link">Dashboard Saya</a>
            <a href="#katalog" class="mobile-link">Katalog Praktikum</a>
            <a href="profil.php" class="mobile-link">Pengaturan Profil</a>
            <a href="logout.php" onclick="confirmLogoutStudent(event)" class="mobile-link" style="color:var(--color-danger);">Keluar Akun</a>
        <?php else: ?>
            <a href="#hero" class="mobile-link">Beranda</a>
            <a href="#katalog" class="mobile-link">Katalog</a>
            <a href="#cara-pinjam" class="mobile-link">Cara Peminjaman</a>
            <a href="#lacak" class="mobile-link">Lacak Pengajuan</a>
            <a href="#ketentuan" class="mobile-link">Ketentuan & SOP</a>
            <a href="login.php" class="mobile-link" style="color:var(--color-cyan-accent);font-weight:700;">Masuk / Tap Kartu RFID &rarr;</a>
        <?php endif; ?>
    </div>
</header>

<main>
    <!-- FLASH ERROR NOTICE JIKA ADA -->
    <?php if ($flashError !== ''): ?>
        <div class="container" style="padding-top:calc(var(--navbar-height) + 16px);">
            <div class="alert-notice error">
                <div><strong>Pemberitahuan:</strong> <?= e($flashError) ?></div>
                <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;font-size:18px;line-height:1;">&times;</button>
            </div>
        </div>
    <?php endif; ?>

    <!-- ======================================================================
         MODE A: SISWA PEMINJAM (SETELAH LOGIN)
         ====================================================================== -->
    <?php if ($isLoggedIn): ?>
        <div class="container" style="padding-top:calc(var(--navbar-height) + 20px);">
            
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
                        Pinjam Alat Praktikum &darr;
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
                                    <span style="font-size:11px;font-weight:700;margin-top:4px;">+ Pas Foto</span>
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
                                <span class="value" style="font-family:var(--font-mono);color:var(--color-blue-primary);font-weight:700;"><?= e($userNis) ?></span>
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
                    <div class="student-panel-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                        <div>
                            <h3 style="margin:0;font-size:17px;font-weight:800;color:var(--color-navy-dark);">Transaksi Peminjaman Saya</h3>
                            <span style="font-size:12px;color:var(--color-text-muted);">
                                <?= count($myPendingLoans) + count($myApprovedLoans) + count($myActiveLoans) ?> Transaksi Berjalan
                            </span>
                        </div>
                        <?php if ((count($myPendingLoans) + count($myApprovedLoans) + count($myActiveLoans) + count($myHistory)) > 0): ?>
                            <div style="flex:1;max-width:240px;min-width:160px;">
                                <input type="text" id="studentSearchLoan" placeholder="Cari kode/alat/status..." oninput="filterStudentLoans()" 
                                       style="width:100%;padding:7px 12px;font-size:12px;border:1px solid var(--color-border);border-radius:var(--radius-md);background:#ffffff;outline:none;">
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="student-panel-body" style="padding-top:14px;">
                        <?php if (empty($myApprovedLoans) && empty($myActiveLoans) && empty($myPendingLoans) && !empty($myHistory)): ?>
                            <div style="padding:12px 14px;background:var(--color-bg-page);border:1px dashed var(--color-border);border-radius:var(--radius-sm);font-size:12.5px;color:var(--color-text-secondary);display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                                <span style="font-size:16px;">ℹ️</span>
                                <span>Saat ini tidak ada pengajuan atau peminjaman aktif yang sedang berjalan. Silakan pilih alat pada katalog di bawah untuk meminjam.</span>
                            </div>
                        <?php endif; ?>

                        <!-- 1. SIAP DIAMBIL (DISETUJUI OLEH LABORAN) -->
                        <?php if (!empty($myApprovedLoans)): ?>
                            <?php foreach ($myApprovedLoans as $appr): ?>
                                <div class="loan-item-card approved">
                                    <div style="flex:1;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <strong style="font-family:var(--font-mono);color:#166534;font-size:14px;"><?= e($appr['kode_peminjaman']) ?></strong>
                                            <span class="status-badge DISETUJUI">Siap Diambil di Lab</span>
                                        </div>
                                        <div style="font-size:13px;color:#15803d;margin-top:4px;font-weight:700;">
                                            Alat: <?= e($appr['daftar_alat'] ?: ($appr['total_item'] . ' item')) ?>
                                        </div>
                                        <div style="font-size:11.5px;color:var(--color-text-secondary);margin-top:2px;">
                                            Keperluan: <?= e($appr['keperluan']) ?> &bull; Rencana Kembali: <strong><?= e(date('d-m-Y', strtotime($appr['tanggal_rencana_kembali']))) ?></strong>
                                        </div>
                                        <div style="font-size:11.5px;color:#166534;margin-top:4px;">
                                            * Silakan datang ke <strong><?= e($lokasiLab) ?></strong> (Jam: <?= e($jamLab) ?>) dan tunjukkan kode transaksi ini ke petugas lab.
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:6px;align-items:center;">
                                        <button type="button" class="btn btn-sm" onclick="openStudentLoanDetail(<?= htmlspecialchars(json_encode([
                                            'kode' => $appr['kode_peminjaman'],
                                            'status' => 'DISETUJUI',
                                            'status_label' => 'Siap Diambil di Lab',
                                            'status_color' => '#15803d',
                                            'status_bg' => '#ecfdf5',
                                            'alat' => $appr['daftar_alat'] ?: ($appr['total_item'] . ' item'),
                                            'keperluan' => $appr['keperluan'],
                                            'tgl_pengajuan' => date('d-m-Y', strtotime($appr['tanggal_pengajuan'])),
                                            'tgl_kembali' => date('d-m-Y', strtotime($appr['tanggal_rencana_kembali'])),
                                            'instruksi' => 'Silakan datang ke ' . $lokasiLab . ' (' . $jamLab . ') untuk serah terima alat.'
                                        ]), ENT_QUOTES, 'UTF-8') ?>)">Rincian</button>
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
                                <?php 
                                $isPendingReturn = ($act['status'] === 'MENUNGGU_PENGEMBALIAN');
                                ?>
                                <div class="loan-item-card <?= $isLate ? 'overdue' : ($isPendingReturn ? 'pending' : 'active-loan') ?>">
                                    <div style="flex:1;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <strong style="font-family:var(--font-mono);color:<?= $isPendingReturn ? '#92400e' : '#1e40af' ?>;font-size:14px;"><?= e($act['kode_peminjaman']) ?></strong>
                                            <?php if ($isPendingReturn): ?>
                                                <span class="status-badge MENUNGGU" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">Menunggu Konfirmasi Kembali</span>
                                            <?php else: ?>
                                                <span class="status-badge DIPINJAM">Sedang Dipinjam</span>
                                            <?php endif; ?>
                                            <?php if ($isLate): ?>
                                                <span class="status-badge DITOLAK">Terlambat <?= $lateDays ?> Hari</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size:13px;color:<?= $isPendingReturn ? '#92400e' : '#1e3a8a' ?>;margin-top:4px;font-weight:700;">
                                            Alat: <?= e($act['daftar_alat'] ?: ($act['total_item'] . ' item')) ?>
                                        </div>
                                        <div style="font-size:12px;color:<?= $isLate ? 'var(--color-danger)' : 'var(--color-text-secondary)' ?>;margin-top:2px;">
                                            Batas Waktu Pengembalian: <strong><?= e(date('d-m-Y', strtotime($act['tanggal_rencana_kembali']))) ?></strong>
                                            <?php if ($isLate): ?>
                                                &bull; Estimasi Denda: <strong>Rp <?= number_format($estFine, 0, ',', '.') ?></strong>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($isPendingReturn): ?>
                                            <div style="font-size:11.5px;color:#92400e;margin-top:6px;background:#fffbeb;padding:6px 10px;border-radius:var(--radius-sm);border:1px solid #fde68a;">
                                                Pengembalian telah diajukan. Silakan serahkan alat fisik ke meja petugas lab untuk dicek dan diselesaikan.
                                            </div>
                                        <?php elseif ($isLate): ?>
                                            <div style="font-size:11.5px;color:var(--color-danger);margin-top:6px;background:#fee2e2;padding:6px 10px;border-radius:var(--radius-sm);border:1px solid var(--color-danger-border);">
                                                Peringatan: Peminjaman telah melewati tenggat! Segera kembalikan ke ruang laboratorium agar denda tidak terus bertambah.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                        <button type="button" class="btn btn-sm" onclick="openStudentLoanDetail(<?= htmlspecialchars(json_encode([
                                            'kode' => $act['kode_peminjaman'],
                                            'status' => $act['status'],
                                            'status_label' => $isPendingReturn ? 'Menunggu Konfirmasi Pengembalian' : ($isLate ? 'Terlambat ' . $lateDays . ' Hari' : 'Sedang Dipinjam'),
                                            'status_color' => $isPendingReturn ? '#b45309' : ($isLate ? '#b91c1c' : '#1d4ed8'),
                                            'status_bg' => $isPendingReturn ? '#fef3c7' : ($isLate ? '#fef2f2' : '#eff6ff'),
                                            'alat' => $act['daftar_alat'] ?: ($act['total_item'] . ' item'),
                                            'keperluan' => $act['keperluan'],
                                            'tgl_pinjam' => date('d-m-Y', strtotime($act['tanggal_pinjam'])),
                                            'tgl_kembali' => date('d-m-Y', strtotime($act['tanggal_rencana_kembali'])),
                                            'est_denda' => $isLate ? 'Rp ' . number_format($estFine, 0, ',', '.') : 'Rp 0',
                                            'instruksi' => $isPendingReturn ? 'Silakan serahkan alat fisik ke meja petugas lab.' : ($isLate ? 'Segera kembalikan ke Lab agar denda tidak terus berjalan!' : 'Harap menjaga kondisi alat dan mengembalikan tepat waktu.')
                                        ]), ENT_QUOTES, 'UTF-8') ?>)">Rincian</button>
                                        <?php if (!$isPendingReturn): ?>
                                            <form method="POST" action="index.php" onsubmit="return confirm('Apakah Anda ingin mengajukan pengembalian alat <?= e($act['kode_peminjaman']) ?>? Pastikan alat diserahkan ke meja petugas lab.');" style="margin:0;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="return_loan">
                                                <input type="hidden" name="loan_id" value="<?= $act['id'] ?>">
                                                <button type="submit" class="btn btn-sm" style="background:#15803d;color:#ffffff;border-color:#15803d;font-weight:600;padding:5px 12px;">
                                                    ↩ Kembalikan Alat
                                                </button>
                                            </form>
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
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <strong style="font-family:var(--font-mono);color:#92400e;font-size:14px;"><?= e($wait['kode_peminjaman']) ?></strong>
                                            <span class="status-badge MENUNGGU">Menunggu Verifikasi</span>
                                        </div>
                                        <div style="font-size:13px;color:#92400e;margin-top:4px;font-weight:700;">
                                            Alat: <?= e($wait['daftar_alat'] ?: ($wait['total_item'] . ' item')) ?>
                                        </div>
                                        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px;">
                                            Diajukan pada: <?= e(date('d-m-Y', strtotime($wait['tanggal_pengajuan']))) ?> &bull; Rencana Kembali: <?= e(date('d-m-Y', strtotime($wait['tanggal_rencana_kembali']))) ?>
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:6px;align-items:center;">
                                        <button type="button" class="btn btn-sm" onclick="openStudentLoanDetail(<?= htmlspecialchars(json_encode([
                                            'kode' => $wait['kode_peminjaman'],
                                            'status' => 'MENUNGGU',
                                            'status_label' => 'Menunggu Verifikasi',
                                            'status_color' => '#b45309',
                                            'status_bg' => '#fef3c7',
                                            'alat' => $wait['daftar_alat'] ?: ($wait['total_item'] . ' item'),
                                            'keperluan' => $wait['keperluan'],
                                            'tgl_pengajuan' => date('d-m-Y', strtotime($wait['tanggal_pengajuan'])),
                                            'tgl_kembali' => date('d-m-Y', strtotime($wait['tanggal_rencana_kembali'])),
                                            'instruksi' => 'Pengajuan menunggu peninjauan dan persetujuan petugas Lab RPL.'
                                        ]), ENT_QUOTES, 'UTF-8') ?>)">Rincian</button>
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
                            <div id="studentLoanHistoryWrap" style="margin-top:16px;padding-top:14px;border-top:1px dashed var(--color-border);">
                                <span style="font-size:11.5px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.5px;display:block;margin-bottom:8px;">
                                    Riwayat Pengembalian Terakhir (Selesai):
                                </span>
                                <?php foreach ($myHistory as $hist): ?>
                                    <div class="loan-item-card loan-history-row" style="font-size:12px;color:var(--color-text-secondary);padding:8px 10px;border-radius:var(--radius-sm);margin-bottom:6px;background:#ffffff;border:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                        <div>
                                            <strong style="font-family:var(--font-mono);color:var(--color-text-main);"><?= e($hist['kode_peminjaman']) ?></strong>
                                            &bull; <?= e($hist['daftar_alat'] ?: ($hist['total_item'] . ' item')) ?>
                                        </div>
                                        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                                            <span style="color:var(--color-success);font-weight:600;font-size:11px;">
                                                Selesai (<?= e(date('d-m-Y', strtotime($hist['tanggal_realisasi_kembali'] ?: $hist['tanggal_pinjam']))) ?>)
                                            </span>
                                            <button type="button" class="btn btn-sm" style="padding:2px 8px;font-size:11px;" onclick="openStudentLoanDetail(<?= htmlspecialchars(json_encode([
                                                'kode' => $hist['kode_peminjaman'],
                                                'status' => 'SELESAI',
                                                'status_label' => 'Selesai Dikembalikan',
                                                'status_color' => '#15803d',
                                                'status_bg' => '#dcfce7',
                                                'alat' => $hist['daftar_alat'] ?: ($hist['total_item'] . ' item'),
                                                'keperluan' => $hist['keperluan'] ?? 'Praktikum',
                                                'tgl_pinjam' => date('d-m-Y', strtotime($hist['tanggal_pinjam'])),
                                                'tgl_kembali' => date('d-m-Y', strtotime($hist['tanggal_realisasi_kembali'] ?: $hist['tanggal_pinjam'])),
                                                'instruksi' => 'Peminjaman telah selesai dan unit alat telah diverifikasi kembali ke inventaris lab.'
                                            ]), ENT_QUOTES, 'UTF-8') ?>)">Rincian</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div id="studentLoanSearchEmpty" style="display:none;padding:24px 12px;text-align:center;font-size:13px;color:var(--color-text-muted);">
                            Tidak ada transaksi yang cocok dengan kata kunci pencarian.
                        </div>

                        <?php if (empty($myApprovedLoans) && empty($myActiveLoans) && empty($myPendingLoans) && empty($myHistory)): ?>
                            <div class="empty-state" style="padding:32px 10px;">
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
                                    <strong style="font-family:var(--font-mono);font-size:12px;color:var(--color-blue-primary);"><?= e($fine['kode_peminjaman']) ?></strong>
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

        </div>

    <!-- ======================================================================
         MODE B: PENGUNJUNG PUBLIK (SEBELUM LOGIN)
         ====================================================================== -->
    <?php else: ?>

        <!-- 1. HERO SECTION (EDITORIAL + LABORATORY SHOWCASE) -->
        <section class="hero-section" id="hero">
            <div class="hero-backdrop" style="background-image: url('assets/img/login-bg.jpg');"></div>
            <div class="hero-overlay"></div>
            <div class="hero-pattern"></div>

            <!-- Subtle Interactive Vector Constellation Canvas -->
            <canvas id="heroMotionCanvas" class="hero-motion-canvas" aria-hidden="true"></canvas>

            <div class="hero-container">
                <div class="hero-content">
                    <div class="hero-badge">
                        <span class="badge-dot"></span>
                        <span>Lab Komputer RPL</span>
                    </div>

                    <h1 class="hero-heading">
                        Sistem Informasi Peminjaman <span class="highlight-text">Alat Lab Komputer RPL</span>
                    </h1>

                    <p class="hero-description">
                        SIPALA hadir untuk memudahkan siswa dalam mengakses, meminjam, dan mengelola peralatan laboratorium komputer secara tertib, aman, dan akuntabel.
                    </p>

                    <div class="hero-cta-group">
                        <a href="#katalog" class="btn-hero-primary">
                            <span>Lihat Katalog</span>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                                <polyline points="12 5 19 12 12 19"></polyline>
                            </svg>
                        </a>
                        <a href="login.php" class="btn-hero-secondary">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                                <polyline points="10 17 15 12 10 7"></polyline>
                                <line x1="15" y1="12" x2="3" y2="12"></line>
                            </svg>
                            <span>Masuk ke SIPALA</span>
                        </a>
                    </div>

                    <a href="#statistik" class="hero-scroll-indicator">
                        <div class="scroll-mouse-icon">
                            <div class="scroll-wheel"></div>
                        </div>
                        <span>Scroll untuk menjelajahi &darr;</span>
                    </a>
                </div>

                <!-- Dekorasi Kutipan Script Cursive (Caveat Font) -->
                <div class="hero-decorative-quote">
                    <div class="quote-script">
                        Laboratorium<br>
                        adalah tempat<br>
                        ide menjadi<br>
                        karya
                    </div>
                </div>
            </div>
        </section>

        <!-- 2. STATISTIK REAL-TIME INVENTARIS DATABASE -->
        <section class="stats-section" id="statistik">
            <div class="container">
                <div class="stats-wrapper">
                    <div class="stats-intro">
                        <span class="section-tag-blue">Data Inventaris Laboratorium</span>
                        <h2 class="section-title-dark">Statistik Real-Time</h2>
                        <p class="section-desc">
                            Informasi diambil langsung dari database dan diperbarui secara real-time.
                        </p>
                    </div>

                    <div class="stats-cards-grid">
                        <!-- Stat Card 1 -->
                        <div class="stat-card">
                            <div class="stat-card-icon stat-icon-blue">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                                    <line x1="8" y1="21" x2="16" y2="21"></line>
                                    <line x1="12" y1="17" x2="12" y2="21"></line>
                                </svg>
                            </div>
                            <div class="stat-card-data">
                                <div class="stat-sub">Jenis Alat Terdaftar</div>
                                <div class="stat-number-wrap">
                                    <span class="stat-number" data-target="<?= $totalJenisAlat ?>"><?= $totalJenisAlat ?></span>
                                </div>
                                <div class="stat-title">Kategori alat aktif</div>
                            </div>
                        </div>

                        <!-- Stat Card 2 -->
                        <div class="stat-card">
                            <div class="stat-card-icon stat-icon-green">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"></polyline>
                                </svg>
                            </div>
                            <div class="stat-card-data">
                                <div class="stat-sub">Unit Tersedia</div>
                                <div class="stat-number-wrap">
                                    <span class="stat-number" data-target="<?= $totalUnitTersedia ?>"><?= $totalUnitTersedia ?></span>
                                </div>
                                <div class="stat-title">Siap dipinjam</div>
                            </div>
                        </div>

                        <!-- Stat Card 3 -->
                        <div class="stat-card">
                            <div class="stat-card-icon stat-icon-cyan">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                </svg>
                            </div>
                            <div class="stat-card-data">
                                <div class="stat-sub">Sedang Dipinjam</div>
                                <div class="stat-number-wrap">
                                    <span class="stat-number" data-target="<?= $totalUnitDipinjam ?>"><?= $totalUnitDipinjam ?></span>
                                </div>
                                <div class="stat-title">Dalam proses peminjaman</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    <?php endif; ?>

    <!-- ======================================================================
         KATALOG PERALATAN (JELAJAHI PERALATAN LAB)
         ====================================================================== -->
    <section class="catalog-section" id="katalog">
        <div class="container">
            <div class="catalog-header-wrap">
                <div class="catalog-titles">
                    <span class="section-tag">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <span>Katalog Peralatan</span>
                    </span>
                    <h2><?= $isLoggedIn ? 'Katalog Praktikum Lab RPL' : 'Jelajahi Peralatan Lab' ?></h2>
                    <p>Temukan peralatan yang kamu butuhkan. Gunakan fitur pencarian dan filter untuk hasil yang lebih cepat dan akurat.</p>
                </div>

                <div class="catalog-controls">
                    <!-- Instant Client-Side Search Form -->
                    <div class="catalog-search-box">
                        <svg class="catalog-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input type="text" id="catalogSearchInput" class="catalog-search-input" 
                               placeholder="Cari nama alat, kode barang..." value="<?= e($searchCatalog) ?>" autocomplete="off">
                    </div>

                    <!-- Category Dropdown -->
                    <select class="catalog-select-category" id="catalogCategorySelect" onchange="window.location.href=this.value">
                        <option value="index.php?q=<?= urlencode($searchCatalog) ?>#katalog">Semua Kategori</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="index.php?kat=<?= $cat['id'] ?>&q=<?= urlencode($searchCatalog) ?>#katalog" <?= $selectedKat === (int)$cat['id'] ? 'selected' : '' ?>>
                                <?= e($cat['nama_kategori']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Filter Pills Row -->
            <div class="category-pills-bar">
                <a href="index.php?q=<?= urlencode($searchCatalog) ?>#katalog" 
                   class="category-pill-btn <?= $selectedKat === 0 ? 'active' : '' ?>">
                    Semua
                </a>
                <?php foreach ($categories as $cat): ?>
                    <a href="index.php?kat=<?= $cat['id'] ?>&q=<?= urlencode($searchCatalog) ?>#katalog" 
                       class="category-pill-btn <?= $selectedKat === (int)$cat['id'] ? 'active' : '' ?>">
                        <?= e($cat['nama_kategori']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Equipment Grid -->
            <?php if (empty($catalog)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </div>
                    <?php if ($searchCatalog !== '' || $selectedKat > 0): ?>
                        <h3>Alat yang dicari tidak ditemukan</h3>
                        <p>Tidak ada perangkat yang sesuai dengan kata kunci pencarian atau kategori yang dipilih.</p>
                        <a href="index.php#katalog" class="btn btn-sm">Reset Pencarian</a>
                    <?php else: ?>
                        <h3>Inventaris Belum Tersedia</h3>
                        <p>Belum ada data peralatan aktif yang ditambahkan ke dalam database laboratorium.</p>
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
                            
                            <!-- Media Box -->
                            <div class="card-media">
                                <?php if (!empty($item['foto']) && file_exists(__DIR__ . '/' . $item['foto'])): ?>
                                    <img src="<?= e($item['foto']) ?>" alt="<?= e($item['nama_barang']) ?>" 
                                         class="card-img sipala-zoomable-img" loading="lazy"
                                         data-lightbox-src="<?= e($item['foto']) ?>"
                                         data-lightbox-title="<?= e($item['nama_barang']) ?>"
                                         data-lightbox-sub="Kode: <?= e($item['kode_barang']) ?> &bull; <?= e($item['nama_kategori'] ?? 'Lab RPL') ?>"
                                         data-lightbox-badge="Kondisi: <?= e($item['kondisi']) ?>"
                                         data-lightbox-meta="Lokasi: <?= e($item['lokasi'] ?: 'Lab Komputer RPL') ?> &bull; Stok: <?= $item['jumlah_tersedia'] ?> / <?= $item['jumlah'] ?> unit"
                                         title="Klik untuk memperbesar foto">
                                <?php else: ?>
                                    <div class="card-img-placeholder">
                                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                                            <line x1="8" y1="21" x2="16" y2="21"></line>
                                            <line x1="12" y1="17" x2="12" y2="21"></line>
                                        </svg>
                                        <span>Foto belum tersedia</span>
                                    </div>
                                <?php endif; ?>

                                <!-- Stock Status Badge -->
                                <div class="card-badge-stock">
                                    <?php if ($stokTersedia > 2): ?>
                                        <span class="stock-badge available">Tersedia</span>
                                    <?php elseif ($stokTersedia > 0): ?>
                                        <span class="stock-badge limited">Stok Terbatas</span>
                                    <?php else: ?>
                                        <span class="stock-badge empty">Stok Habis</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Card Body -->
                            <div class="card-body">
                                <h3 class="card-title"><?= e($item['nama_barang']) ?></h3>
                                <div class="card-code">KODE : <?= e($item['kode_barang']) ?></div>

                                <div class="card-meta-list">
                                    <div class="card-meta-row">
                                        <svg class="meta-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                                        <span class="label">Kategori :</span>
                                        <strong><?= e($item['nama_kategori'] ?? 'Hardware') ?></strong>
                                    </div>
                                    <div class="card-meta-row">
                                        <svg class="meta-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                        <span class="label">Lokasi :</span>
                                        <span><?= e($item['lokasi'] ?: 'Lab Komputer RPL') ?></span>
                                    </div>
                                    <div class="card-meta-row">
                                        <svg class="meta-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                                        <span class="label">Kondisi :</span>
                                        <span><?= e($item['kondisi']) ?></span>
                                    </div>
                                </div>

                                <!-- Card Actions -->
                                <div class="card-footer">
                                    <button type="button" class="btn-card-detail" onclick='openDetailModal(<?= $itemJson ?>)'>
                                        Detail
                                    </button>

                                    <button type="button" class="btn-card-qr" data-qr-target
                                            data-qr-title="<?= e($item['nama_barang']) ?>"
                                            data-qr-code="<?= e($item['kode_barang']) ?>"
                                            data-qr-scan="<?= e(get_alat_scan_url($item['kode_barang'])) ?>"
                                            data-qr-desc="Kategori: <?= e($item['nama_kategori'] ?? 'Perangkat RPL') ?> &bull; Lokasi: <?= e($item['lokasi'] ?: '-') ?>"
                                            title="Tampilkan QR Code Alat">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="3" width="7" height="7"></rect>
                                            <rect x="14" y="3" width="7" height="7"></rect>
                                            <rect x="14" y="14" width="7" height="7"></rect>
                                            <rect x="3" y="14" width="7" height="7"></rect>
                                        </svg>
                                    </button>

                                    <?php if ($isLoggedIn): ?>
                                        <?php if (!empty($isBorrowingSuspended)): ?>
                                            <button type="button" class="btn-card-borrow" disabled style="opacity:0.6;cursor:not-allowed;" title="Pengajuan ditangguhkan">
                                                Ditangguhkan
                                            </button>
                                        <?php elseif ($stokTersedia > 0): ?>
                                            <button type="button" class="btn-card-borrow" onclick='openLoanModal(<?= $itemJson ?>)'>
                                                Pinjam
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn-card-borrow" disabled style="opacity:0.5;background:#94a3b8;cursor:not-allowed;">
                                                Habis
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="catalogEmptyState" class="empty-state" style="display:none;margin-top:20px;">
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

    <!-- ======================================================================
         SECTION KHUSUS PUBLIK: CARA PEMINJAMAN & LACAK TRANSAKSI
         ====================================================================== -->
    <?php if (!$isLoggedIn): ?>

        <!-- 3. CARA PEMINJAMAN (DARK EDITORIAL 4 LANGKAH) -->
        <section class="steps-section" id="cara-pinjam">
            <div class="steps-backdrop-pattern"></div>
            <div class="container" style="position:relative;z-index:2;">
                <div class="steps-intro">
                    <span class="steps-tag">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        <span>Cara Peminjaman</span>
                    </span>
                    <h2>Mudah dalam 4 Langkah</h2>
                    <p>Proses peminjaman yang cepat, transparan, dan terstruktur.</p>
                </div>

                <div class="steps-grid">
                    <!-- Step 01 -->
                    <div class="step-card">
                        <div class="step-icon-wrap">
                            <div class="step-number-pill">01</div>
                            <div class="step-icon-circle">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                            </div>
                        </div>
                        <h3 class="step-title">Cari Alat</h3>
                        <p class="step-desc">Temukan perangkat yang kamu butuhkan di katalog lab.</p>
                    </div>

                    <div class="step-connector">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </div>

                    <!-- Step 02 -->
                    <div class="step-card">
                        <div class="step-icon-wrap">
                            <div class="step-number-pill">02</div>
                            <div class="step-icon-circle">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                            </div>
                        </div>
                        <h3 class="step-title">Ajukan</h3>
                        <p class="step-desc">Masuk ke akun dan tentukan jumlah serta tanggal pengembalian.</p>
                    </div>

                    <div class="step-connector">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </div>

                    <!-- Step 03 -->
                    <div class="step-card">
                        <div class="step-icon-wrap">
                            <div class="step-number-pill">03</div>
                            <div class="step-icon-circle">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                </svg>
                            </div>
                        </div>
                        <h3 class="step-title">Verifikasi</h3>
                        <p class="step-desc">Petugas/Laboran memeriksa dan menyetujui pengajuan.</p>
                    </div>

                    <div class="step-connector">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </div>

                    <!-- Step 04 -->
                    <div class="step-card">
                        <div class="step-icon-wrap">
                            <div class="step-number-pill">04</div>
                            <div class="step-icon-circle">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                                    <line x1="12" y1="22.08" x2="12" y2="12"></line>
                                </svg>
                            </div>
                        </div>
                        <h3 class="step-title">Ambil Alat</h3>
                        <p class="step-desc">Datang ke lab untuk serah terima via RFID atau verifikasi manual.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- 4. LACAK STATUS TRANSAKSI (GUEST TRACKING SECTION) -->
        <section class="track-section" id="lacak">
            <div class="container">
                <div class="track-wrapper">
                    
                    <!-- Kiri: Smartphone Mockup Graphic -->
                    <div class="track-device-preview">
                        <div class="smartphone-frame">
                            <div class="smartphone-notch"></div>
                            <div class="smartphone-screen">
                                <div class="screen-app-bar">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                                    <span>SIPALA Mobile</span>
                                </div>
                                <div class="screen-card-box">
                                    <div class="screen-rfid-chip">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                    </div>
                                    <strong style="font-size:11px;color:var(--color-navy-dark);display:block;">Kartu RFID Terhubung</strong>
                                    <span style="font-size:9.5px;color:var(--color-text-muted);">Lab Komputer RPL</span>
                                </div>
                                <div class="screen-timeline">
                                    <div class="screen-timeline-item">
                                        <div class="screen-dot"></div>
                                        <span>Pengajuan Diverifikasi</span>
                                    </div>
                                    <div class="screen-timeline-item">
                                        <div class="screen-dot"></div>
                                        <span>Disetujui Laboran</span>
                                    </div>
                                    <div class="screen-timeline-item">
                                        <div class="screen-dot" style="background:var(--color-blue-primary);"></div>
                                        <span style="font-weight:700;color:var(--color-blue-primary);">Siap Diambil di Lab</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tengah: Form Pencarian Kode -->
                    <div class="track-form-box">
                        <span class="section-tag">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <span>Lacak Pengajuan</span>
                        </span>
                        <h2>Cek Status Transaksi</h2>
                        <p>Masukkan kode transaksi untuk melihat status peminjaman kamu tanpa perlu login.</p>

                        <form method="get" action="index.php#lacak">
                            <div class="track-input-wrap">
                                <input type="text" name="track_kode" class="track-text-input" 
                                       placeholder="Contoh: PJM-20261019-XXXXX" value="<?= e($trackCode) ?>" required>
                                <button type="submit" class="btn-track-submit">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    <span>Cek Status</span>
                                </button>
                                <?php if ($trackCode !== ''): ?>
                                    <a href="index.php#lacak" class="btn btn-sm" style="height:46px;display:inline-flex;align-items:center;">Reset</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <!-- Kanan: Preview Hasil Pelacakan / Sample Preview -->
                    <div class="track-result-preview-box">
                        <?php if ($trackCode !== '' && $trackedLoan): ?>
                            <!-- Data Riil Hasil Pencarian Pengunjung -->
                            <div class="track-sample-card">
                                <div class="track-card-top">
                                    <span class="track-status-pill <?= e($trackedLoan['status']) ?>">
                                        <?= e($trackedLoan['status']) ?>
                                    </span>
                                    <span class="track-code-text"><?= e($trackedLoan['kode_peminjaman']) ?></span>
                                </div>

                                <div class="track-item-summary">
                                    <div class="track-item-thumb">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                                    </div>
                                    <div class="track-item-info">
                                        <strong><?= e($trackedLoan['daftar_alat'] ?: ($trackedLoan['total_item'] . ' unit alat')) ?></strong>
                                        <span>Peminjam: <?= e(mask_guest_name($trackedLoan['nama_peminjam'] ?? 'Siswa')) ?></span>
                                    </div>
                                </div>

                                <div class="track-timeline-list">
                                    <div class="timeline-step completed">
                                        <div class="timeline-bullet"></div>
                                        <strong>Pengajuan</strong>
                                        <span><?= date('d M Y, H:i', strtotime($trackedLoan['tanggal_pengajuan'])) ?></span>
                                    </div>
                                    <div class="timeline-step <?= in_array($trackedLoan['status'], ['DISETUJUI', 'DIPINJAM', 'SELESAI'], true) ? 'completed' : ($trackedLoan['status'] === 'MENUNGGU' ? 'active' : '') ?>">
                                        <div class="timeline-bullet"></div>
                                        <strong>Verifikasi Petugas</strong>
                                        <span><?= in_array($trackedLoan['status'], ['DISETUJUI', 'DIPINJAM', 'SELESAI'], true) ? 'Disetujui' : 'Menunggu' ?></span>
                                    </div>
                                    <div class="timeline-step <?= in_array($trackedLoan['status'], ['DIPINJAM', 'SELESAI'], true) ? 'completed' : ($trackedLoan['status'] === 'DISETUJUI' ? 'active' : '') ?>">
                                        <div class="timeline-bullet"></div>
                                        <strong>Serah Terima Alat</strong>
                                        <span><?= $trackedLoan['status'] === 'DISETUJUI' ? 'Siap Diambil di Lab' : ($trackedLoan['status'] === 'DIPINJAM' ? 'Sedang Dipinjam' : ($trackedLoan['status'] === 'SELESAI' ? 'Selesai' : '-')) ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($trackCode !== '' && !$trackedLoan): ?>
                            <!-- Jika Kode Tidak Ditemukan -->
                            <div class="track-sample-card" style="border-color:var(--color-danger-border);background:#fffafb;">
                                <div style="display:flex;align-items:center;gap:8px;color:var(--color-danger);font-weight:700;font-size:13px;margin-bottom:6px;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                    <span>Kode Tidak Ditemukan</span>
                                </div>
                                <p style="font-size:12px;color:var(--color-text-secondary);margin:0;">
                                    Transaksi dengan kode <strong><?= e($trackCode) ?></strong> tidak ditemukan. Pastikan format penulisan kode transaksi tepat.
                                </p>
                            </div>
                        <?php else: ?>
                            <!-- Live Interactive Sample Preview Sesuai Gambar Referensi -->
                            <div class="track-sample-card">
                                <div class="track-card-top">
                                    <span class="track-status-pill DISETUJUI">● DISETUJUI</span>
                                    <span class="track-code-text">PMJ-<?= date('Ymd') ?>-4827</span>
                                </div>

                                <div class="track-item-summary">
                                    <div class="track-item-thumb">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                                    </div>
                                    <div class="track-item-info">
                                        <strong>Laptop Praktikum Lab</strong>
                                        <span>2 Unit &bull; Batas 3 Hari</span>
                                    </div>
                                </div>

                                <div class="track-timeline-list">
                                    <div class="timeline-step completed">
                                        <div class="timeline-bullet"></div>
                                        <strong>Pengajuan</strong>
                                        <span><?= date('d M Y') ?> 08:24</span>
                                    </div>
                                    <div class="timeline-step completed">
                                        <div class="timeline-bullet"></div>
                                        <strong>Disetujui</strong>
                                        <span><?= date('d M Y') ?> 10:12</span>
                                    </div>
                                    <div class="timeline-step active">
                                        <div class="timeline-bullet"></div>
                                        <strong style="color:var(--color-blue-primary);">Siap Diambil</strong>
                                        <span style="color:var(--color-blue-primary);font-weight:700;">Lab RPL</span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </section>

    <?php endif; ?>

    <!-- ======================================================================
         KETENTUAN & SOP SECTION (CARDS + FAQ ACCORDION)
         ====================================================================== -->
    <section class="rules-section" id="ketentuan">
        <div class="container">
            <div class="rules-header">
                <div class="rules-titles">
                    <span class="section-tag">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <span>Ketentuan & SOP</span>
                    </span>
                    <h2>Aturan Peminjaman Lab</h2>
                    <p>Pastikan kamu memahami ketentuan dan tata tertib peminjaman agar proses berjalan lancar.</p>
                </div>

                <button type="button" class="btn-toggle-faq" id="faqToggleBtn">
                    <span>Lihat FAQ</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
            </div>

            <!-- 3 Rule Cards Row -->
            <div class="rules-cards-row">
                <!-- Card 1 -->
                <div class="rule-info-card">
                    <div class="rule-card-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                    </div>
                    <div class="rule-card-body">
                        <h4>Batas Maksimal Pinjam</h4>
                        <strong><?= $maksHariPinjam ?> hari kerja</strong>
                        <small>(sesuai pengaturan sistem)</small>
                    </div>
                </div>

                <!-- Card 2 -->
                <div class="rule-info-card">
                    <div class="rule-card-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                            <line x1="12" y1="22.08" x2="12" y2="12"></line>
                        </svg>
                    </div>
                    <div class="rule-card-body">
                        <h4>Kuota Unit per Pengajuan</h4>
                        <strong>Maks. <?= $maksUnitPinjam ?> unit</strong>
                        <small>(sesuai pengaturan sistem)</small>
                    </div>
                </div>

                <!-- Card 3 -->
                <div class="rule-info-card">
                    <div class="rule-card-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </div>
                    <div class="rule-card-body">
                        <h4>Jam Operasional</h4>
                        <strong>07:00 – 15:00 WIB</strong>
                        <small><?= e($lokasiLab) ?></small>
                    </div>
                </div>
            </div>

            <!-- FAQ Accordion -->
            <div class="faq-accordion-wrap" id="faqAccordion">
                <div class="faq-item">
                    <button type="button" class="faq-question-btn">
                        <span>Bagaimana cara melakukan serah terima alat di laboratorium?</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="faq-answer">
                        Setelah status pengajuan berubah menjadi <strong>DISETUJUI</strong>, silakan datang langsung ke <?= e($lokasiLab) ?> pada jam operasional (<?= e($jamLab) ?>). Tempelkan kartu smart RFID Anda pada reader atau sebutkan kode transaksi kepada petugas lab untuk serah terima unit fisik.
                    </div>
                </div>

                <div class="faq-item">
                    <button type="button" class="faq-question-btn">
                        <span>Apa yang terjadi jika saya terlambat mengembalikan alat?</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="faq-answer">
                        Keterlambatan pengembalian akan dikenakan denda administratif sebesar <strong>Rp <?= number_format($tarifDendaHarian, 0, ',', '.') ?> / hari</strong> per transaksi, serta hak pengajuan peminjaman alat baru akan ditangguhkan sementara hingga alat dikembalikan dan denda dilunasi.
                    </div>
                </div>

                <div class="faq-item">
                    <button type="button" class="faq-question-btn">
                        <span>Apakah saya bisa meminjam alat tanpa memiliki akun login?</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="faq-answer">
                        Bisa. Siswa dapat mengajukan peminjaman mandiri melalui modal katalog dengan mengisi identitas Nama, NIS, dan Kelas. Sistem akan membuatkan akun peminjam otomatis dan Anda dapat melacak statusnya melalui menu <strong>Lacak Pengajuan</strong>.
                    </div>
                </div>

                <div class="faq-item">
                    <button type="button" class="faq-question-btn">
                        <span>Bagaimana jika alat yang dipinjam mengalami kendala teknis atau kerusakan?</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="faq-answer">
                        Segera laporkan kepada laboran atau guru pembimbing praktikum di <?= e($lokasiLab) ?> agar kondisi alat dapat diperiksa dan dicatat dalam berita acara pengembalian laboratorium.
                    </div>
                </div>
            </div>
        </div>
    </section>

</main>

<!-- ==========================================================================
     SITE FOOTER (DEEP NAVY 4-COLUMN)
     ========================================================================== -->
<footer class="site-footer">
    <div class="container">
        <div class="footer-columns-grid">
            <!-- Kolom 1: Brand & Slogan -->
            <div class="footer-col footer-col-brand">
                <div class="footer-brand-header">
                    <?php if ($hasOfficialLogo): ?>
                        <img src="assets/img/logo.jpg" alt="Logo SIPALA" class="footer-brand-logo">
                    <?php else: ?>
                        <div class="footer-brand-logo" style="display:flex;align-items:center;justify-content:center;background:var(--color-blue-primary);color:#fff;font-weight:800;">SP</div>
                    <?php endif; ?>
                    <div>
                        <h3>SIPALA</h3>
                        <span>SMK NEGERI 1 PANYINGKIRAN</span>
                    </div>
                </div>
                <p>Disiplin &bull; Tertib &bull; Profesional</p>
            </div>

            <!-- Kolom 2: Jam Operasional -->
            <div class="footer-col">
                <h4>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span>Jam Operasional</span>
                </h4>
                <p>
                    Senin – Jumat<br>
                    <strong>07.00 – 15.00 WIB</strong>
                </p>
            </div>

            <!-- Kolom 3: Kontak Laboratorium -->
            <div class="footer-col">
                <h4>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <span>Kontak Laboratorium</span>
                </h4>
                <p>
                    <?= !empty($kontakLabWa) ? e($kontakLabWa) : '+62 812-3456-7890' ?><br>
                    <span>(WhatsApp)</span>
                </p>
            </div>

            <!-- Kolom 4: Alamat -->
            <div class="footer-col">
                <h4>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <span>Alamat</span>
                </h4>
                <p>
                    SMK Negeri 1 Panyingkiran<br>
                    <span>Jl. Raya Panyingkiran, Majalengka</span>
                </p>
            </div>
        </div>

        <!-- Footer Bottom Bar -->
        <div class="footer-bottom-bar">
            <div>
                &copy; <?= date('Y') ?> SIPALA. Sistem Informasi Peminjaman Alat Lab Komputer RPL. All rights reserved.
            </div>
            <div class="footer-social-links">
                <span style="font-size:12px;color:var(--color-navy-muted);">SMK Negeri 1 Panyingkiran</span>
                <a href="#" class="footer-social-icon" title="Twitter / X" aria-label="Twitter">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg>
                </a>
                <a href="#" class="footer-social-icon" title="Instagram" aria-label="Instagram">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
                </a>
                <a href="#" class="footer-social-icon" title="YouTube" aria-label="YouTube">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"/></svg>
                </a>
            </div>
        </div>
    </div>
</footer>

<!-- Floating Back to Top Button -->
<button type="button" class="back-to-top-btn" id="backToTopBtn" aria-label="Kembali ke atas">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="12" y1="19" x2="12" y2="5"></line>
        <polyline points="5 12 12 5 19 12"></polyline>
    </svg>
</button>

<!-- ==========================================================================
     MODALS (DETAIL, LOAN, SUCCESS, TRANSACTION DETAIL)
     ========================================================================== -->

<!-- 1. MODAL DETAIL ALAT -->
<div class="modal-overlay" id="detailModalOverlay">
    <div class="modal-container">
        <div class="modal-header">
            <div>
                <span id="detailAlatKategori" style="font-size:11px;font-weight:700;color:var(--color-blue-primary);text-transform:uppercase;letter-spacing:0.5px;">Kategori</span>
                <h3 id="detailAlatNama" style="margin-top:2px;font-size:18px;font-weight:800;color:var(--color-navy-dark);">Nama Alat</h3>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeDetailModal()">&times;</button>
        </div>

        <div class="detail-modal-media" id="detailMediaWrap"></div>

        <table class="detail-table">
            <tr>
                <th>Kode Barang</th>
                <td id="detailAlatKode" style="font-family:var(--font-mono);font-weight:700;color:var(--color-navy-dark);">-</td>
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
            <button type="button" class="btn btn-secondary" id="detailQrBtn" data-qr-target title="Lihat Kode QR Alat">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <span>Kode QR</span>
            </button>
            <?php if ($isLoggedIn): ?>
                <?php if (!empty($isBorrowingSuspended)): ?>
                    <button type="button" class="btn btn-secondary" disabled style="opacity:0.65;cursor:not-allowed;" title="Pengajuan ditangguhkan">
                        Pengajuan Ditangguhkan
                    </button>
                <?php else: ?>
                    <button type="button" id="detailActionBtn" class="btn btn-primary">
                        Ajukan Peminjaman
                    </button>
                <?php endif; ?>
            <?php else: ?>
                <a href="login.php" class="btn btn-primary">
                    Masuk untuk Meminjam
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 2. MODAL FORM PENGAJUAN PEMINJAMAN -->
<div class="modal-overlay" id="loanModalOverlay">
    <div class="modal-container">
        <div class="modal-header">
            <div>
                <h3 style="margin:0;font-size:18px;font-weight:800;color:var(--color-navy-dark);">Pengajuan Peminjaman Alat</h3>
                <span style="font-size:12px;color:var(--color-text-muted);">Lab Komputer Rekayasa Perangkat Lunak</span>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeLoanModal()">&times;</button>
        </div>

        <!-- Ringkasan Alat yang Dipilih -->
        <div style="display:flex;gap:12px;background:var(--color-bg-page);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:12px;margin-bottom:16px;align-items:center;">
            <div style="width:50px;height:50px;border-radius:var(--radius-sm);background:#ffffff;border:1px solid var(--color-border);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
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
                <strong style="font-size:14.5px;color:var(--color-navy-dark);" id="modalAlatNama">-</strong>
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
                    <small style="color:var(--color-text-muted);font-size:11px;">Maksimal <?= $maksHariPinjam ?> hari kerja.</small>
                </div>
            </div>

            <div class="form-group">
                <label for="modalKeperluan">Keperluan Praktikum *</label>
                <textarea name="keperluan" id="modalKeperluan" rows="2" required placeholder="Contoh: Praktikum perakitan perangkat jaringan IoT kelas XII RPL 1"></textarea>
            </div>

            <!-- Identitas Siswa / Tamu -->
            <?php if ($isLoggedIn): ?>
                <div style="background:var(--color-blue-light);border:1px solid var(--color-blue-border);border-radius:var(--radius-md);padding:10px 14px;font-size:12.5px;color:var(--color-blue-primary);margin-bottom:16px;">
                    <div><strong>Mengajukan dengan Akun Siswa Terdaftar:</strong></div>
                    <div>Nama: <?= e($userName) ?> &bull; NIS: <?= e($userNis) ?> &bull; Kelas: <?= e($userKelas) ?></div>
                </div>
            <?php else: ?>
                <div style="background:var(--color-bg-page);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:14px;margin-bottom:16px;">
                    <div style="font-weight:700;font-size:12.5px;color:var(--color-navy-dark);margin-bottom:4px;">
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

            <?php if ($isLoggedIn && !empty($isBorrowingSuspended)): ?>
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius-md);padding:12px 14px;margin-bottom:16px;color:#991b1b;font-size:12.5px;line-height:1.45;">
                    <div style="font-weight:700;margin-bottom:4px;display:flex;align-items:center;gap:6px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        Pengajuan Peminjaman Ditangguhkan
                    </div>
                    <?php if (!empty($hasMyOverdue)): ?>
                        <div>• Anda memiliki peminjaman aktif yang telah melewati batas waktu pengembalian. Harap mengembalikan alat tersebut ke ruang lab terlebih dahulu.</div>
                    <?php endif; ?>
                    <?php if (!empty($hasUnpaidFine)): ?>
                        <div style="margin-top:3px;">• Anda memiliki tagihan denda keterlambatan yang belum lunas (Rp <?= number_format((float)$totalUnpaidFine, 0, ',', '.') ?>). Harap melunasi denda ke petugas lab.</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="form-actions">
                <button type="button" class="btn" onclick="closeLoanModal()">Batal</button>
                <?php if ($isLoggedIn && !empty($isBorrowingSuspended)): ?>
                    <button type="button" class="btn btn-secondary" disabled style="cursor:not-allowed;opacity:0.6;">Pengajuan Ditangguhkan</button>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary">Kirim Pengajuan Peminjaman</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- 3. MODAL SUKSES PENGAJUAN -->
<?php if ($loanSubmitted !== null): ?>
    <div class="modal-overlay active" id="successModalOverlay">
        <div class="modal-container" style="text-align:center;">
            <div style="width:52px;height:52px;border-radius:50%;background:var(--color-success-bg);color:var(--color-success);border:1px solid var(--color-success-border);display:inline-flex;align-items:center;justify-content:center;font-size:24px;margin-bottom:12px;">
                &#10003;
            </div>
            <h3 style="margin-bottom:6px;font-size:19px;font-weight:800;color:var(--color-navy-dark);">Pengajuan Berhasil Dikirim</h3>
            <p style="font-size:13.5px;color:var(--color-text-muted);">
                Pengajuan peminjaman telah masuk ke sistem dan menunggu verifikasi petugas Lab RPL.
            </p>

            <div class="code-display-box" style="margin:16px 0;">
                <div style="font-size:11.5px;color:var(--color-blue-primary);margin-bottom:4px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Kode Peminjaman Anda</div>
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

<!-- 4. MODAL RINCIAN TRANSAKSI SISWA -->
<div class="modal-overlay" id="studentDetailModalOverlay">
    <div class="modal-container" style="max-width:500px;">
        <div class="modal-header">
            <div>
                <h3 style="margin:0;font-size:17px;font-weight:800;color:var(--color-navy-dark);">Rincian Transaksi Peminjaman</h3>
                <span style="font-size:12px;color:var(--color-text-muted);">Lab Komputer Rekayasa Perangkat Lunak</span>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeStudentDetailModal()">&times;</button>
        </div>

        <div style="background:var(--color-bg-page);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:14px;margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <span style="font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;">Kode Transaksi</span>
                <span id="dtlStatusBadge" style="font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;">-</span>
            </div>
            <div style="font-size:18px;font-weight:800;font-family:var(--font-mono);color:var(--color-blue-primary);" id="dtlKode">-</div>
        </div>

        <div style="display:grid;grid-template-columns:1fr;gap:10px;font-size:13px;margin-bottom:16px;">
            <div style="background:#ffffff;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:10px 12px;">
                <div style="font-size:11px;color:var(--color-text-muted);margin-bottom:2px;">Daftar Peralatan & Unit Fisik:</div>
                <strong id="dtlAlat" style="color:var(--color-text-main);">-</strong>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div style="background:#ffffff;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:10px 12px;">
                    <div style="font-size:11px;color:var(--color-text-muted);margin-bottom:2px;">Tanggal Pinjam / Pengajuan:</div>
                    <span id="dtlTglPinjam" style="font-weight:600;">-</span>
                </div>
                <div style="background:#ffffff;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:10px 12px;">
                    <div style="font-size:11px;color:var(--color-text-muted);margin-bottom:2px;">Batas Rencana Kembali:</div>
                    <span id="dtlTglKembali" style="font-weight:600;color:#d97706;">-</span>
                </div>
            </div>

            <div style="background:#ffffff;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:10px 12px;">
                <div style="font-size:11px;color:var(--color-text-muted);margin-bottom:2px;">Keperluan Praktikum:</div>
                <span id="dtlKeperluan" style="color:var(--color-text-secondary);">-</span>
            </div>

            <div id="dtlDendaBox" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius-sm);padding:10px 12px;color:#b91c1c;">
                <div style="font-size:11px;margin-bottom:2px;font-weight:700;">Denda Keterlambatan:</div>
                <span id="dtlDendaVal" style="font-size:15px;font-weight:800;">-</span>
            </div>

            <div style="background:#f8fafc;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:10px 12px;font-size:12px;color:var(--color-text-secondary);">
                <strong>Catatan / Petunjuk:</strong>
                <p id="dtlInstruksi" style="margin:4px 0 0 0;line-height:1.4;">-</p>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;">
            <button type="button" class="btn btn-primary" onclick="closeStudentDetailModal()">Tutup</button>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="assets/js/sweetalert2.all.min.js"></script>
<script src="assets/js/image-cropper-modal.js"></script>
<script src="assets/js/qrcode.min.js"></script>
<script src="assets/js/qr-modal.js"></script>
<script src="assets/js/index.js?v=<?= filemtime(__DIR__ . '/assets/js/index.js') ?>"></script>

<script>
// Dropdown Notifikasi Siswa
document.addEventListener('DOMContentLoaded', function() {
    const notifBtn = document.getElementById('studentNotifBellBtn');
    const notifDropdown = document.getElementById('studentNotifDropdown');
    const notifBadge = document.getElementById('studentNotifBadge');

    if (notifBtn && notifDropdown) {
        notifBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = notifDropdown.classList.contains('active');
            if (!isOpen) {
                notifDropdown.classList.add('active');
                if (notifBadge && notifBadge.style.display !== 'none') {
                    fetch('api_notif_read.php', { method: 'POST' })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                notifBadge.style.display = 'none';
                                const sub = document.getElementById('studentNotifSubtext');
                                if (sub) sub.style.display = 'none';
                                document.querySelectorAll('#studentNotifList .notif-item.unread').forEach(el => el.classList.remove('unread'));
                            }
                        })
                        .catch(err => console.error('Notif read error:', err));
                }
            } else {
                notifDropdown.classList.remove('active');
            }
        });

        document.addEventListener('click', function(e) {
            if (!notifDropdown.contains(e.target) && e.target !== notifBtn) {
                notifDropdown.classList.remove('active');
            }
        });
    }
});

function openStudentLoanDetail(data) {
    if (!data) return;
    document.getElementById('dtlKode').textContent = data.kode || '-';
    
    const badge = document.getElementById('dtlStatusBadge');
    badge.textContent = data.status_label || data.status || '-';
    badge.style.color = data.status_color || '#1e40af';
    badge.style.backgroundColor = data.status_bg || '#eff6ff';

    document.getElementById('dtlAlat').textContent = data.alat || '-';
    document.getElementById('dtlTglPinjam').textContent = data.tgl_pinjam || data.tgl_pengajuan || '-';
    document.getElementById('dtlTglKembali').textContent = data.tgl_kembali || '-';
    document.getElementById('dtlKeperluan').textContent = data.keperluan || '-';
    document.getElementById('dtlInstruksi').textContent = data.instruksi || 'Patuhi tata tertib laboratorium komputer.';

    const dendaBox = document.getElementById('dtlDendaBox');
    if (data.est_denda && data.est_denda !== 'Rp 0') {
        dendaBox.style.display = 'block';
        document.getElementById('dtlDendaVal').textContent = data.est_denda;
    } else {
        dendaBox.style.display = 'none';
    }

    const modal = document.getElementById('studentDetailModalOverlay');
    if (modal) modal.classList.add('active');
}

function closeStudentDetailModal() {
    const modal = document.getElementById('studentDetailModalOverlay');
    if (modal) modal.classList.remove('active');
}

function filterStudentLoans() {
    const input = document.getElementById('studentSearchLoan');
    const query = (input ? input.value : '').toLowerCase().trim();
    const cards = document.querySelectorAll('.student-panel-body .loan-item-card');
    let visibleCount = 0;

    cards.forEach(card => {
        const text = card.textContent.toLowerCase();
        if (query === '' || text.includes(query)) {
            card.style.display = '';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    const emptyMsg = document.getElementById('studentLoanSearchEmpty');
    if (emptyMsg) {
        emptyMsg.style.display = (visibleCount === 0 && query !== '') ? 'block' : 'none';
    }

    const histWrap = document.getElementById('studentLoanHistoryWrap');
    if (histWrap) {
        if (query === '') {
            histWrap.style.display = '';
        } else {
            const visibleHist = histWrap.querySelectorAll('.loan-history-row:not([style*="display: none"])');
            histWrap.style.display = (visibleHist.length > 0) ? '' : 'none';
        }
    }
}
</script>

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
