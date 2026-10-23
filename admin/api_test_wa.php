<?php

/**
 * FILE       : admin/api_test_wa.php
 * MODUL      : Endpoint Uji Coba Pengiriman Pesan Bot WhatsApp
 * FUNGSI     : Menguji koneksi API Token dan nomor tujuan dari panel pengaturan
 * AKSES      : Administrator Saja (AJAX / POST)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/../includes/whatsapp.php';

header('Content-Type: application/json; charset=UTF-8');

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Hanya Administrator yang dapat menguji bot WhatsApp.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode HTTP tidak didukung.']);
    exit;
}

$phone = trim((string)($_POST['test_phone'] ?? ''));
$message = trim((string)($_POST['test_message'] ?? ''));

if ($phone === '') {
    echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp tujuan tes wajib diisi.']);
    exit;
}

if ($message === '') {
    $cfg = get_wa_config();
    $message = "🤖 *TES KONEKSI BOT WHATSAPP SIPALA*\n\nPesan ini adalah uji coba koneksi gateway WhatsApp dari " . $cfg['nama_instansi'] . ".\nStatus: ✅ Terhubung Berhasil\nWaktu: " . date('d-m-Y H:i:s') . " WIB\nSistem: " . $cfg['nama_aplikasi'];
}

$res = send_wa_message($phone, $message);
echo json_encode($res);
exit;
