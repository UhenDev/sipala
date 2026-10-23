<?php

/**
 * FILE       : database/migration_barcode.php
 * MODUL      : Migrasi Basis Data untuk Integrasi Barcode Fisik Unit Alat & Snapshot Transaksi
 * FUNGSI     : Menambahkan kolom barcode unik, status pelabelan fisik, dan snapshot inventaris pada detail peminjaman
 * AKSES      : CLI Terminal (php database/migration_barcode.php)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
$pdo = db();

echo "========================================================\n";
echo "   SIPALA - MIGRASI PENYESUAIAN BASIS DATA BARCODE      \n";
echo "========================================================\n\n";

try {
    $pdo->beginTransaction();

    // 1. Periksa dan tambahkan kolom 'barcode' pada tabel unit_peralatan
    echo "1. Memeriksa kolom 'barcode' pada unit_peralatan...\n";
    $colCheck = $pdo->query("SHOW COLUMNS FROM `unit_peralatan` LIKE 'barcode'")->fetch();
    if (!$colCheck) {
        $pdo->exec("
            ALTER TABLE `unit_peralatan` 
            ADD COLUMN `barcode` VARCHAR(64) NULL AFTER `kode_unit`,
            ADD UNIQUE INDEX `idx_unit_barcode` (`barcode`);
        ");
        echo "   -> Kolom 'barcode' berhasil ditambahkan dengan UNIQUE index.\n";
    } else {
        echo "   -> Kolom 'barcode' sudah tersedia.\n";
    }

    // 2. Periksa dan tambahkan kolom 'status_label' pada unit_peralatan
    echo "2. Memeriksa kolom 'status_label' pada unit_peralatan...\n";
    $colLabelCheck = $pdo->query("SHOW COLUMNS FROM `unit_peralatan` LIKE 'status_label'")->fetch();
    if (!$colLabelCheck) {
        $pdo->exec("
            ALTER TABLE `unit_peralatan` 
            ADD COLUMN `status_label` ENUM('BELUM_DILABELI', 'SUDAH_DILABELI', 'TERVERIFIKASI') NOT NULL DEFAULT 'BELUM_DILABELI' AFTER `status`,
            ADD INDEX `idx_unit_status_label` (`status_label`);
        ");
        echo "   -> Kolom 'status_label' berhasil ditambahkan.\n";
    } else {
        echo "   -> Kolom 'status_label' sudah tersedia.\n";
    }

    // 3. Periksa dan tambahkan kolom 'label_printed_at' pada unit_peralatan
    echo "3. Memeriksa kolom 'label_printed_at' pada unit_peralatan...\n";
    $colPrintCheck = $pdo->query("SHOW COLUMNS FROM `unit_peralatan` LIKE 'label_printed_at'")->fetch();
    if (!$colPrintCheck) {
        $pdo->exec("
            ALTER TABLE `unit_peralatan` 
            ADD COLUMN `label_printed_at` DATETIME NULL AFTER `status_label`;
        ");
        echo "   -> Kolom 'label_printed_at' berhasil ditambahkan.\n";
    } else {
        echo "   -> Kolom 'label_printed_at' sudah tersedia.\n";
    }

    // 4. Periksa dan tambahkan kolom snapshot pada detail_peminjaman
    echo "4. Memeriksa kolom snapshot pada detail_peminjaman...\n";
    $colKodeBarang = $pdo->query("SHOW COLUMNS FROM `detail_peminjaman` LIKE 'kode_barang_snapshot'")->fetch();
    if (!$colKodeBarang) {
        $pdo->exec("ALTER TABLE `detail_peminjaman` ADD COLUMN `kode_barang_snapshot` VARCHAR(50) NULL AFTER `peralatan_id`");
        echo "   -> Kolom 'kode_barang_snapshot' berhasil ditambahkan.\n";
    } else {
        echo "   -> Kolom 'kode_barang_snapshot' sudah tersedia.\n";
    }

    $colNamaBarang = $pdo->query("SHOW COLUMNS FROM `detail_peminjaman` LIKE 'nama_barang_snapshot'")->fetch();
    if (!$colNamaBarang) {
        $pdo->exec("ALTER TABLE `detail_peminjaman` ADD COLUMN `nama_barang_snapshot` VARCHAR(150) NULL AFTER `kode_barang_snapshot`");
        echo "   -> Kolom 'nama_barang_snapshot' berhasil ditambahkan.\n";
    } else {
        echo "   -> Kolom 'nama_barang_snapshot' sudah tersedia.\n";
    }

    $colKodeUnit = $pdo->query("SHOW COLUMNS FROM `detail_peminjaman` LIKE 'kode_unit_snapshot'")->fetch();
    if (!$colKodeUnit) {
        $pdo->exec("ALTER TABLE `detail_peminjaman` ADD COLUMN `kode_unit_snapshot` VARCHAR(60) NULL AFTER `unit_peralatan_id`");
        echo "   -> Kolom 'kode_unit_snapshot' berhasil ditambahkan.\n";
    } else {
        echo "   -> Kolom 'kode_unit_snapshot' sudah tersedia.\n";
    }

    $colSnapCheck = $pdo->query("SHOW COLUMNS FROM `detail_peminjaman` LIKE 'barcode_snapshot'")->fetch();
    if (!$colSnapCheck) {
        $pdo->exec("ALTER TABLE `detail_peminjaman` ADD COLUMN `barcode_snapshot` VARCHAR(64) NULL AFTER `kode_unit_snapshot`");
        echo "   -> Kolom 'barcode_snapshot' berhasil ditambahkan ke detail_peminjaman.\n";
    } else {
        echo "   -> Kolom 'barcode_snapshot' sudah tersedia.\n";
    }

    // 5. Inisialisasi barcode unik untuk seluruh unit peralatan yang belum memiliki barcode
    echo "5. Menginisialisasi kode barcode unik format SIP-ALT-XXXXXX untuk unit yang belum memiliki barcode...\n";
    $unitsWithoutBarcode = $pdo->query("SELECT id, kode_unit FROM unit_peralatan WHERE barcode IS NULL OR barcode = ''")->fetchAll();
    
    $updStmt = $pdo->prepare("UPDATE unit_peralatan SET barcode = ? WHERE id = ?");
    $updatedCount = 0;
    foreach ($unitsWithoutBarcode as $u) {
        $unitId = (int)$u['id'];
        $barcodeCode = sprintf("SIP-ALT-%06d", $unitId);
        
        // Cek jika barcodeCode kebetulan sudah terpakai
        $chkExist = $pdo->prepare("SELECT id FROM unit_peralatan WHERE barcode = ? AND id != ?");
        $chkExist->execute([$barcodeCode, $unitId]);
        if ($chkExist->fetch()) {
            $barcodeCode = sprintf("SIP-ALT-%06d-%s", $unitId, bin2hex(random_bytes(2)));
        }

        $updStmt->execute([$barcodeCode, $unitId]);
        $updatedCount++;
    }
    echo "   -> Berhasil membuat barcode unik untuk $updatedCount unit inventaris.\n";

    // 6. Sinkronkan snapshot data pada transaksi peminjaman lampau
    echo "6. Menyinkronkan snapshot pada detail_peminjaman lama...\n";
    $pdo->exec("
        UPDATE detail_peminjaman dp
        JOIN peralatan p ON p.id = dp.peralatan_id
        SET dp.kode_barang_snapshot = COALESCE(dp.kode_barang_snapshot, p.kode_barang),
            dp.nama_barang_snapshot = COALESCE(dp.nama_barang_snapshot, p.nama_barang)
        WHERE dp.kode_barang_snapshot IS NULL OR dp.nama_barang_snapshot IS NULL;
    ");
    $pdo->exec("
        UPDATE detail_peminjaman dp
        JOIN unit_peralatan up ON up.id = dp.unit_peralatan_id
        SET dp.kode_unit_snapshot = COALESCE(dp.kode_unit_snapshot, up.kode_unit),
            dp.barcode_snapshot = COALESCE(dp.barcode_snapshot, up.barcode)
        WHERE dp.unit_peralatan_id IS NOT NULL AND (dp.kode_unit_snapshot IS NULL OR dp.barcode_snapshot IS NULL);
    ");
    echo "   -> Sinkronisasi snapshot selesai.\n";

    // 7. Pastikan integritas kolom log_aktivitas.aktivitas memiliki default
    $pdo->exec("ALTER TABLE `log_aktivitas` MODIFY COLUMN `aktivitas` VARCHAR(100) NOT NULL DEFAULT ''");
    echo "   -> Integritas kolom log_aktivitas.aktivitas terverifikasi.\n";

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    echo "\n========================================================\n";
    echo "   MIGRASI DATABASE BARCODE BERHASIL 100%!               \n";
    echo "========================================================\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR MIGRASI: " . $e->getMessage() . "\n";
    exit(1);
}
