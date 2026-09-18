<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('error', 'Metode permintaan tidak diizinkan. Penghapusan unit peralatan harus melalui POST.');
    redirect('index.php');
}

require_csrf();

$unitId = (int)($_POST['id'] ?? 0);

try {
    $st = $pdo->prepare("SELECT id, peralatan_id, kode_unit, status FROM unit_peralatan WHERE id = ?");
    $st->execute([$unitId]);
    $unit = $st->fetch();

    if (!$unit) {
        flash('error', 'Unit peralatan tidak ditemukan.');
    } elseif ($unit['status'] === 'DIPINJAM') {
        flash('error', 'Unit tidak dapat dihapus karena saat ini berstatus DIPINJAM.');
    } else {
        $del = $pdo->prepare("DELETE FROM unit_peralatan WHERE id = ?");
        $del->execute([$unitId]);

        sync_peralatan_stock((int)$unit['peralatan_id']);
        audit_log('DELETE', 'UNIT_PERALATAN', $unitId, "Menghapus unit peralatan {$unit['kode_unit']}");

        flash('success', "Unit peralatan {$unit['kode_unit']} berhasil dihapus.");
    }
} catch (Throwable $e) {
    error_log('Hapus unit error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus unit karena kendala sistem.');
}

redirect('index.php');
