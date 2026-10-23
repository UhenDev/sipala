<?php

/**
 * FILE       : api_notif_read.php
 * MODUL      : API Notifikasi Pengguna (Siswa & Admin)
 * FUNGSI     : Menandai notifikasi pengguna aktif menjadi terbaca (is_read = 1)
 * AKSES      : Pengguna Terautentikasi (Siswa / Petugas / Admin)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_name('sipala_session');
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/config/database.php';

try {
    $userId = (int)$_SESSION['user_id'];
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE notifikasi SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    $affected = $stmt->rowCount();

    echo json_encode([
        'success' => true,
        'marked_read' => $affected,
        'unread_count' => 0
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('API notif error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Gagal memperbarui notifikasi.'
    ]);
}
