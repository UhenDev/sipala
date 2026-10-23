<?php

/**
 * FILE       : admin/api_barcode_lookup.php
 * MODUL      : API Verifikasi & Pemindaian Barcode Fisik Unit Alat
 * FUNGSI     : Endpoint backend untuk melayani pemindaian barcode scanner (USB HID / input manual)
 *              pada modul Serah Terima Peminjaman, Pengembalian Alat, dan Verifikasi Label Inventaris.
 * AKSES      : Administrator / Petugas Laboratorium
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_name('sipala_session');
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Verifikasi otorisasi pengguna
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'petugas'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Akses ditolak: Endpoint ini hanya untuk Administrator dan Petugas Laboratorium.'
    ]);
    exit;
}

$pdo = db();
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'lookup_unit'));
$barcodeInput = trim((string)($_GET['barcode'] ?? $_POST['barcode'] ?? $_GET['code'] ?? $_POST['code'] ?? ''));

if ($barcodeInput === '' && $action !== 'verify_label') {
    echo json_encode([
        'success' => false,
        'message' => 'Kode barcode tidak boleh kosong. Silakan pindai label barcode atau ketik manual.'
    ]);
    exit;
}

try {
    switch ($action) {
        // -------------------------------------------------------------
        // 1. PENCARIAN UMUM / UJI COBA PEMINDAIAN BARCODE UNIT
        // -------------------------------------------------------------
        case 'lookup_unit':
            $stmt = $pdo->prepare("
                SELECT u.id, u.peralatan_id, u.kode_unit, u.barcode, u.serial_number, u.rfid_uid,
                       u.kondisi, u.lokasi, u.status, u.status_label, u.label_printed_at, u.catatan,
                       p.nama_barang, p.kode_barang, p.foto, k.nama_kategori
                FROM unit_peralatan u
                JOIN peralatan p ON p.id = u.peralatan_id
                LEFT JOIN kategori k ON k.id = p.kategori_id
                WHERE u.barcode = ? OR u.kode_unit = ? OR u.serial_number = ?
                LIMIT 1
            ");
            $stmt->execute([$barcodeInput, $barcodeInput, $barcodeInput]);
            $unit = $stmt->fetch();

            if (!$unit) {
                echo json_encode([
                    'success' => false,
                    'message' => "Unit dengan kode barcode '$barcodeInput' tidak ditemukan di basis data inventaris."
                ]);
                exit;
            }

            // Ambil informasi peminjaman aktif jika unit sedang dipinjam
            $activeLoan = null;
            if ($unit['status'] === 'DIPINJAM') {
                $stLoan = $pdo->prepare("
                    SELECT p.id AS peminjaman_id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali,
                           u.nama AS peminjam_nama, u.nis AS peminjam_nis, u.kelas AS peminjam_kelas, u.no_hp
                    FROM detail_peminjaman dp
                    JOIN peminjaman p ON p.id = dp.peminjaman_id
                    JOIN users u ON u.id = p.user_id
                    WHERE dp.unit_peralatan_id = ? AND p.status IN ('DIPINJAM', 'MENUNGGU_PENGEMBALIAN')
                    LIMIT 1
                ");
                $stLoan->execute([(int)$unit['id']]);
                $activeLoan = $stLoan->fetch() ?: null;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Data unit inventaris ditemukan.',
                'unit' => [
                    'id' => (int)$unit['id'],
                    'peralatan_id' => (int)$unit['peralatan_id'],
                    'nama_barang' => $unit['nama_barang'],
                    'kode_barang' => $unit['kode_barang'],
                    'kategori' => $unit['nama_kategori'] ?? 'Umum',
                    'kode_unit' => $unit['kode_unit'],
                    'barcode' => $unit['barcode'] ?: $unit['kode_unit'],
                    'serial_number' => $unit['serial_number'] ?: '-',
                    'rfid_uid' => $unit['rfid_uid'] ?: '-',
                    'kondisi' => $unit['kondisi'],
                    'lokasi' => $unit['lokasi'] ?: 'Lab Komputer RPL',
                    'status' => $unit['status'],
                    'status_label' => $unit['status_label'],
                    'label_printed_at' => $unit['label_printed_at'] ? format_tanggal_indonesia($unit['label_printed_at'], true) : 'Belum pernah dicetak',
                    'catatan' => $unit['catatan'] ?: '-',
                    'foto' => !empty($unit['foto']) ? $unit['foto'] : 'assets/img/default-tool.png'
                ],
                'active_loan' => $activeLoan
            ]);
            break;

        // -------------------------------------------------------------
        // 2. VALIDASI BARCODE PADA SAAT SERAH TERIMA PEMINJAMAN (BORROW)
        // -------------------------------------------------------------
        case 'verify_loan_unit':
            $peminjamanId = (int)($_GET['peminjaman_id'] ?? $_POST['peminjaman_id'] ?? 0);
            if ($peminjamanId <= 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID transaksi peminjaman tidak valid.'
                ]);
                exit;
            }

            // Ambil rincian transaksi peminjaman
            $stP = $pdo->prepare("
                SELECT p.id, p.kode_peminjaman, p.status, p.user_id, u.nama AS peminjam_nama, u.nis, u.kelas, u.rfid_uid AS user_rfid
                FROM peminjaman p
                JOIN users u ON u.id = p.user_id
                WHERE p.id = ?
            ");
            $stP->execute([$peminjamanId]);
            $loan = $stP->fetch();

            if (!$loan) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Transaksi peminjaman tidak ditemukan.'
                ]);
                exit;
            }

            if (!in_array($loan['status'], ['MENUNGGU', 'DISETUJUI', 'SIAP_DIAMBIL'], true)) {
                echo json_encode([
                    'success' => false,
                    'message' => "Transaksi {$loan['kode_peminjaman']} tidak dalam status siap serah terima (Status saat ini: {$loan['status']})."
                ]);
                exit;
            }

            // Cari unit berdasarkan barcode atau kode unit
            $stmt = $pdo->prepare("
                SELECT u.id, u.peralatan_id, u.kode_unit, u.barcode, u.serial_number, u.rfid_uid,
                       u.kondisi, u.lokasi, u.status, u.status_label,
                       p.nama_barang, p.kode_barang, p.foto
                FROM unit_peralatan u
                JOIN peralatan p ON p.id = u.peralatan_id
                WHERE u.barcode = ? OR u.kode_unit = ?
                LIMIT 1
            ");
            $stmt->execute([$barcodeInput, $barcodeInput]);
            $unit = $stmt->fetch();

            if (!$unit) {
                echo json_encode([
                    'success' => false,
                    'message' => "Unit alat dengan barcode '$barcodeInput' tidak terdaftar di sistem SIPALA."
                ]);
                exit;
            }

            // Periksa apakah jenis peralatan unit ini sesuai dengan salah satu item yang diajukan
            $stMatch = $pdo->prepare("
                SELECT id, jumlah, unit_peralatan_id 
                FROM detail_peminjaman 
                WHERE peminjaman_id = ? AND peralatan_id = ?
                LIMIT 1
            ");
            $stMatch->execute([$peminjamanId, (int)$unit['peralatan_id']]);
            $matchedDetail = $stMatch->fetch();

            if (!$matchedDetail) {
                echo json_encode([
                    'success' => false,
                    'message' => "Ketidaksesuaian Alat: Unit yang dipindai adalah '{$unit['nama_barang']}' ({$unit['barcode']}), sedangkan siswa TIDAK mengajukan alat jenis ini pada transaksi {$loan['kode_peminjaman']}!",
                    'unit' => [
                        'nama_barang' => $unit['nama_barang'],
                        'barcode' => $unit['barcode'] ?: $unit['kode_unit'],
                        'kode_unit' => $unit['kode_unit']
                    ]
                ]);
                exit;
            }

            // Periksa ketersediaan unit fisik
            if ($unit['status'] === 'DIPINJAM') {
                echo json_encode([
                    'success' => false,
                    'message' => "Penolakan: Unit '{$unit['nama_barang']}' ({$unit['barcode']}) sedang berstatus DIPINJAM oleh peminjam lain."
                ]);
                exit;
            }

            if ($unit['status'] === 'RUSAK' || $unit['kondisi'] === 'Rusak Berat') {
                echo json_encode([
                    'success' => false,
                    'message' => "Penolakan: Unit ini dalam kondisi Rusak / Maintenance ({$unit['kondisi']}) dan tidak layak dipinjamkan."
                ]);
                exit;
            }

            if (!in_array($unit['status'], ['TERSEDIA'], true)) {
                echo json_encode([
                    'success' => false,
                    'message' => "Unit tidak berstatus TERSEDIA (Status: {$unit['status']})."
                ]);
                exit;
            }

            // Validasi sukses: unit cocok dan siap diserahkan
            echo json_encode([
                'success' => true,
                'message' => "Unit '{$unit['nama_barang']}' cocok dengan pengajuan dan siap diserahkan.",
                'detail_id' => (int)$matchedDetail['id'],
                'unit' => [
                    'id' => (int)$unit['id'],
                    'peralatan_id' => (int)$unit['peralatan_id'],
                    'nama_barang' => $unit['nama_barang'],
                    'kode_barang' => $unit['kode_barang'],
                    'kode_unit' => $unit['kode_unit'],
                    'barcode' => $unit['barcode'] ?: $unit['kode_unit'],
                    'serial_number' => $unit['serial_number'] ?: '-',
                    'kondisi' => $unit['kondisi'],
                    'lokasi' => $unit['lokasi'] ?: '-',
                    'foto' => !empty($unit['foto']) ? $unit['foto'] : 'assets/img/default-tool.png'
                ]
            ]);
            break;

        // -------------------------------------------------------------
        // 3. PEMINDAIAN BARCODE PADA SAAT PENGEMBALIAN ALAT (RETURN)
        // -------------------------------------------------------------
        case 'verify_return_unit':
            // Cari unit berdasarkan barcode atau kode unit
            $stmt = $pdo->prepare("
                SELECT u.id, u.peralatan_id, u.kode_unit, u.barcode, u.serial_number, u.rfid_uid,
                       u.kondisi, u.lokasi, u.status,
                       p.nama_barang, p.kode_barang, p.foto
                FROM unit_peralatan u
                JOIN peralatan p ON p.id = u.peralatan_id
                WHERE u.barcode = ? OR u.kode_unit = ? OR u.serial_number = ?
                LIMIT 1
            ");
            $stmt->execute([$barcodeInput, $barcodeInput, $barcodeInput]);
            $unit = $stmt->fetch();

            if (!$unit) {
                echo json_encode([
                    'success' => false,
                    'message' => "Barcode '$barcodeInput' tidak dikenali. Unit tidak terdaftar dalam sistem inventaris laboratorium."
                ]);
                exit;
            }

            // Cari transaksi peminjaman aktif yang meminjam unit ini
            $stLoan = $pdo->prepare("
                SELECT p.id AS peminjaman_id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.status,
                       u.id AS user_id, u.nama AS peminjam_nama, u.nis AS peminjam_nis, u.kelas AS peminjam_kelas, u.no_hp,
                       dp.id AS detail_id, dp.kondisi_awal
                FROM detail_peminjaman dp
                JOIN peminjaman p ON p.id = dp.peminjaman_id
                JOIN users u ON u.id = p.user_id
                WHERE (dp.unit_peralatan_id = ? OR (dp.peralatan_id = ? AND dp.unit_peralatan_id IS NULL AND u.id = p.user_id))
                  AND p.status IN ('DIPINJAM', 'MENUNGGU_PENGEMBALIAN')
                ORDER BY p.id DESC
                LIMIT 1
            ");
            $stLoan->execute([(int)$unit['id'], (int)$unit['peralatan_id']]);
            $activeLoan = $stLoan->fetch();

            if (!$activeLoan) {
                echo json_encode([
                    'success' => false,
                    'message' => "Unit '{$unit['nama_barang']}' ({$unit['barcode']}) tidak memiliki transaksi peminjaman aktif yang perlu dikembalikan. Status unit saat ini: {$unit['status']}.",
                    'unit' => [
                        'nama_barang' => $unit['nama_barang'],
                        'barcode' => $unit['barcode'] ?: $unit['kode_unit'],
                        'status' => $unit['status'],
                        'kondisi' => $unit['kondisi']
                    ]
                ]);
                exit;
            }

            // Hitung keterlambatan dan denda di server
            $tarifDendaHarian = (int)($pdo->query("SELECT nilai FROM pengaturan WHERE nama = 'tarif_denda_harian'")->fetchColumn() ?: 1000);
            $tglRencana = (string)$activeLoan['tanggal_rencana_kembali'];
            $today = date('Y-m-d');
            $terlambatHari = 0;
            if (!empty($tglRencana) && $today > $tglRencana) {
                $diffSec = strtotime($today) - strtotime($tglRencana);
                $terlambatHari = max(0, (int)floor($diffSec / 86400));
            }
            $denda = $terlambatHari * $tarifDendaHarian;

            echo json_encode([
                'success' => true,
                'message' => "Transaksi aktif ditemukan untuk {$activeLoan['kode_peminjaman']} ({$activeLoan['peminjam_nama']}).",
                'loan' => [
                    'peminjaman_id' => (int)$activeLoan['peminjaman_id'],
                    'kode_peminjaman' => $activeLoan['kode_peminjaman'],
                    'peminjam_nama' => $activeLoan['peminjam_nama'],
                    'peminjam_nis' => $activeLoan['peminjam_nis'] ?: '-',
                    'peminjam_kelas' => $activeLoan['peminjam_kelas'] ?: '-',
                    'tanggal_pinjam' => date('d-m-Y', strtotime((string)$activeLoan['tanggal_pinjam'])),
                    'tanggal_rencana_kembali' => date('d-m-Y', strtotime($tglRencana)),
                    'terlambat_hari' => $terlambatHari,
                    'denda' => $denda,
                    'denda_formatted' => format_rupiah($denda),
                    'kondisi_awal' => $activeLoan['kondisi_awal'] ?: 'Baik'
                ],
                'unit' => [
                    'id' => (int)$unit['id'],
                    'peralatan_id' => (int)$unit['peralatan_id'],
                    'nama_barang' => $unit['nama_barang'],
                    'kode_barang' => $unit['kode_barang'],
                    'kode_unit' => $unit['kode_unit'],
                    'barcode' => $unit['barcode'] ?: $unit['kode_unit'],
                    'serial_number' => $unit['serial_number'] ?: '-',
                    'kondisi' => $unit['kondisi'],
                    'lokasi' => $unit['lokasi'] ?: '-'
                ]
            ]);
            break;

        // -------------------------------------------------------------
        // 4. VERIFIKASI PENEMPELAN LABEL BARCODE FISIK OLEH PETUGAS
        // -------------------------------------------------------------
        case 'verify_label':
            require_csrf();
            $unitId = (int)($_POST['unit_id'] ?? 0);
            if ($unitId <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID Unit tidak valid.']);
                exit;
            }

            $stU = $pdo->prepare("SELECT id, kode_unit, barcode, status_label FROM unit_peralatan WHERE id = ?");
            $stU->execute([$unitId]);
            $unit = $stU->fetch();

            if (!$unit) {
                echo json_encode(['success' => false, 'message' => 'Unit tidak ditemukan.']);
                exit;
            }

            $upd = $pdo->prepare("
                UPDATE unit_peralatan 
                SET status_label = 'TERVERIFIKASI', 
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $upd->execute([$unitId]);

            audit_log('VERIFY_LABEL', 'UNIT_PERALATAN', $unitId, "Verifikasi penempelan fisik barcode label untuk unit {$unit['barcode']} ({$unit['kode_unit']})");

            echo json_encode([
                'success' => true,
                'message' => "Label fisik untuk unit {$unit['barcode']} berhasil diverifikasi dan terkonfirmasi aktif.",
                'status_label' => 'TERVERIFIKASI'
            ]);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => "Aksi API '$action' tidak dikenali."
            ]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('API Barcode Lookup Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
