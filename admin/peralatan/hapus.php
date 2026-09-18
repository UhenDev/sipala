<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('error', 'Metode permintaan tidak diizinkan. Penghapusan peralatan harus melalui POST.');
    redirect('index.php');
}

require_csrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    redirect('index.php');
}

try {
    // Cek apakah sedang dipinjam
    $chk = $pdo->prepare('
        SELECT COUNT(*) 
        FROM detail_peminjaman dp 
        JOIN peminjaman p ON p.id = dp.peminjaman_id 
        WHERE dp.peralatan_id = ? AND p.status IN ("MENUNGGU", "DISETUJUI", "DIPINJAM")
    ');
    $chk->execute([$id]);
    if ((int)$chk->fetchColumn() > 0) {
        throw new RuntimeException('Alat ini tidak dapat dihapus karena sedang dalam transaksi peminjaman aktif!');
    }

    $stName = $pdo->prepare('SELECT nama_barang FROM peralatan WHERE id = ?');
    $stName->execute([$id]);
    $alatName = (string)($stName->fetchColumn() ?: "ID $id");

    $stmt = $pdo->prepare('DELETE FROM peralatan WHERE id = ?');
    $stmt->execute([$id]);

    audit_log('DELETE', 'PERALATAN', $id, "Menghapus master peralatan $alatName (ID: $id)");

    flash('success', 'Peralatan berhasil dihapus.');
} catch (Throwable $e) {
    error_log('Hapus peralatan error: ' . $e->getMessage());
    flash('error', $e->getMessage());
}

redirect('index.php');
