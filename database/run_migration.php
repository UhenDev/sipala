<?php

/**
 * FILE       : database/run_migration.php
 * MODUL      : Skrip Migrasi & Pembaruan Basis Data (Database Migration)
 * FUNGSI     : Memperbarui struktur tabel database lama ke arsitektur SIPALA modern secara otomatis
 * AKSES      : Command Line Interface (CLI) / Developer Setup
 *
 * TUGAS UTAMA:
 * 1. Membuat tabel `unit_peralatan` (pelacakan unit fisik individual).
 * 2. Membuat tabel `rfid_cards` (manajemen chip kartu pengguna dan tag alat).
 * 3. Membuat tabel `notifikasi` (sistem pemberitahuan internal pengguna).
 * 4. Memperluas ENUM status transaksi peminjaman (MENUNGGU, DISETUJUI, DIPINJAM, SELESAI, dll).
 * 5. Menambahkan kolom relasi `unit_peralatan_id` pada tabel `detail_peminjaman`.
 * 6. Mengisi (seed) unit fisik otomatis untuk seluruh master alat yang sudah ada di database.
 */

declare(strict_types=1);

// Proteksi Keamanan: Hanya izinkan eksekusi via Command Line Interface (CLI)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Akses ditolak: Skrip migrasi DDL ini hanya dapat dijalankan melalui terminal (CLI).' . PHP_EOL);
}


require_once __DIR__ . '/../config/database.php';
$pdo = db();

echo "=== SIPALA DATABASE UPGRADE MIGRATION ===" . PHP_EOL;

try {
    // 1. Tabel unit_peralatan
    echo "1. Creating unit_peralatan table..." . PHP_EOL;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `unit_peralatan` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `peralatan_id` INT UNSIGNED NOT NULL,
            `kode_unit` VARCHAR(60) NOT NULL UNIQUE,
            `serial_number` VARCHAR(100) NULL,
            `rfid_uid` VARCHAR(100) NULL UNIQUE,
            `kondisi` ENUM('Baik', 'Rusak Ringan', 'Rusak Berat') NOT NULL DEFAULT 'Baik',
            `lokasi` VARCHAR(150) NULL,
            `status` ENUM('TERSEDIA', 'DIPINJAM', 'RUSAK', 'HILANG', 'MAINTENANCE') NOT NULL DEFAULT 'TERSEDIA',
            `catatan` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_unit_peralatan (`peralatan_id`),
            INDEX idx_unit_status (`status`),
            INDEX idx_unit_kondisi (`kondisi`),
            CONSTRAINT fk_unit_peralatan_master FOREIGN KEY (`peralatan_id`) REFERENCES `peralatan` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Tabel rfid_cards
    echo "2. Creating rfid_cards table..." . PHP_EOL;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `rfid_cards` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NULL,
            `unit_id` INT UNSIGNED NULL,
            `uid` VARCHAR(100) NOT NULL UNIQUE,
            `tipe` ENUM('user', 'alat') NOT NULL DEFAULT 'user',
            `status` ENUM('aktif', 'nonaktif') NOT NULL DEFAULT 'aktif',
            `catatan` VARCHAR(255) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_rfid_user (`user_id`),
            INDEX idx_rfid_unit (`unit_id`),
            INDEX idx_rfid_status (`status`),
            CONSTRAINT fk_rfid_user FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT fk_rfid_unit FOREIGN KEY (`unit_id`) REFERENCES `unit_peralatan` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. Tabel notifikasi
    echo "3. Creating notifikasi table..." . PHP_EOL;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notifikasi` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `judul` VARCHAR(150) NOT NULL,
            `pesan` TEXT NOT NULL,
            `tipe` ENUM('info', 'success', 'warning', 'danger') NOT NULL DEFAULT 'info',
            `link` VARCHAR(255) NULL,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notif_user (`user_id`),
            INDEX idx_notif_read (`is_read`),
            CONSTRAINT fk_notif_user FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 4. Update peminjaman status enum
    echo "4. Updating peminjaman status ENUM..." . PHP_EOL;
    $pdo->exec("
        ALTER TABLE `peminjaman` 
        MODIFY COLUMN `status` ENUM('MENUNGGU','DISETUJUI','SIAP_DIAMBIL','DIPINJAM','MENUNGGU_PENGEMBALIAN','SELESAI','DITOLAK','TERLAMBAT','DIBATALKAN') 
        NOT NULL DEFAULT 'MENUNGGU';
    ");

    // 5. Update detail_peminjaman: unit_peralatan_id
    echo "5. Checking detail_peminjaman unit_peralatan_id column..." . PHP_EOL;
    $colCheck = $pdo->query("SHOW COLUMNS FROM `detail_peminjaman` LIKE 'unit_peralatan_id'")->fetch();
    if (!$colCheck) {
        $pdo->exec("
            ALTER TABLE `detail_peminjaman` 
            ADD COLUMN `unit_peralatan_id` INT UNSIGNED NULL AFTER `peralatan_id`,
            ADD CONSTRAINT fk_detail_unit_peralatan FOREIGN KEY (`unit_peralatan_id`) REFERENCES `unit_peralatan` (`id`) ON DELETE SET NULL;
        ");
        echo "   -> Added unit_peralatan_id column to detail_peminjaman." . PHP_EOL;
    } else {
        echo "   -> unit_peralatan_id column already exists." . PHP_EOL;
    }

    // 6. Update pengembalian: status_denda
    echo "6. Checking pengembalian status_denda column..." . PHP_EOL;
    $colDenda = $pdo->query("SHOW COLUMNS FROM `pengembalian` LIKE 'status_denda'")->fetch();
    if (!$colDenda) {
        $pdo->exec("
            ALTER TABLE `pengembalian` 
            ADD COLUMN `status_denda` ENUM('LUNAS', 'BELUM_LUNAS') NOT NULL DEFAULT 'LUNAS' AFTER `denda`;
        ");
        echo "   -> Added status_denda column to pengembalian." . PHP_EOL;
    } else {
        echo "   -> status_denda column already exists." . PHP_EOL;
    }

    // 7. Update log_aktivitas fields
    echo "7. Checking log_aktivitas columns..." . PHP_EOL;
    $colsLog = $pdo->query("SHOW COLUMNS FROM `log_aktivitas`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('aksi', $colsLog, true)) {
        $pdo->exec("ALTER TABLE `log_aktivitas` ADD COLUMN `aksi` VARCHAR(50) NOT NULL DEFAULT 'UMUM' AFTER `user_id`");
    }
    if (!in_array('modul', $colsLog, true)) {
        $pdo->exec("ALTER TABLE `log_aktivitas` ADD COLUMN `modul` VARCHAR(50) NOT NULL DEFAULT 'SYSTEM' AFTER `aksi`");
    }
    if (!in_array('reference_id', $colsLog, true)) {
        $pdo->exec("ALTER TABLE `log_aktivitas` ADD COLUMN `reference_id` INT UNSIGNED NULL AFTER `modul`");
    }

    // 8. Seeding unit_peralatan for existing peralatan
    echo "8. Seeding physical units for existing master equipment..." . PHP_EOL;
    $equipmentList = $pdo->query("SELECT id, kode_barang, nama_barang, jumlah, jumlah_tersedia, kondisi, lokasi FROM peralatan")->fetchAll();
    
    foreach ($equipmentList as $eq) {
        $eqId = (int)$eq['id'];
        $totalQty = max(1, (int)$eq['jumlah']);
        $availQty = (int)$eq['jumlah_tersedia'];
        $baseCode = preg_replace('/[^A-Za-z0-9]/', '', (string)$eq['kode_barang']);
        if ($baseCode === '') $baseCode = 'EQ' . $eqId;
        $baseCode = strtoupper($baseCode);

        // Check how many units already exist
        $existingCount = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan WHERE peralatan_id = $eqId")->fetchColumn();
        
        if ($existingCount < $totalQty) {
            $stmtInsUnit = $pdo->prepare("
                INSERT INTO unit_peralatan (peralatan_id, kode_unit, kondisi, lokasi, status, catatan)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            for ($i = $existingCount + 1; $i <= $totalQty; $i++) {
                $unitCode = sprintf("%s-%03d", $baseCode, $i);
                // First availQty units are TERSEDIA, rest DIPINJAM or RUSAK according to condition
                $unitStatus = ($i <= $availQty) ? 'TERSEDIA' : 'DIPINJAM';
                if ($eq['kondisi'] === 'Rusak Berat') {
                    $unitStatus = 'RUSAK';
                }
                
                // Avoid duplicate kode_unit
                $chkCode = $pdo->prepare("SELECT id FROM unit_peralatan WHERE kode_unit = ?");
                $chkCode->execute([$unitCode]);
                if ($chkCode->fetch()) {
                    $unitCode = sprintf("%s-%03d-%s", $baseCode, $i, bin2hex(random_bytes(2)));
                }

                $stmtInsUnit->execute([
                    $eqId,
                    $unitCode,
                    $eq['kondisi'] ?? 'Baik',
                    $eq['lokasi'] ?: 'Lab',
                    $unitStatus,
                    'Unit fisik diinisialisasi otomatis dari master inventaris'
                ]);
            }
            echo "   -> Generated " . ($totalQty - $existingCount) . " physical units for equipment ID $eqId ({$eq['nama_barang']})." . PHP_EOL;
        }
    }

    // 9. Seeding rfid_cards from existing users
    echo "9. Seeding rfid_cards for existing users with RFID..." . PHP_EOL;
    $usersWithRfid = $pdo->query("SELECT id, rfid_uid, nama FROM users WHERE rfid_uid IS NOT NULL AND rfid_uid != ''")->fetchAll();
    $stmtRfidCard = $pdo->prepare("
        INSERT INTO rfid_cards (user_id, uid, tipe, status, catatan)
        VALUES (?, ?, 'user', 'aktif', ?)
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)
    ");
    foreach ($usersWithRfid as $u) {
        $stmtRfidCard->execute([(int)$u['id'], $u['rfid_uid'], 'Kartu pengguna: ' . $u['nama']]);
    }
    echo "   -> Synced " . count($usersWithRfid) . " RFID cards from users." . PHP_EOL;

    echo "=== MIGRATION COMPLETED SUCCESSFULLY ===" . PHP_EOL;

} catch (Throwable $e) {
    echo "ERROR during migration: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
