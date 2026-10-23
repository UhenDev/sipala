<?php

/**
 * FILE       : admin/peminjaman/index.php
 * MODUL      : Manajemen Transaksi Peminjaman
 * FUNGSI     : Menampilkan daftar transaksi peminjaman, menyetujui permohonan, dan melakukan serah terima alat fisik
 * AKSES      : Administrator / Petugas Laboratorium
 *
 * WORKFLOW STATUS TRANSAKSI:
 *   MENUNGGU (Pengajuan siswa)
 *       ↓ (Disetujui laboran)
 *   DISETUJUI
 *       ↓ (Alat diserahkan + verifikasi kartu RFID / manual)
 *   DIPINJAM (Stok tersedia berkurang, status unit fisik menjadi DIPINJAM)
 *       ↓ (Pengembalian di modul pengembalian)
 *   SELESAI
 *
 * *Kondisi khusus: DITOLAK (permohonan dibatalkan/ditolak), TERLAMBAT (melewati jatuh tempo).
 *
 * ALUR:
 * 1. Memeriksa otorisasi admin/petugas.
 * 2. Menangani aksi POST persetujuan ('approve'), penolakan ('reject'), dan serah terima alat ('handover').
 * 3. Menjalankan alokasi unit fisik dalam blok transaksi database dengan locking (FOR UPDATE).
 * 4. Menyinkronkan stok master peralatan dan mencatat audit log.
 * 5. Menampilkan seluruh data transaksi dengan filter pencarian dan badge status.
 */

require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/qrcode.php';
require_once __DIR__ . '/../../includes/whatsapp.php';

// Handle serahkan alat & persetujuan via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $act = (string)($_POST['action'] ?? '');
    $rfidTap = trim((string)($_POST['rfid_tap'] ?? ''));
    $isManual = !empty($_POST['is_manual']);

    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT id, kode_peminjaman, status, user_id, tanggal_rencana_kembali FROM peminjaman WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $p = $q->fetch();

        if (!$p) {
            throw new RuntimeException('Peminjaman tidak ditemukan.');
        }

        $kodePinjam = (string)$p['kode_peminjaman'];
        $peminjamUserId = (int)$p['user_id'];

        if ($act === 'approve' && $p['status'] === 'MENUNGGU') {
            $q = $pdo->prepare('UPDATE peminjaman SET status = "DISETUJUI", disetujui_oleh = ?, tanggal_disetujui = NOW() WHERE id = ?');
            $q->execute([(int)$_SESSION['user_id'], $id]);
            $pdo->commit();

            audit_log('APPROVE', 'PEMINJAMAN', $id, "Menyetujui pengajuan peminjaman $kodePinjam");
            send_notification($peminjamUserId, 'Pengajuan Peminjaman Disetujui!', "Pengajuan peminjaman alat lab ($kodePinjam) telah disetujui. Silakan datang ke ruang laboratorium untuk mengambil alat.", 'success', 'index.php');

            // Kirim Notifikasi WhatsApp ke Siswa
            try {
                $stInfo = $pdo->prepare("
                    SELECT u.nama, u.no_hp, p.tanggal_rencana_kembali,
                           GROUP_CONCAT(pr.nama_barang SEPARATOR ', ') AS daftar_alat
                    FROM peminjaman p
                    JOIN users u ON u.id = p.user_id
                    JOIN detail_peminjaman dp ON dp.peminjaman_id = p.id
                    JOIN peralatan pr ON pr.id = dp.peralatan_id
                    WHERE p.id = ? GROUP BY p.id
                ");
                $stInfo->execute([$id]);
                $info = $stInfo->fetch();
                if ($info && !empty($info['no_hp'])) {
                    send_wa_notification('disetujui', [
                        'nama' => $info['nama'],
                        'no_hp' => $info['no_hp'],
                        'kode_peminjaman' => $kodePinjam,
                        'alat' => $info['daftar_alat'],
                        'tgl_kembali' => $info['tanggal_rencana_kembali'],
                        'peminjaman_id' => $id
                    ]);
                }
            } catch (Throwable $eWa) {
                error_log('Gagal kirim WA approval: ' . $eWa->getMessage());
            }

            flash('success', 'Pengajuan peminjaman berhasil disetujui. Siswa dapat mengambil barang di laboratorium.');
            redirect('index.php');
        } elseif ($act === 'reject' && in_array($p['status'], ['MENUNGGU', 'DISETUJUI'], true)) {
            $alasan = trim((string)($_POST['alasan_penolakan'] ?? 'Tidak memenuhi syarat / alat sedang diperlukan untuk keperluan lain'));
            $q = $pdo->prepare('UPDATE peminjaman SET status = "DITOLAK", disetujui_oleh = ?, tanggal_disetujui = NOW(), alasan_penolakan = ? WHERE id = ?');
            $q->execute([(int)$_SESSION['user_id'], $alasan, $id]);
            $pdo->commit();

            audit_log('REJECT', 'PEMINJAMAN', $id, "Menolak pengajuan peminjaman $kodePinjam: $alasan");
            send_notification($peminjamUserId, 'Pengajuan Peminjaman Ditolak', "Pengajuan peminjaman ($kodePinjam) ditolak oleh petugas lab. Alasan: $alasan", 'danger', 'index.php');

            // Kirim Notifikasi WhatsApp ke Siswa
            try {
                $stInfo = $pdo->prepare("
                    SELECT u.nama, u.no_hp, GROUP_CONCAT(pr.nama_barang SEPARATOR ', ') AS daftar_alat
                    FROM peminjaman p
                    JOIN users u ON u.id = p.user_id
                    JOIN detail_peminjaman dp ON dp.peminjaman_id = p.id
                    JOIN peralatan pr ON pr.id = dp.peralatan_id
                    WHERE p.id = ? GROUP BY p.id
                ");
                $stInfo->execute([$id]);
                $info = $stInfo->fetch();
                if ($info && !empty($info['no_hp'])) {
                    send_wa_notification('ditolak', [
                        'nama' => $info['nama'],
                        'no_hp' => $info['no_hp'],
                        'kode_peminjaman' => $kodePinjam,
                        'alat' => $info['daftar_alat'],
                        'alasan' => $alasan,
                        'peminjaman_id' => $id
                    ]);
                }
            } catch (Throwable $eWa) {
                error_log('Gagal kirim WA reject: ' . $eWa->getMessage());
            }

            flash('success', 'Pengajuan peminjaman ditolak.');
            redirect('index.php');
        } elseif ($act === 'borrow' && in_array($p['status'], ['MENUNGGU', 'DISETUJUI', 'SIAP_DIAMBIL'], true)) {
            // Validasi wajib: harus ada tap RFID atau centang konfirmasi manual
            if ($rfidTap === '' && !$isManual) {
                throw new RuntimeException('Silakan tempelkan kartu RFID siswa ATAU centang opsi konfirmasi serah terima manual.');
            }

            // Ambil rincian detail peminjaman
            $d = $pdo->prepare('SELECT id, peralatan_id, jumlah FROM detail_peminjaman WHERE peminjaman_id = ?');
            $d->execute([$id]);
            $items = $d->fetchAll();

            // Cek apakah ada unit fisik spesifik yang dipindai petugas via barcode scanner
            $scannedUnitsJson = trim((string)($_POST['scanned_units'] ?? ''));
            $scannedUnits = [];
            if ($scannedUnitsJson !== '') {
                $decoded = json_decode($scannedUnitsJson, true);
                if (is_array($decoded)) {
                    $scannedUnits = $decoded;
                }
            }

            if (!empty($scannedUnits)) {
                // ALUR UTAMA: PENYERAHAN DENGAN VALIDASI BARCODE SCANNER
                $stCheckUnit = $pdo->prepare("
                    SELECT id, peralatan_id, kode_unit, barcode, kondisi, status 
                    FROM unit_peralatan 
                    WHERE id = ? FOR UPDATE
                ");
                $stUpdUnit = $pdo->prepare("UPDATE unit_peralatan SET status = 'DIPINJAM' WHERE id = ?");
                $stUpdDet = $pdo->prepare("
                    UPDATE detail_peminjaman 
                    SET unit_peralatan_id = ?, kode_unit_snapshot = ?, barcode_snapshot = ?, jumlah = 1, kondisi_awal = ? 
                    WHERE id = ?
                ");
                $stInsDet = $pdo->prepare("
                    INSERT INTO detail_peminjaman (peminjaman_id, peralatan_id, unit_peralatan_id, kode_unit_snapshot, barcode_snapshot, jumlah, kondisi_awal) 
                    VALUES (?, ?, ?, ?, ?, 1, ?)
                ");

                $usedUnitIds = [];
                $processedDetailIds = [];

                foreach ($scannedUnits as $sc) {
                    $uId = (int)($sc['unit_id'] ?? 0);
                    if ($uId <= 0 || in_array($uId, $usedUnitIds, true)) {
                        continue;
                    }
                    $usedUnitIds[] = $uId;

                    $stCheckUnit->execute([$uId]);
                    $uData = $stCheckUnit->fetch();
                    if (!$uData) {
                        throw new RuntimeException("Unit fisik dengan ID #$uId tidak ditemukan di basis data.");
                    }
                    if ($uData['status'] === 'DIPINJAM') {
                        throw new RuntimeException("Unit '{$uData['kode_unit']}' ({$uData['barcode']}) sudah berstatus DIPINJAM pada transaksi lain!");
                    }
                    if ($uData['status'] === 'RUSAK' || $uData['kondisi'] === 'Rusak Berat') {
                        throw new RuntimeException("Unit '{$uData['kode_unit']}' dalam kondisi Rusak Berat dan tidak dapat diserahkan.");
                    }

                    // Cocokkan dengan salah satu baris detail peminjaman
                    $detailTargetId = 0;
                    foreach ($items as $it) {
                        if ((int)$it['peralatan_id'] === (int)$uData['peralatan_id'] && !in_array((int)$it['id'], $processedDetailIds, true)) {
                            $detailTargetId = (int)$it['id'];
                            $processedDetailIds[] = $detailTargetId;
                            break;
                        }
                    }

                    $stUpdUnit->execute([$uId]);
                    $unitKondisi = $uData['kondisi'] ?: 'Baik';
                    $barcodeVal = $uData['barcode'] ?: $uData['kode_unit'];

                    if ($detailTargetId > 0) {
                        $stUpdDet->execute([$uId, $uData['kode_unit'], $barcodeVal, $unitKondisi, $detailTargetId]);
                    } else {
                        $stInsDet->execute([$id, (int)$uData['peralatan_id'], $uId, $uData['kode_unit'], $barcodeVal, $unitKondisi]);
                    }

                    sync_peralatan_stock((int)$uData['peralatan_id']);
                }
            } else {
                // ALUR KEDUA: ALOKASI OTOMATIS JIKA SERAH TERIMA MANUAL
                foreach ($items as $it) {
                    $alatId = (int)$it['peralatan_id'];
                    $qty = (int)$it['jumlah'];

                    // Alokasikan unit fisik berstatus TERSEDIA
                    $stAvail = $pdo->prepare("
                        SELECT id, kode_unit, barcode, kondisi 
                        FROM unit_peralatan 
                        WHERE peralatan_id = ? AND status = 'TERSEDIA' AND kondisi != 'Rusak Berat' 
                        ORDER BY id ASC
                        LIMIT ? FOR UPDATE
                    ");
                    $stAvail->bindValue(1, $alatId, PDO::PARAM_INT);
                    $stAvail->bindValue(2, $qty, PDO::PARAM_INT);
                    $stAvail->execute();
                    $availUnits = $stAvail->fetchAll();

                    if (count($availUnits) < $qty) {
                        throw new RuntimeException("Unit fisik yang siap dipinjam tidak mencukupi untuk diserahkan (Tersedia: " . count($availUnits) . " unit).");
                    }

                    $stUpdUnit = $pdo->prepare("UPDATE unit_peralatan SET status = 'DIPINJAM' WHERE id = ?");
                    $stUpdDet = $pdo->prepare("UPDATE detail_peminjaman SET unit_peralatan_id = ?, kode_unit_snapshot = ?, barcode_snapshot = ?, jumlah = 1, kondisi_awal = ? WHERE id = ?");
                    $stInsDet = $pdo->prepare("INSERT INTO detail_peminjaman (peminjaman_id, peralatan_id, unit_peralatan_id, kode_unit_snapshot, barcode_snapshot, jumlah, kondisi_awal) VALUES (?, ?, ?, ?, ?, 1, ?)");

                    foreach ($availUnits as $idx => $u) {
                        $stUpdUnit->execute([$u['id']]);
                        $unitKondisi = $u['kondisi'] ?: 'Baik';
                        $barcodeVal = $u['barcode'] ?: $u['kode_unit'];
                        if ($idx === 0) {
                            $stUpdDet->execute([$u['id'], $u['kode_unit'], $barcodeVal, $unitKondisi, (int)$it['id']]);
                        } else {
                            $stInsDet->execute([$id, $alatId, $u['id'], $u['kode_unit'], $barcodeVal, $unitKondisi]);
                        }
                    }

                    sync_peralatan_stock($alatId);
                }
            }

            if ($rfidTap !== '') {
                // Update status ke DIPINJAM dan simpan verifikasi RFID
                $stmt = $pdo->prepare('
                    UPDATE peminjaman 
                    SET status = "DIPINJAM", 
                        tanggal_pinjam = CURDATE(),
                        rfid_uid = ?,
                        rfid_verified_at = NOW(),
                        rfid_verified_by = ?
                    WHERE id = ?
                ');
                $stmt->execute([
                    $rfidTap,
                    (int)$_SESSION['user_id'],
                    $id
                ]);
            } else {
                // Serah terima manual tanpa kartu RFID
                $stmt = $pdo->prepare('
                    UPDATE peminjaman 
                    SET status = "DIPINJAM", 
                        tanggal_pinjam = CURDATE(),
                        rfid_verified_by = ?
                    WHERE id = ?
                ');
                $stmt->execute([
                    (int)$_SESSION['user_id'],
                    $id
                ]);
            }

            $pdo->commit();

            audit_log('BORROW', 'PEMINJAMAN', $id, "Menyerahkan alat untuk transaksi $kodePinjam (" . ($rfidTap !== '' ? "Verifikasi RFID: $rfidTap" : "Manual") . ")");
            send_notification($peminjamUserId, 'Alat Laboratorium Telah Diterima', "Alat untuk transaksi $kodePinjam telah diserahkan kepada Anda. Harap merawat dan mengembalikan sebelum " . date('d-m-Y', strtotime($p['tanggal_rencana_kembali'])), 'info', 'index.php');

            // Kirim Notifikasi WhatsApp Serah Terima ke Siswa
            try {
                $stInfo = $pdo->prepare("
                    SELECT u.nama, u.no_hp, p.tanggal_rencana_kembali,
                           GROUP_CONCAT(CONCAT(pr.nama_barang, ' (', COALESCE(up.kode_unit, '-'), ')') SEPARATOR ', ') AS rincian_barang
                    FROM peminjaman p
                    JOIN users u ON u.id = p.user_id
                    JOIN detail_peminjaman dp ON dp.peminjaman_id = p.id
                    JOIN peralatan pr ON pr.id = dp.peralatan_id
                    LEFT JOIN unit_peralatan up ON up.id = dp.unit_peralatan_id
                    WHERE p.id = ? GROUP BY p.id
                ");
                $stInfo->execute([$id]);
                $info = $stInfo->fetch();
                if ($info && !empty($info['no_hp'])) {
                    send_wa_notification('serah_terima', [
                        'nama' => $info['nama'],
                        'no_hp' => $info['no_hp'],
                        'kode_peminjaman' => $kodePinjam,
                        'alat' => $info['rincian_barang'],
                        'kode_unit' => $info['rincian_barang'],
                        'tgl_pinjam' => date('Y-m-d'),
                        'tgl_kembali' => $p['tanggal_rencana_kembali'],
                        'peminjaman_id' => $id
                    ]);
                }
            } catch (Throwable $eWa) {
                error_log('Gagal kirim WA serah terima: ' . $eWa->getMessage());
            }

            flash('success', 'Alat berhasil diserahkan dan unit fisik telah dialokasikan ke peminjam.');
            redirect('index.php');
        } else {
            throw new RuntimeException('Aksi tidak sesuai dengan status transaksi saat ini.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
        redirect('index.php');
    }
}

// Filter dan Pencarian
$searchQuery = trim((string)($_GET['q'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? ''));

// Ambil statistik jumlah transaksi per status untuk quick tabs
$statCounts = $pdo->query("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'MENUNGGU' THEN 1 ELSE 0 END) AS menunggu,
        SUM(CASE WHEN status = 'DISETUJUI' THEN 1 ELSE 0 END) AS disetujui,
        SUM(CASE WHEN status = 'DIPINJAM' THEN 1 ELSE 0 END) AS dipinjam,
        SUM(CASE WHEN status = 'SELESAI' THEN 1 ELSE 0 END) AS selesai
    FROM peminjaman
")->fetch() ?: ['total' => 0, 'menunggu' => 0, 'disetujui' => 0, 'dipinjam' => 0, 'selesai' => 0];

$totalAllRows = (int)($statCounts['total'] ?? 0);
$countMenunggu = (int)($statCounts['menunggu'] ?? 0);
$countDisetujui = (int)($statCounts['disetujui'] ?? 0);
$countDipinjam = (int)($statCounts['dipinjam'] ?? 0);
$countSelesai = (int)($statCounts['selesai'] ?? 0);

// Bangun query transaksi dengan filter dinamis
$sql = '
    SELECT p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_pinjam, p.tanggal_rencana_kembali, 
           p.status, p.rfid_uid, p.rfid_verified_at, p.keperluan,
           u.nama AS nama_peminjam, u.nis, u.kelas, u.rfid_uid AS user_rfid, u.no_hp,
           COALESCE(SUM(d.jumlah), 0) AS total_item 
    FROM peminjaman p 
    LEFT JOIN users u ON u.id = p.user_id 
    LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id 
    WHERE 1=1 
';
$params = [];

if ($filterStatus !== '') {
    $sql .= ' AND p.status = ? ';
    $params[] = $filterStatus;
}

if ($searchQuery !== '') {
    $sql .= ' AND (
        p.kode_peminjaman LIKE ? 
        OR p.rfid_uid = ? 
        OR u.nama LIKE ? 
        OR u.nis LIKE ? 
        OR u.kelas LIKE ? 
        OR u.rfid_uid = ? 
        OR p.keperluan LIKE ?
        OR EXISTS (
            SELECT 1 FROM detail_peminjaman dp 
            LEFT JOIN unit_peralatan up ON up.id = dp.unit_peralatan_id 
            LEFT JOIN peralatan pe ON pe.id = dp.peralatan_id 
            WHERE dp.peminjaman_id = p.id 
            AND (up.kode_unit LIKE ? OR up.rfid_uid = ? OR up.serial_number LIKE ? OR pe.nama_barang LIKE ? OR pe.kode_barang LIKE ?)
        )
    ) ';
    $qLike = '%' . $searchQuery . '%';
    $params = array_merge($params, [
        $qLike, $searchQuery, $qLike, $qLike, $qLike, $searchQuery, $qLike,
        $qLike, $searchQuery, $qLike, $qLike, $qLike
    ]);
}

$sql .= ' GROUP BY p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.status, p.rfid_uid, p.rfid_verified_at, p.keperluan, u.nama, u.nis, u.kelas, u.rfid_uid, u.no_hp ';
$sql .= ' ORDER BY p.id DESC ';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

page_start('Peminjaman Alat Lab', 'Kelola transaksi peminjaman dan verifikasi fisik', 'peminjaman');
?>

<div class="head">
    <div>
        <h2>Data Transaksi Peminjaman</h2>
        <p>Pencatatan peminjaman alat laboratorium, persetujuan pengajuan, dan verifikasi fisik serah terima.</p>
    </div>
    <div class="actions">
        <a class="btn btn-primary" href="tambah.php">+ Peminjaman Baru (Scan RFID / Manual)</a>
    </div>
</div>

<!-- KONSOL PENCARIAN & PEMINDAIAN TRANSAKSI -->
<div class="panel" style="margin-bottom:18px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
    <div style="padding:16px 20px;">
        <form method="GET" action="index.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div class="field" style="flex:1;min-width:260px;">
                <label style="display:flex;align-items:center;gap:6px;font-weight:600;font-size:12px;color:#334155;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    Pencarian Transaksi Peminjaman / Scan RFID Siswa
                </label>
                <div style="position:relative;">
                    <input type="text" name="q" placeholder="Cari kode transaksi (PJM-...), nama siswa, NIS, kelas, nama alat, kode unit, atau tap kartu RFID..." 
                           value="<?= e($searchQuery) ?>" autofocus class="rfid-autofocus" data-rfid-scanner="true"
                           style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:6px;font-size:13.5px;background:#ffffff;">
                </div>
            </div>

            <div class="field" style="min-width:180px;">
                <label style="font-weight:600;font-size:12px;color:#334155;">Filter Status</label>
                <select name="status" style="padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#ffffff;">
                    <option value="">-- Semua Status --</option>
                    <option value="MENUNGGU" <?= $filterStatus === 'MENUNGGU' ? 'selected' : '' ?>>MENUNGGU (Pengajuan Baru)</option>
                    <option value="DISETUJUI" <?= $filterStatus === 'DISETUJUI' ? 'selected' : '' ?>>DISETUJUI (Siap Diambil)</option>
                    <option value="DIPINJAM" <?= $filterStatus === 'DIPINJAM' ? 'selected' : '' ?>>DIPINJAM (Sedang Dipinjam)</option>
                    <option value="SELESAI" <?= $filterStatus === 'SELESAI' ? 'selected' : '' ?>>SELESAI (Dikembalikan)</option>
                    <option value="DITOLAK" <?= $filterStatus === 'DITOLAK' ? 'selected' : '' ?>>DITOLAK</option>
                    <option value="DIBATALKAN" <?= $filterStatus === 'DIBATALKAN' ? 'selected' : '' ?>>DIBATALKAN</option>
                </select>
            </div>

            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary" style="height:38px;padding:0 18px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    Cari
                </button>
                <?php if ($searchQuery !== '' || $filterStatus !== ''): ?>
                    <a href="index.php" class="btn" style="height:38px;">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($searchQuery !== '' || $filterStatus !== ''): ?>
            <div style="margin-top:12px;font-size:12.5px;color:#64748b;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <span>Hasil pencarian:</span>
                <strong style="color:#0f172a;"><?= count($rows) ?> transaksi ditemukan</strong>
                <?php if ($searchQuery !== ''): ?>
                    <span>untuk kata kunci "<strong><?= e($searchQuery) ?></strong>"</span>
                <?php endif; ?>
                <?php if ($filterStatus !== ''): ?>
                    <span>dengan status <strong><?= e($filterStatus) ?></strong></span>
                <?php endif; ?>
                <a href="index.php" style="color:#2563eb;text-decoration:underline;margin-left:6px;">Tampilkan semua (<?= $totalAllRows ?>)</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- QUICK STATUS TABS -->
<div style="display:flex;gap:8px;margin-bottom:18px;border-bottom:1px solid #dfe3e8;padding-bottom:10px;overflow-x:auto;-webkit-overflow-scrolling:touch;">
    <a href="index.php<?= $searchQuery !== '' ? '?q=' . urlencode($searchQuery) : '' ?>" 
       class="btn <?= $filterStatus === '' ? 'btn-primary' : '' ?>" style="font-weight:600;font-size:12.5px;">
        Semua (<?= $totalAllRows ?>)
    </a>
    <a href="index.php?status=MENUNGGU<?= $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '' ?>" 
       class="btn <?= $filterStatus === 'MENUNGGU' ? 'btn-primary' : '' ?>" style="font-weight:600;font-size:12.5px;">
        Menunggu (<?= $countMenunggu ?>)
    </a>
    <a href="index.php?status=DISETUJUI<?= $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '' ?>" 
       class="btn <?= $filterStatus === 'DISETUJUI' ? 'btn-primary' : '' ?>" style="font-weight:600;font-size:12.5px;">
        Siap Diambil (<?= $countDisetujui ?>)
    </a>
    <a href="index.php?status=DIPINJAM<?= $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '' ?>" 
       class="btn <?= $filterStatus === 'DIPINJAM' ? 'btn-primary' : '' ?>" style="font-weight:600;font-size:12.5px;">
        Sedang Dipinjam (<?= $countDipinjam ?>)
    </a>
    <a href="index.php?status=SELESAI<?= $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '' ?>" 
       class="btn <?= $filterStatus === 'SELESAI' ? 'btn-primary' : '' ?>" style="font-weight:600;font-size:12.5px;">
        Selesai (<?= $countSelesai ?>)
    </a>
</div>

<div class="panel">
    <div class="panel-title">
        <span>Daftar Transaksi Peminjaman</span>
        <span style="font-size:12px;color:#7b8492;font-weight:normal;">Menampilkan <?= count($rows) ?> dari total <?= $totalAllRows ?> transaksi</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:40px;">No</th>
                    <th>Kode Transaksi</th>
                    <th>Peminjam (Siswa)</th>
                    <th>Tgl Pengajuan / Pinjam</th>
                    <th>Rencana Kembali</th>
                    <th>Total Item</th>
                    <th>Metode Verifikasi</th>
                    <th>Status</th>
                    <th style="text-align:right;">Tindakan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty">
                                <?php if ($searchQuery !== '' || $filterStatus !== ''): ?>
                                    Tidak ada catatan transaksi peminjaman yang sesuai dengan filter pencarian.
                                    <br><a href="index.php" class="btn btn-sm" style="margin-top:8px;">Reset Pencarian</a>
                                <?php else: ?>
                                    Belum ada catatan transaksi peminjaman.
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    // Ambil seluruh rincian detail barang peminjaman untuk modal serah terima
                    $stAllDetails = $pdo->query("
                        SELECT dp.peminjaman_id, dp.id, dp.peralatan_id, dp.jumlah, pr.nama_barang, pr.kode_barang
                        FROM detail_peminjaman dp
                        JOIN peralatan pr ON pr.id = dp.peralatan_id
                    ");
                    $allDetailsGrouped = [];
                    while ($dRow = $stAllDetails->fetch(PDO::FETCH_ASSOC)) {
                        $pId = (int)$dRow['peminjaman_id'];
                        if (!isset($allDetailsGrouped[$pId])) {
                            $allDetailsGrouped[$pId] = [];
                        }
                        $allDetailsGrouped[$pId][] = [
                            'id' => (int)$dRow['id'],
                            'peralatan_id' => (int)$dRow['peralatan_id'],
                            'nama_barang' => $dRow['nama_barang'],
                            'kode_barang' => $dRow['kode_barang'],
                            'jumlah' => (int)$dRow['jumlah']
                        ];
                    }
                    ?>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <strong style="font-family:monospace;color:#1e40af;"><?= e($r['kode_peminjaman']) ?></strong>
                                <?php if (!empty($r['keperluan'])): ?>
                                    <div style="font-size:11px;color:#64748b;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($r['keperluan']) ?>">
                                        <?= e($r['keperluan']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><strong><?= e($r['nama_peminjam'] ?? '-') ?></strong></div>
                                <div style="font-size:12px;color:#64748b;">
                                    NIS: <?= e($r['nis'] ?: '-') ?> &bull; <?= e($r['kelas'] ?: '-') ?>
                                </div>
                            </td>
                            <td>
                                <div><?= e(date('d-m-Y', strtotime($r['tanggal_pinjam'] ?: ($r['tanggal_pengajuan'] ?: 'now')))) ?></div>
                            </td>
                            <td>
                                <div style="color:#d97706;font-weight:600;">
                                    <?= e(date('d-m-Y', strtotime($r['tanggal_rencana_kembali']))) ?>
                                </div>
                            </td>
                            <td>
                                <span style="font-weight:700;font-size:14px;"><?= $r['total_item'] ?></span> unit
                            </td>
                            <td>
                                <?php if (!empty($r['rfid_verified_at']) && !empty($r['rfid_uid'])): ?>
                                    <span class="badge aktif" title="Diverifikasi chip RFID: <?= e($r['rfid_uid']) ?>">
                                        RFID Terverifikasi
                                    </span>
                                <?php elseif (in_array($r['status'], ['DIPINJAM', 'SELESAI'], true)): ?>
                                    <span class="badge nonaktif" title="Serah terima fisik tanpa kartu RFID">
                                        Manual (Tanpa Kartu)
                                    </span>
                                <?php elseif ($r['status'] === 'DISETUJUI'): ?>
                                    <span class="badge DISETUJUI">
                                        Siap Serah Terima
                                    </span>
                                <?php else: ?>
                                    <span class="badge nonaktif">
                                        Belum Diserahkan
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= status_badge($r['status']) ?>
                            </td>
                            <td style="text-align:right;">
                                <div class="actions" style="justify-content:flex-end;">
                                    <a class="btn btn-sm" href="detail.php?id=<?= $r['id'] ?>">Detail</a>
                                    
                                    <?php 
                                    $isOverdue = ($r['status'] === 'DIPINJAM' && !empty($r['tanggal_rencana_kembali']) && date('Y-m-d') > $r['tanggal_rencana_kembali']);
                                    $rawHp = (string)($r['no_hp'] ?? '');
                                    ?>
                                    <?php if ($rawHp !== ''): ?>
                                        <button type="button" class="btn btn-sm" data-wa-target
                                                data-wa-id="<?= $r['id'] ?>"
                                                data-wa-nama="<?= e($r['nama_peminjam'] ?? '') ?>"
                                                data-wa-kelas="<?= e($r['kelas'] ?? '') ?>"
                                                data-wa-phone="<?= e($rawHp) ?>"
                                                data-wa-kode="<?= e($r['kode_peminjaman']) ?>"
                                                data-wa-alat="<?= e($r['daftar_alat'] ?? 'Alat Lab') ?>"
                                                data-wa-kembali="<?= e(date('d-m-Y', strtotime($r['tanggal_rencana_kembali']))) ?>"
                                                data-wa-type="<?= $isOverdue ? 'terlambat' : ($r['status'] === 'MENUNGGU' ? 'disetujui' : 'pengingat') ?>"
                                                style="background:#22c55e;color:#ffffff;border:none;display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;"
                                                title="Kirim pesan via Bot WhatsApp ke <?= e($rawHp) ?>">
                                            📱 WA
                                        </button>
                                    <?php endif; ?>

                                    
                                    <?php if ($r['status'] === 'MENUNGGU'): ?>
                                        <form method="post" id="form_approve_<?= $r['id'] ?>" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="button" class="btn btn-sm btn-ok" onclick="confirmAction('form_approve_<?= $r['id'] ?>', 'Setujui Pengajuan?', 'Setujui pengajuan peminjaman untuk <?= e(addslashes($r['nama_peminjam'] ?? '')) ?>?', 'Ya, Setujui', '#10b981')">Setujui</button>
                                        </form>
                                        <form method="post" id="form_reject_<?= $r['id'] ?>" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="button" class="btn btn-sm btn-danger" onclick="confirmAction('form_reject_<?= $r['id'] ?>', 'Tolak Pengajuan?', 'Tolak pengajuan peminjaman untuk <?= e(addslashes($r['nama_peminjam'] ?? '')) ?>?', 'Ya, Tolak', '#ef4444')">Tolak</button>
                                        </form>
                                    <?php elseif (in_array($r['status'], ['DISETUJUI', 'SIAP_DIAMBIL'], true)): 
                                        $loanItemsData = $allDetailsGrouped[(int)$r['id']] ?? [];
                                    ?>
                                        <button type="button" class="btn btn-sm btn-primary" 
                                                data-loan-items="<?= htmlspecialchars(json_encode($loanItemsData), ENT_QUOTES, 'UTF-8') ?>"
                                                onclick="openHandoverModal(<?= $r['id'] ?>, '<?= e($r['kode_peminjaman']) ?>', '<?= e(addslashes($r['nama_peminjam'] ?? 'Siswa')) ?>', '<?= e($r['nis'] ?: '-') ?>', '<?= e($r['kelas'] ?: '-') ?>', '<?= e($r['user_rfid'] ?: '') ?>', this.dataset.loanItems)">
                                            Serahkan Alat
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL SERAH TERIMA ALAT DUA TAHAP (RFID SISWA + BARCODE ALAT) -->
<div class="modal" id="handoverModal">
    <div class="modal-box" style="max-width:620px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;border-bottom:1px solid #e2e8f0;padding-bottom:10px;">
            <h3 style="margin:0;font-size:16px;display:flex;align-items:center;gap:8px;color:#0f172a;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                Serah Terima Fisik & Validasi Barcode Alat
            </h3>
            <button type="button" onclick="closeHandoverModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;line-height:1;">&times;</button>
        </div>

        <div id="handoverDesc" style="margin-bottom:14px;color:#334155;font-size:13px;background:#f8fafc;padding:10px 14px;border-radius:6px;border-left:4px solid #2563eb;"></div>
        
        <form method="post" id="handoverForm">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="handoverId">
            <input type="hidden" name="action" value="borrow">
            <input type="hidden" name="scanned_units" id="scannedUnitsPayload" value="[]">

            <!-- TAHAP 1: VERIFIKASI IDENTITAS SISWA (RFID READER) -->
            <div style="background:#ffffff;border:1.5px solid #cbd5e1;border-radius:8px;padding:14px;margin-bottom:14px;">
                <div style="font-weight:700;font-size:13px;color:#0f172a;display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span style="display:flex;align-items:center;gap:6px;">
                        <span style="display:inline-block;width:20px;height:20px;line-height:20px;text-align:center;background:#2563eb;color:#fff;border-radius:50%;font-size:11px;font-weight:800;">1</span>
                        Verifikasi Kartu Pelajar (RFID Reader)
                    </span>
                    <span id="rfidMatchBadge" style="font-size:11px;font-weight:700;"></span>
                </div>
                <div class="field" style="margin-bottom:8px;">
                    <input type="password" name="rfid_tap" id="modalRfidInput" placeholder="Tempelkan kartu RFID siswa ke reader..." 
                           data-rfid-scanner="true" autocomplete="off" style="font-family:monospace;font-size:14px;background:#f8fafc;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;width:100%;">
                    <small style="color:#64748b;font-size:11px;">Arahkan kursor ke kolom ini, lalu tap kartu RFID siswa.</small>
                </div>
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#475569;cursor:pointer;">
                    <input type="checkbox" name="is_manual" id="manualCheck" value="1" onchange="toggleManualHandover(this)">
                    <span>Verifikasi manual (siswa tidak membawa kartu RFID / tamu lab)</span>
                </label>
            </div>

            <!-- TAHAP 2: VERIFIKASI BARCODE UNIT ALAT (BARCODE SCANNER) -->
            <div style="background:#ffffff;border:1.5px solid #2563eb;border-radius:8px;padding:14px;margin-bottom:14px;">
                <div style="font-weight:700;font-size:13px;color:#1e40af;display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span style="display:flex;align-items:center;gap:6px;">
                        <span style="display:inline-block;width:20px;height:20px;line-height:20px;text-align:center;background:#1e40af;color:#fff;border-radius:50%;font-size:11px;font-weight:800;">2</span>
                        Pemindaian Barcode Fisik Alat
                    </span>
                    <span id="scanProgressBadge" style="font-size:11.5px;font-weight:700;background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:12px;">0 / 0 Unit</span>
                </div>

                <!-- Daftar alat yang harus dipindai -->
                <div id="requiredItemsList" style="margin-bottom:10px;font-size:12px;display:flex;flex-direction:column;gap:6px;"></div>

                <!-- Input scan barcode scanner -->
                <div class="field" style="margin-bottom:8px;">
                    <label style="font-size:12px;font-weight:700;color:#0f172a;">Arahkan Barcode Scanner ke Stiker Alat:</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="modalBarcodeScanInput" placeholder="Scan barcode alat (SIP-ALT-XXXXXX)..." 
                               autocomplete="off" style="font-family:monospace;font-size:14px;font-weight:700;border:1.5px solid #2563eb;padding:8px 12px;border-radius:6px;flex:1;">
                        <button type="button" class="btn btn-sm btn-primary" onclick="triggerBarcodeLookup()" style="padding:0 14px;">Scan</button>
                    </div>
                    <small style="color:#64748b;font-size:11px;">Mendukung pemindai USB HID (otomatis Enter) atau input manual.</small>
                </div>

                <!-- Pesan status hasil pemindaian -->
                <div id="barcodeFeedback" style="display:none;padding:8px 12px;border-radius:6px;font-size:12px;margin-bottom:8px;"></div>

                <!-- Unit yang sudah terverifikasi dan siap diserahkan -->
                <div id="scannedUnitsContainer" style="display:flex;flex-direction:column;gap:6px;margin-top:6px;"></div>
            </div>

            <div class="modal-actions" style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn" onclick="closeHandoverModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitHandover" disabled>
                    Konfirmasi Serah Terima Alat
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let currentLoanItems = [];
let scannedUnitsList = [];
let expectedUserRfid = '';

function openHandoverModal(id, kode, nama, nis, kelas, userRfid, itemsJson) {
    document.getElementById('handoverId').value = id;
    expectedUserRfid = (userRfid || '').trim();
    
    // Parse rincian alat yang diajukan
    try {
        currentLoanItems = typeof itemsJson === 'string' ? JSON.parse(itemsJson) : (itemsJson || []);
    } catch(e) {
        currentLoanItems = [];
    }

    scannedUnitsList = [];
    document.getElementById('scannedUnitsPayload').value = '[]';

    document.getElementById('handoverDesc').innerHTML = 
        'Transaksi: <strong>' + escapeHtml(kode) + '</strong> &bull; Peminjam: <strong>' + escapeHtml(nama) + '</strong> (' + escapeHtml(nis) + ' - ' + escapeHtml(kelas) + ')';

    // Reset input RFID & Status
    const rfidInp = document.getElementById('modalRfidInput');
    rfidInp.value = '';
    rfidInp.disabled = false;
    document.getElementById('manualCheck').checked = false;
    document.getElementById('rfidMatchBadge').innerHTML = '';

    // Reset Barcode Scan Area
    const barInp = document.getElementById('modalBarcodeScanInput');
    barInp.value = '';
    const fb = document.getElementById('barcodeFeedback');
    fb.style.display = 'none';

    renderRequiredItemsList();
    renderScannedUnits();
    updateHandoverSubmitState();

    document.getElementById('handoverModal').classList.add('show');

    // Fokus ke RFID jika belum ada, atau ke Barcode Scanner jika RFID sudah terisi
    setTimeout(() => {
        if (expectedUserRfid === '') {
            barInp.focus();
        } else {
            rfidInp.focus();
        }
    }, 200);
}

function closeHandoverModal() {
    document.getElementById('handoverModal').classList.remove('show');
}

function renderRequiredItemsList() {
    const container = document.getElementById('requiredItemsList');
    if (!container) return;
    container.innerHTML = '';

    let totalQty = 0;
    currentLoanItems.forEach(item => {
        totalQty += (parseInt(item.jumlah) || 1);
        const div = document.createElement('div');
        div.style.padding = '8px 10px';
        div.style.background = '#f8fafc';
        div.style.border = '1px solid #e2e8f0';
        div.style.borderRadius = '6px';
        div.style.display = 'flex';
        div.style.justifyContent = 'space-between';
        div.style.alignItems = 'center';
        div.id = 'req_item_' + item.peralatan_id;

        const countScanned = scannedUnitsList.filter(u => u.peralatan_id == item.peralatan_id).length;
        const isComplete = countScanned >= (parseInt(item.jumlah) || 1);

        div.innerHTML = 
            '<div><strong>' + escapeHtml(item.nama_barang) + '</strong> <span style="color:#64748b;">(' + escapeHtml(item.kode_barang) + ')</span></div>' +
            '<span style="font-weight:700;font-size:11.5px;padding:2px 8px;border-radius:12px;' + 
            (isComplete ? 'background:#dcfce7;color:#15803d;' : 'background:#fef3c7;color:#b45309;') + '">' +
            (isComplete ? '✓ Terpenuhi (' + countScanned + '/' + item.jumlah + ')' : 'Perlu Scan (' + countScanned + '/' + item.jumlah + ')') +
            '</span>';

        container.appendChild(div);
    });

    const progressBadge = document.getElementById('scanProgressBadge');
    if (progressBadge) {
        progressBadge.textContent = scannedUnitsList.length + ' / ' + totalQty + ' Unit';
        if (scannedUnitsList.length >= totalQty && totalQty > 0) {
            progressBadge.style.background = '#dcfce7';
            progressBadge.style.color = '#15803d';
        } else {
            progressBadge.style.background = '#dbeafe';
            progressBadge.style.color = '#1e40af';
        }
    }
}

function renderScannedUnits() {
    const container = document.getElementById('scannedUnitsContainer');
    if (!container) return;
    container.innerHTML = '';

    if (scannedUnitsList.length === 0) {
        container.innerHTML = '<div style="font-size:12px;color:#94a3b8;font-style:italic;">Belum ada barcode unit yang dipindai.</div>';
        return;
    }

    scannedUnitsList.forEach((u, idx) => {
        const itemBox = document.createElement('div');
        itemBox.style.padding = '8px 10px';
        itemBox.style.background = '#ecfdf5';
        itemBox.style.border = '1px solid #a7f3d0';
        itemBox.style.borderRadius = '6px';
        itemBox.style.display = 'flex';
        itemBox.style.justifyContent = 'space-between';
        itemBox.style.alignItems = 'center';

        itemBox.innerHTML = 
            '<div style="font-size:12px;">' +
                '<strong style="color:#065f46;">✓ ' + escapeHtml(u.nama_barang) + '</strong> &bull; ' +
                'Barcode: <code style="font-weight:700;color:#047857;">' + escapeHtml(u.barcode) + '</code> (Unit: ' + escapeHtml(u.kode_unit) + ')<br>' +
                '<span style="font-size:11px;color:#047857;">Kondisi: ' + escapeHtml(u.kondisi) + ' &bull; Lokasi: ' + escapeHtml(u.lokasi) + '</span>' +
            '</div>' +
            '<button type="button" onclick="removeScannedUnit(' + idx + ')" style="background:none;border:none;color:#dc2626;cursor:pointer;font-weight:700;font-size:13px;" title="Batalkan unit ini">&times; Hapus</button>';

        container.appendChild(itemBox);
    });
}

function removeScannedUnit(index) {
    scannedUnitsList.splice(index, 1);
    document.getElementById('scannedUnitsPayload').value = JSON.stringify(scannedUnitsList);
    renderRequiredItemsList();
    renderScannedUnits();
    updateHandoverSubmitState();
}

function triggerBarcodeLookup() {
    const inp = document.getElementById('modalBarcodeScanInput');
    const code = inp ? inp.value.trim() : '';
    if (!code) return;

    const loanId = document.getElementById('handoverId').value;
    const fb = document.getElementById('barcodeFeedback');
    fb.style.display = 'block';
    fb.style.background = '#f8fafc';
    fb.style.color = '#334155';
    fb.innerHTML = 'Memverifikasi barcode: <strong>' + escapeHtml(code) + '</strong>...';

    // Cek apakah barcode sudah dipindai dalam sesi serah terima ini
    const isAlreadyScanned = scannedUnitsList.some(u => u.barcode === code || u.kode_unit === code);
    if (isAlreadyScanned) {
        fb.style.background = '#fee2e2';
        fb.style.color = '#b91c1c';
        fb.innerHTML = '<strong>❌ Duplikasi Scan:</strong> Barcode unit <code>' + escapeHtml(code) + '</code> sudah dipindai dalam serah terima ini.';
        inp.value = '';
        inp.focus();
        return;
    }

    fetch('../api_barcode_lookup.php?action=verify_loan_unit&peminjaman_id=' + encodeURIComponent(loanId) + '&barcode=' + encodeURIComponent(code))
        .then(r => r.json())
        .then(data => {
            if (data.success && data.unit) {
                // Cek kuota item ini apakah sudah terpenuhi
                const reqItem = currentLoanItems.find(it => it.peralatan_id == data.unit.peralatan_id);
                const currentCount = scannedUnitsList.filter(u => u.peralatan_id == data.unit.peralatan_id).length;
                const maxAllowed = reqItem ? (parseInt(reqItem.jumlah) || 1) : 0;

                if (currentCount >= maxAllowed) {
                    fb.style.background = '#fef3c7';
                    fb.style.color = '#b45309';
                    fb.innerHTML = '<strong>⚠️ Kuota Terpenuhi:</strong> Jumlah unit untuk <strong>' + escapeHtml(data.unit.nama_barang) + '</strong> sudah lengkap (' + maxAllowed + ' unit). Tidak perlu memindai unit tambahan untuk alat ini.';
                    inp.value = '';
                    inp.focus();
                    return;
                }

                // Tambahkan ke daftar scanned
                scannedUnitsList.push({
                    unit_id: data.unit.id,
                    peralatan_id: data.unit.peralatan_id,
                    nama_barang: data.unit.nama_barang,
                    kode_unit: data.unit.kode_unit,
                    barcode: data.unit.barcode,
                    kondisi: data.unit.kondisi,
                    lokasi: data.unit.lokasi
                });

                document.getElementById('scannedUnitsPayload').value = JSON.stringify(scannedUnitsList);

                fb.style.background = '#dcfce7';
                fb.style.color = '#15803d';
                fb.innerHTML = '<strong>✓ Cocok & Siap Diserahkan:</strong> ' + escapeHtml(data.unit.nama_barang) + ' (' + escapeHtml(data.unit.barcode) + ')';

                inp.value = '';
                renderRequiredItemsList();
                renderScannedUnits();
                updateHandoverSubmitState();
            } else {
                fb.style.background = '#fee2e2';
                fb.style.color = '#b91c1c';
                fb.innerHTML = '<strong>❌ Pemindaian Ditolak:</strong><br>' + escapeHtml(data.message || 'Unit tidak sesuai pengajuan atau tidak tersedia.');
            }
            inp.value = '';
            inp.focus();
        })
        .catch(err => {
            fb.style.background = '#fee2e2';
            fb.style.color = '#b91c1c';
            fb.innerHTML = 'Gagal menghubungi server validasi barcode.';
        });
}

function toggleManualHandover(chk) {
    const rfidInp = document.getElementById('modalRfidInput');
    const badge = document.getElementById('rfidMatchBadge');
    if (chk.checked) {
        rfidInp.value = '';
        rfidInp.disabled = true;
        badge.innerHTML = '<span style="color:#d97706;">(Mode Manual Aktif)</span>';
    } else {
        rfidInp.disabled = false;
        badge.innerHTML = '';
        rfidInp.focus();
    }
    updateHandoverSubmitState();
}

function updateHandoverSubmitState() {
    const btn = document.getElementById('btnSubmitHandover');
    if (!btn) return;

    const rfidVal = document.getElementById('modalRfidInput').value.trim();
    const isManual = document.getElementById('manualCheck').checked;

    // Hitung total item yang harus dipindai
    let totalRequired = 0;
    currentLoanItems.forEach(it => {
        totalRequired += (parseInt(it.jumlah) || 1);
    });

    const isRfidValid = (rfidVal !== '' || isManual);
    const isBarcodeComplete = (scannedUnitsList.length >= totalRequired) || isManual;

    if (isRfidValid && isBarcodeComplete) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    } else {
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Listener RFID input
    const rfidInp = document.getElementById('modalRfidInput');
    const badge = document.getElementById('rfidMatchBadge');
    if (rfidInp) {
        rfidInp.addEventListener('input', function() {
            const val = this.value.trim();
            if (val !== '') {
                if (expectedUserRfid !== '' && val === expectedUserRfid) {
                    badge.innerHTML = '<span style="color:#16a34a;">✓ RFID Cocok</span>';
                } else if (expectedUserRfid !== '') {
                    badge.innerHTML = '<span style="color:#d97706;">Terbaca: ' + escapeHtml(val) + '</span>';
                } else {
                    badge.innerHTML = '<span style="color:#16a34a;">✓ Kartu Terbaca</span>';
                }
            } else {
                badge.innerHTML = '';
            }
            updateHandoverSubmitState();
        });

        rfidInp.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                // Pindah fokus ke scanner barcode setelah RFID di-tap
                const barInp = document.getElementById('modalBarcodeScanInput');
                if (barInp) barInp.focus();
            }
        });
    }

    // Listener Barcode input
    const barInp = document.getElementById('modalBarcodeScanInput');
    if (barInp) {
        barInp.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                triggerBarcodeLookup();
            }
        });
    }

    // Cegah submit ganda pada form
    const formHandover = document.getElementById('handoverForm');
    if (formHandover) {
        formHandover.addEventListener('submit', function(e) {
            const btn = document.getElementById('btnSubmitHandover');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = 'Memproses Serah Terima...';
            }
        });
    }
});

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, function(m) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m];
    });
}
</script>

<?php page_end(); ?>