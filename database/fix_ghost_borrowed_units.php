<?php

/**
 * FILE       : database/fix_ghost_borrowed_units.php
 * MODUL      : Pemulihan Konsistensi Status Unit Fisik & Sinkronisasi Stok Master
 * FUNGSI     : Memulihkan unit berstatus 'DIPINJAM' tanpa transaksi aktif (residu data seeder) kembali ke 'TERSEDIA'
 * AKSES      : CLI / Administrator
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();

echo "=== SIPALA: PEMULIHAN UNIT GHOST-BORROWED ===" . PHP_EOL;

try {
    $pdo->beginTransaction();

    // 1. Temukan seluruh unit DIPINJAM yang tidak memiliki transaksi aktif
    $stmt = $pdo->query("
        SELECT u.id, u.kode_unit, u.peralatan_id, p.nama_barang
        FROM unit_peralatan u
        JOIN peralatan p ON p.id = u.peralatan_id
        WHERE u.status = 'DIPINJAM'
          AND u.id NOT IN (
              SELECT dp.unit_peralatan_id 
              FROM detail_peminjaman dp 
              JOIN peminjaman pm ON pm.id = dp.peminjaman_id 
              WHERE pm.status IN ('DIPINJAM', 'MENUNGGU_PENGEMBALIAN')
                AND dp.unit_peralatan_id IS NOT NULL
          )
        FOR UPDATE
    ");
    $ghostUnits = $stmt->fetchAll();

    echo "Ditemukan " . count($ghostUnits) . " unit fisik berstatus DIPINJAM tanpa transaksi aktif." . PHP_EOL;

    if (empty($ghostUnits)) {
        echo "Seluruh unit fisik sudah konsisten dengan transaksi peminjaman." . PHP_EOL;
        $pdo->rollBack();
        exit(0);
    }

    $affectedPeralatanIds = [];
    $upd = $pdo->prepare("UPDATE unit_peralatan SET status = 'TERSEDIA' WHERE id = ?");

    foreach ($ghostUnits as $gu) {
        $upd->execute([$gu['id']]);
        $affectedPeralatanIds[(int)$gu['peralatan_id']] = true;
        echo " [+] Memulihkan unit {$gu['kode_unit']} ({$gu['nama_barang']}) -> TERSEDIA" . PHP_EOL;
    }

    // 2. Sinkronkan stok master untuk setiap jenis alat yang terdampak
    echo PHP_EOL . "Sinkronisasi stok master peralatan..." . PHP_EOL;
    foreach (array_keys($affectedPeralatanIds) as $pid) {
        $stats = sync_peralatan_stock((int)$pid);
        echo " [✓] Peralatan ID $pid disinkronkan: Total={$stats['total']}, Tersedia={$stats['tersedia']}" . PHP_EOL;
    }

    $pdo->commit();

    audit_log(
        'REPAIR',
        'DATABASE',
        null,
        'Pemulihan konsistensi: ' . count($ghostUnits) . ' unit fisik ghost-borrowed dipulihkan ke status TERSEDIA'
    );

    echo PHP_EOL . "=== PEMULIHAN SELESAI DENGAN SUKSES ===" . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
