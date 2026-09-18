<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('error', 'Metode permintaan tidak diizinkan. Penghapusan kategori harus menggunakan formulir POST.');
    redirect('index.php');
}

require_csrf();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    flash('error', 'ID kategori tidak valid.');
    redirect('index.php');
}

// Cek Kategori
$stmt = $pdo->prepare('SELECT id, nama_kategori FROM kategori WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$category = $stmt->fetch();

if (!$category) {
    flash('error', 'Kategori tidak ditemukan.');
    redirect('index.php');
}

$categoryName = (string)$category['nama_kategori'];

// Cek apakah kategori masih digunakan peralatan
$stmt = $pdo->prepare('SELECT COUNT(*) FROM peralatan WHERE kategori_id = ?');
$stmt->execute([$id]);
$totalEquipment = (int)$stmt->fetchColumn();

if ($totalEquipment > 0) {
    flash('error', 'Kategori "' . $categoryName . '" tidak dapat dihapus karena masih digunakan oleh ' . $totalEquipment . ' data peralatan.');
    redirect('index.php');
}

// Hapus Kategori
try {
    $stmt = $pdo->prepare('DELETE FROM kategori WHERE id = ?');
    $stmt->execute([$id]);
    audit_log('DELETE', 'KATEGORI', $id, "Menghapus kategori $categoryName");
    flash('success', 'Kategori "' . $categoryName . '" berhasil dihapus.');
} catch (PDOException $exception) {
    error_log('SIPALA hapus kategori error: ' . $exception->getMessage());
    flash('error', 'Kategori tidak dapat dihapus. Pastikan kategori tidak sedang digunakan.');
}

redirect('index.php');
