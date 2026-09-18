<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_name('sipala_session');
    session_start();
}
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'petugas'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak: Endpoint ini hanya untuk Administrator dan Petugas Laboratorium.']);
    exit;
}

$uid = trim((string)($_GET['uid'] ?? ''));

if ($uid === '') {
    echo json_encode(['success' => false, 'message' => 'UID kosong']);
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT id, nama, username, nis, kelas, role, status, rfid_uid 
        FROM users 
        WHERE rfid_uid = ? 
        LIMIT 1
    ');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode([
            'success' => false,
            'message' => 'Kartu RFID (' . htmlspecialchars($uid) . ') belum terdaftar pada siswa/pengguna manapun!'
        ]);
        exit;
    }

    if ($user['status'] !== 'aktif') {
        echo json_encode([
            'success' => false,
            'message' => 'Akun atas nama ' . htmlspecialchars($user['nama']) . ' sedang nonaktif!'
        ]);
        exit;
    }

    // Cek peminjaman aktif yang belum dikembalikan
    $qLoan = $pdo->prepare('
        SELECT COUNT(*) 
        FROM peminjaman 
        WHERE user_id = ? AND status = "DIPINJAM"
    ');
    $qLoan->execute([(int)$user['id']]);
    $activeLoans = (int)$qLoan->fetchColumn();

    echo json_encode([
        'success' => true,
        'user' => [
            'id' => (int)$user['id'],
            'nama' => $user['nama'],
            'nis' => $user['nis'] ?: '-',
            'kelas' => $user['kelas'] ?: '-',
            'username' => $user['username'],
            'rfid_uid' => $user['rfid_uid'],
            'active_loans' => $activeLoans
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('RFID AJAX error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem saat memproses kartu RFID.']);
}
