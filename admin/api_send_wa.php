<?php

/**
 * FILE       : admin/api_send_wa.php
 * MODUL      : Endpoint Pengiriman Pesan WhatsApp Transaksional & Pengingat
 * FUNGSI     : Mengirim pesan WhatsApp langsung ke siswa/peminjam dari tabel peminjaman & pengembalian
 * AKSES      : Administrator & Petugas Laboratorium (AJAX / POST)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/../includes/whatsapp.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode HTTP tidak didukung.']);
    exit;
}

$phone = trim((string)($_POST['phone'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));
$peminjamanId = (int)($_POST['peminjaman_id'] ?? 0);
$actionType = (string)($_POST['type'] ?? 'manual');

if ($phone === '' && $peminjamanId > 0) {
    // Cari no_hp pengguna dari peminjaman_id
    $st = $pdo->prepare("
        SELECT u.no_hp, u.nama, p.kode_peminjaman 
        FROM peminjaman p 
        JOIN users u ON u.id = p.user_id 
        WHERE p.id = ?
    ");
    $st->execute([$peminjamanId]);
    $uData = $st->fetch();
    if ($uData && !empty($uData['no_hp'])) {
        $phone = (string)$uData['no_hp'];
    }
}

if ($phone === '') {
    echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp peminjam tidak ditemukan pada profil siswa.']);
    exit;
}

if ($message === '') {
    echo json_encode(['success' => false, 'message' => 'Isi pesan tidak boleh kosong.']);
    exit;
}

$res = send_wa_message($phone, $message);

// Catat riwayat pengiriman
try {
    audit_log('KIRIM_WA', 'PEMINJAMAN', $peminjamanId > 0 ? $peminjamanId : null, "Kirim WA ($actionType) ke $phone: " . substr($message, 0, 50) . "...");
} catch (Throwable $e) {}

echo json_encode($res);
exit;
