<?php

/**
 * FILE       : admin/pengembalian/index.php
 * MODUL      : Manajemen Pengembalian & Denda
 * FUNGSI     : Memproses penerimaan pengembalian alat laboratorium, verifikasi kondisi fisik, kalkulasi denda, dan sinkronisasi stok
 * AKSES      : Administrator / Petugas Laboratorium
 *
 * ALUR:
 * 1. Memastikan otorisasi admin/petugas melalui middleware admin.php.
 * 2. Menyediakan konsol pencarian terpadu (kartu RFID, barcode, kode transaksi, NIS, nama siswa).
 * 3. Menampilkan daftar transaksi yang sedang dipinjam (tab aktif) dan riwayat selesai.
 * 4. Membuka modal konfirmasi untuk memeriksa kondisi fisik alat (Baik, Rusak Ringan, Rusak Berat).
 * 5. Menghitung hari keterlambatan dan kalkulasi denda otomatis sesuai tarif harian.
 * 6. Mengubah status unit_peralatan, menyinkronkan stok master peralatan, dan mencatat audit log.
 */

require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/qrcode.php';
require_once __DIR__ . '/../../includes/whatsapp.php';

// Mengambil konfigurasi tarif denda harian dari tabel pengaturan sistem
$tarifDendaHarian = (int)($pdo->query("SELECT nilai FROM pengaturan WHERE nama = 'tarif_denda_harian'")->fetchColumn() ?: 1000);

$rfidSearch = trim((string)($_GET['rfid'] ?? ''));
$tab = trim((string)($_GET['tab'] ?? 'aktif')); // 'aktif' atau 'selesai'

// Handle pelunasan denda terpisah via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bayar_denda') {
    require_csrf();
    $pengembalianId = (int)($_POST['pengembalian_id'] ?? 0);
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare('
            SELECT pg.id, pg.peminjaman_id, pg.denda, pg.status_denda, p.kode_peminjaman, p.user_id 
            FROM pengembalian pg
            JOIN peminjaman p ON p.id = pg.peminjaman_id
            WHERE pg.id = ? FOR UPDATE
        ');
        $q->execute([$pengembalianId]);
        $row = $q->fetch();

        if (!$row) {
            throw new RuntimeException('Data denda tidak ditemukan.');
        }

        if ($row['status_denda'] === 'LUNAS') {
            throw new RuntimeException('Denda untuk transaksi ini sudah berstatus Lunas.');
        }

        $upd = $pdo->prepare("UPDATE pengembalian SET status_denda = 'LUNAS', diproses_oleh = ? WHERE id = ?");
        $upd->execute([(int)$_SESSION['user_id'], $pengembalianId]);

        $pdo->commit();

        audit_log('PAYMENT', 'DENDA', $pengembalianId, "Pelunasan denda {$row['kode_peminjaman']} sebesar " . format_rupiah((float)$row['denda']));
        send_notification((int)$row['user_id'], 'Pelunasan Denda Berhasil', "Denda keterlambatan untuk transaksi {$row['kode_peminjaman']} sebesar " . format_rupiah((float)$row['denda']) . " telah dinyatakan LUNAS oleh petugas lab.", 'success', 'index.php');

        flash('success', 'Denda untuk transaksi ' . $row['kode_peminjaman'] . ' berhasil dilunasi!');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
    }
    redirect('index.php?tab=selesai');
}

// Handle konfirmasi pengembalian via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    require_csrf();
    $id = (int)$_POST['id'];
    $kondisi = (string)($_POST['kondisi_barang'] ?? 'Baik');
    $catatan = trim((string)($_POST['catatan'] ?? ''));
    $scannedUnitId = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : 0;
    $scannedBarcode = trim((string)($_POST['barcode'] ?? ''));

    try {
        $pdo->beginTransaction();

        $q = $pdo->prepare('SELECT id, kode_peminjaman, user_id, status, tanggal_rencana_kembali FROM peminjaman WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $p = $q->fetch();

        if (!$p) {
            throw new RuntimeException('Data peminjaman tidak ditemukan.');
        }

        if (!in_array($p['status'], ['DIPINJAM', 'MENUNGGU_PENGEMBALIAN'], true)) {
            throw new RuntimeException('Peminjaman ini sudah selesai atau tidak dalam status aktif dipinjam.');
        }

        $kodePinjam = (string)$p['kode_peminjaman'];
        $peminjamUserId = (int)$p['user_id'];

        // Keamanan Business Logic: Hitung ulang keterlambatan & denda secara mandiri di sisi server
        $tglKembaliRencana = $p['tanggal_rencana_kembali'];
        $today = date('Y-m-d');
        $serverTerlambatHari = 0;
        if (!empty($tglKembaliRencana) && $today > $tglKembaliRencana) {
            $diffSeconds = strtotime($today) - strtotime($tglKembaliRencana);
            $serverTerlambatHari = max(0, (int)floor($diffSeconds / 86400));
        }

        $serverDenda = (float)($serverTerlambatHari * $tarifDendaHarian);
        $terlambatHari = $serverTerlambatHari;

        // Jika ada denda keterlambatan resmi server, tegakkan denda tersebut; jika ada denda ganti rugi, tambahkan
        $postDenda = max(0.0, (float)($_POST['denda'] ?? 0));
        $denda = max($serverDenda, $postDenda);

        $statusDenda = (string)($_POST['status_denda'] ?? ($denda > 0 ? 'BELUM_LUNAS' : 'LUNAS'));
        if (!in_array($statusDenda, ['LUNAS', 'BELUM_LUNAS'], true)) {
            $statusDenda = ($denda > 0) ? 'BELUM_LUNAS' : 'LUNAS';
        }

        // Kembalikan unit peralatan fisik dan sinkronkan master peralatan
        $q = $pdo->prepare('SELECT peralatan_id, unit_peralatan_id, barcode_snapshot, jumlah FROM detail_peminjaman WHERE peminjaman_id = ?');
        $q->execute([$id]);
        $items = $q->fetchAll();

        $unitStatus = ($kondisi === 'Rusak Berat') ? 'RUSAK' : 'TERSEDIA';

        foreach ($items as $it) {
            $alatId = (int)$it['peralatan_id'];
            $unitId = !empty($it['unit_peralatan_id']) ? (int)$it['unit_peralatan_id'] : null;

            if ($unitId !== null) {
                // Perbarui status dan kondisi unit fisik spesifik
                $updUnit = $pdo->prepare("UPDATE unit_peralatan SET kondisi = ?, status = ?, catatan = CONCAT(COALESCE(catatan, ''), ' | Dikembalikan: ', ?) WHERE id = ?");
                $updUnit->execute([$kondisi, $unitStatus, date('Y-m-d') . ' (' . $kondisi . ')', $unitId]);
            } elseif ($scannedUnitId > 0) {
                // Jika unit fisik spesifik teridentifikasi via barcode scanner
                $updUnit = $pdo->prepare("UPDATE unit_peralatan SET kondisi = ?, status = ?, catatan = CONCAT(COALESCE(catatan, ''), ' | Dikembalikan: ', ?) WHERE id = ?");
                $updUnit->execute([$kondisi, $unitStatus, date('Y-m-d') . ' (' . $kondisi . ')', $scannedUnitId]);
            } else {
                // Jika unit fisik belum terikat spesifik (transaksi lama), perbarui unit berstatus DIPINJAM untuk alat ini
                $updOldUnit = $pdo->prepare("
                    UPDATE unit_peralatan 
                    SET kondisi = ?, status = ? 
                    WHERE peralatan_id = ? AND status = 'DIPINJAM' 
                    LIMIT 1
                ");
                $updOldUnit->execute([$kondisi, $unitStatus, $alatId]);
            }

            // Sinkronkan stok master peralatan secara akurat dari tabel unit_peralatan
            sync_peralatan_stock($alatId);
        }

        // Catat kondisi akhir dan catatan pada detail_peminjaman
        $updDetail = $pdo->prepare('UPDATE detail_peminjaman SET kondisi_akhir = ?, catatan_kondisi = ? WHERE peminjaman_id = ?');
        $updDetail->execute([$kondisi, $catatan !== '' ? $catatan : null, $id]);

        // Simpan catatan ke tabel pengembalian
        $ins = $pdo->prepare('
            INSERT INTO pengembalian (
                peminjaman_id, tanggal_pengembalian, terlambat_hari, denda, status_denda,
                kondisi_barang, catatan, diproses_oleh
            ) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?)
        ');
        $ins->execute([
            $id,
            $terlambatHari,
            $denda,
            $statusDenda,
            $kondisi,
            $catatan !== '' ? $catatan : null,
            (int)$_SESSION['user_id']
        ]);

        // Update status peminjaman menjadi SELESAI
        $upd = $pdo->prepare('UPDATE peminjaman SET status = "SELESAI", tanggal_realisasi_kembali = CURDATE() WHERE id = ?');
        $upd->execute([$id]);

        $pdo->commit();

        $extraLog = $scannedBarcode !== '' ? " [Barcode: $scannedBarcode]" : "";
        audit_log('RETURN', 'PENGEMBALIAN', $id, "Memproses pengembalian $kodePinjam$extraLog (Kondisi: $kondisi, Terlambat: $terlambatHari hari, Denda: " . format_rupiah($denda) . ")");
        send_notification($peminjamUserId, 'Pengembalian Selesai Diverifikasi', "Pengembalian alat untuk $kodePinjam telah diverifikasi petugas lab (Kondisi: $kondisi" . ($denda > 0 ? ", Denda: " . format_rupiah($denda) : "") . "). Terima kasih telah menjaga inventaris lab!", 'success', 'index.php');

        // Kirim Bukti Pengembalian Digital via Bot WhatsApp
        try {
            $stInfo = $pdo->prepare("
                SELECT u.nama, u.no_hp,
                       GROUP_CONCAT(CONCAT(pr.nama_barang, ' (', COALESCE(up.barcode, up.kode_unit, '-'), ')') SEPARATOR ', ') AS rincian_barang
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
                send_wa_notification('pengembalian', [
                    'nama' => $info['nama'],
                    'no_hp' => $info['no_hp'],
                    'kode_peminjaman' => $kodePinjam,
                    'alat' => $info['rincian_barang'],
                    'kondisi' => $kondisi,
                    'hari_terlambat' => $terlambatHari,
                    'denda' => $denda,
                    'status_denda' => $statusDenda,
                    'catatan' => $catatan !== '' ? $catatan : 'Diterima dalam kondisi baik',
                    'peminjaman_id' => $id
                ]);
            }
        } catch (Throwable $eWa) {
            error_log('Gagal kirim WA pengembalian: ' . $eWa->getMessage());
        }

        flash('success', 'Pengembalian alat untuk ' . $kodePinjam . ($scannedBarcode !== '' ? " ($scannedBarcode)" : '') . ' berhasil diverifikasi! Stok dan status unit fisik telah disinkronkan.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
    }
    redirect('index.php');
}

// 1. Ambil data peminjaman yang masih aktif (Perlu Dikembalikan)
$sqlAktif = '
    SELECT p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.status, p.rfid_uid,
           u.nama AS nama_peminjam, u.nis, u.kelas, u.rfid_uid AS user_rfid, u.no_hp,
           COALESCE(SUM(d.jumlah), 0) AS total_item,
           GROUP_CONCAT(DISTINCT CONCAT(pe.nama_barang, " (", COALESCE(up.barcode, up.kode_unit, "-"), ")") SEPARATOR ", ") AS rincian_unit,
           MIN(up.id) AS single_unit_id,
           MIN(COALESCE(up.barcode, up.kode_unit)) AS single_unit_barcode,
           MIN(pe.nama_barang) AS single_tool_name
    FROM peminjaman p 
    LEFT JOIN users u ON u.id = p.user_id 
    LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id 
    LEFT JOIN unit_peralatan up ON up.id = d.unit_peralatan_id
    LEFT JOIN peralatan pe ON pe.id = d.peralatan_id
    WHERE p.status IN ("DIPINJAM", "MENUNGGU_PENGEMBALIAN") 
';
$paramsAktif = [];
if ($rfidSearch !== '') {
    $sqlAktif .= 'AND (
        p.rfid_uid = ? 
        OR u.rfid_uid = ? 
        OR u.nis = ? 
        OR u.nama LIKE ? 
        OR p.kode_peminjaman LIKE ?
        OR EXISTS (
            SELECT 1 FROM detail_peminjaman dp 
            LEFT JOIN unit_peralatan up2 ON up2.id = dp.unit_peralatan_id 
            LEFT JOIN peralatan pe2 ON pe2.id = dp.peralatan_id 
            WHERE dp.peminjaman_id = p.id 
            AND (up2.kode_unit = ? OR up2.barcode = ? OR dp.barcode_snapshot = ? OR up2.rfid_uid = ? OR up2.serial_number = ? OR pe2.nama_barang LIKE ?)
        )
    ) ';
    $paramsAktif = [
        $rfidSearch, $rfidSearch, $rfidSearch, '%' . $rfidSearch . '%', '%' . $rfidSearch . '%',
        $rfidSearch, $rfidSearch, $rfidSearch, $rfidSearch, $rfidSearch, '%' . $rfidSearch . '%'
    ];
}
$sqlAktif .= 'GROUP BY p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.status, p.rfid_uid, u.nama, u.nis, u.kelas, u.rfid_uid, u.no_hp ORDER BY p.id DESC';
$stmtAktif = $pdo->prepare($sqlAktif);
$stmtAktif->execute($paramsAktif);
$rowsAktif = $stmtAktif->fetchAll();

// 2. Ambil data riwayat pengembalian yang sudah selesai
$sqlSelesai = '
    SELECT p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.tanggal_realisasi_kembali, p.status,
           u.nama AS nama_peminjam, u.nis, u.kelas,
           pg.id AS pengembalian_id, pg.status_denda,
           pg.tanggal_pengembalian, pg.terlambat_hari, pg.denda, pg.kondisi_barang, pg.catatan,
           COALESCE(SUM(d.jumlah), 0) AS total_item,
           GROUP_CONCAT(DISTINCT CONCAT(pe.nama_barang, " (", COALESCE(up.barcode, d.barcode_snapshot, up.kode_unit, "-"), ")") SEPARATOR ", ") AS rincian_unit
    FROM peminjaman p 
    LEFT JOIN users u ON u.id = p.user_id 
    LEFT JOIN pengembalian pg ON pg.peminjaman_id = p.id 
    LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id 
    LEFT JOIN unit_peralatan up ON up.id = d.unit_peralatan_id
    LEFT JOIN peralatan pe ON pe.id = d.peralatan_id
    WHERE p.status = "SELESAI" 
';
$paramsSelesai = [];
if ($rfidSearch !== '') {
    $sqlSelesai .= 'AND (
        p.rfid_uid = ? 
        OR u.rfid_uid = ? 
        OR u.nis = ? 
        OR u.nama LIKE ? 
        OR p.kode_peminjaman LIKE ?
        OR EXISTS (
            SELECT 1 FROM detail_peminjaman dp 
            LEFT JOIN unit_peralatan up2 ON up2.id = dp.unit_peralatan_id 
            LEFT JOIN peralatan pe2 ON pe2.id = dp.peralatan_id 
            WHERE dp.peminjaman_id = p.id 
            AND (up2.kode_unit = ? OR up2.barcode = ? OR dp.barcode_snapshot = ? OR up2.rfid_uid = ? OR up2.serial_number = ? OR pe2.nama_barang LIKE ?)
        )
    ) ';
    $paramsSelesai = [
        $rfidSearch, $rfidSearch, $rfidSearch, '%' . $rfidSearch . '%', '%' . $rfidSearch . '%',
        $rfidSearch, $rfidSearch, $rfidSearch, $rfidSearch, $rfidSearch, '%' . $rfidSearch . '%'
    ];
}
$sqlSelesai .= 'GROUP BY p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.tanggal_realisasi_kembali, p.status, u.nama, u.nis, u.kelas, pg.id, pg.status_denda, pg.tanggal_pengembalian, pg.terlambat_hari, pg.denda, pg.kondisi_barang, pg.catatan ORDER BY p.id DESC LIMIT 50';
$stmtSelesai = $pdo->prepare($sqlSelesai);
$stmtSelesai->execute($paramsSelesai);
$rowsSelesai = $stmtSelesai->fetchAll();

page_start('Pengembalian Alat Lab', 'Proses pengembalian alat dan pengembalian stok lab', 'pengembalian');
?>

<div class="head">
    <div>
        <h2>Data Pengembalian Alat Laboratorium</h2>
        <p>Penerimaan pengembalian alat, verifikasi kondisi fisik, kalkulasi denda, dan sinkronisasi stok lab.</p>
    </div>
</div>

<!-- BARCODE SCANNER PENGEMBALIAN ALAT (USB HID) -->
<div class="panel" style="margin-bottom:20px;border:1px solid #cbd5e1;box-shadow:0 2px 8px rgba(0,0,0,0.04);background:linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);">
    <div style="padding:18px 22px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:12px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:38px;height:38px;border-radius:8px;background:#e0f2fe;border:1px solid #bae6fd;display:flex;align-items:center;justify-content:center;color:#0284c7;flex-shrink:0;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 7V5a2 2 0 0 1 2-2h2"></path>
                        <path d="M17 3h2a2 2 0 0 1 2 2v2"></path>
                        <path d="M21 17v2a2 2 0 0 1-2 2h-2"></path>
                        <path d="M3 17v2a2 2 0 0 0 2 2h2"></path>
                        <line x1="7" y1="12" x2="17" y2="12"></line>
                    </svg>
                </div>
                <div>
                    <h3 style="margin:0;font-size:15px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
                        Pemindaian Barcode Unit Fisik untuk Pengembalian Cepat
                    </h3>
                    <p style="margin:2px 0 0;font-size:12.5px;color:#64748b;">
                        Arahkan barcode scanner USB ke stiker barcode alat fisik (cth: <code>SIP-ALT-000001</code>). Sistem otomatis memeriksa peminjaman aktif & kalkulasi denda.
                    </p>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <span id="barcodeScannerStatusBadge" style="display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;padding:4px 10px;border-radius:20px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;">
                    <span style="width:7px;height:7px;border-radius:50%;background:#10b981;"></span>
                    Scanner Barcode Siap
                </span>
            </div>
        </div>

        <form id="barcodeReturnForm" onsubmit="event.preventDefault(); processReturnBarcode();" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <div style="position:relative;flex:1;min-width:280px;">
                <div style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#0284c7;pointer-events:none;display:flex;align-items:center;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="4" y1="9" x2="20" y2="9"></line>
                        <line x1="4" y1="15" x2="20" y2="15"></line>
                        <line x1="10" y1="3" x2="8" y2="21"></line>
                        <line x1="16" y1="3" x2="14" y2="21"></line>
                    </svg>
                </div>
                <input type="text" 
                       id="barcodeReturnInput" 
                       placeholder="Scan / ketik kode barcode unit fisik (cth: SIP-ALT-000036)..." 
                       autocomplete="off"
                       autofocus
                       style="width:100%;padding:11px 14px 11px 40px;border-radius:8px;border:2px solid #0284c7;font-family:'JetBrains Mono', monospace;font-size:14.5px;font-weight:700;background:#ffffff;color:#0f172a;outline:none;box-shadow:0 0 0 3px rgba(2,132,199,0.12);">
            </div>
            <button type="submit" id="btnProcessBarcodeReturn" class="btn btn-primary" style="padding:10px 20px;font-weight:700;gap:8px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                Verifikasi & Terima Unit
            </button>
            <button type="button" class="btn" onclick="clearReturnBarcodeScan()" style="padding:10px 14px;color:#64748b;">
                Reset
            </button>
        </form>

        <!-- LIVE FEEDBACK BANNER -->
        <div id="barcodeReturnFeedback" style="display:none;margin-top:14px;"></div>
    </div>
</div>

<!-- SEARCH & SCAN CONSOLE PENGEMBALIAN -->
<div class="panel" style="margin-bottom:22px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
    <div style="padding:16px 20px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:12px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:34px;height:34px;border-radius:6px;background:#f8fafc;border:1px solid #cbd5e1;display:flex;align-items:center;justify-content:center;color:#334155;flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 7V5a2 2 0 0 1 2-2h2"></path>
                        <path d="M17 3h2a2 2 0 0 1 2 2v2"></path>
                        <path d="M21 17v2a2 2 0 0 1-2 2h-2"></path>
                        <path d="M3 17v2a2 2 0 0 0 2 2h2"></path>
                        <line x1="7" y1="12" x2="17" y2="12"></line>
                    </svg>
                </div>
                <div>
                    <h3 style="margin:0;font-size:14.5px;font-weight:700;color:#0f172a;">Pencarian & Pemindaian Pengembalian</h3>
                    <p style="margin:1px 0 0;font-size:12px;color:#64748b;">
                        Scan kartu RFID siswa, barcode transaksi, atau cari berdasarkan nama peminjam, NIS, dan kode transaksi.
                    </p>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;padding:3px 9px;border-radius:20px;background:#f8fafc;color:#475569;border:1px solid #e2e8f0;">
                    <span style="width:6px;height:6px;border-radius:50%;background:#10b981;"></span>
                    Scanner Siap
                </span>
            </div>
        </div>

        <form method="get" action="index.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            
            <div style="position:relative;flex:1;min-width:260px;">
                <div style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none;display:flex;align-items:center;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                </div>
                
                <input type="text" 
                       name="rfid" 
                       id="rfidSearchInput"
                       value="<?= e($rfidSearch) ?>" 
                       placeholder="Scan kartu RFID / masukkan kode transaksi / NIS / nama siswa..." 
                       class="rfid-autofocus" 
                       data-rfid-scanner="true" 
                       data-auto-submit="true"
                       autocomplete="off"
                       style="width:100%;padding:9px 12px 9px 38px;border-radius:6px;border:1px solid #cbd5e1;font-family:inherit;font-size:13.5px;font-weight:500;background:#ffffff;color:#0f172a;outline:none;">
            </div>

            <button type="submit" class="btn btn-primary" style="padding:8px 16px;gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                Cari Transaksi
            </button>

            <?php if ($rfidSearch !== ''): ?>
                <a href="index.php?tab=<?= e($tab) ?>" class="btn" style="padding:8px 14px;color:#64748b;">
                    Reset
                </a>
            <?php endif; ?>
        </form>

        <?php if ($rfidSearch !== ''): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;margin-top:12px;font-size:12.5px;color:#334155;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <span style="color:#64748b;">Hasil pencarian:</span>
                    <span style="font-family:monospace;font-weight:700;color:#0f172a;background:#ffffff;border:1px solid #cbd5e1;padding:1px 7px;border-radius:4px;">
                        <?= e($rfidSearch) ?>
                    </span>
                    <span style="color:#64748b;">
                        (Ditemukan <?= count($tab === 'aktif' ? $rowsAktif : $rowsSelesai) ?> data)
                    </span>
                </div>
                <a href="index.php?tab=<?= e($tab) ?>" style="color:#dc2626;font-weight:600;text-decoration:none;font-size:12px;display:flex;align-items:center;gap:4px;">
                    ✕ Hapus Pencarian
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- TAB PILIHAN: PEMINJAMAN AKTIF VS RIWAYAT SELESAI -->
<div style="display:flex;gap:8px;margin-bottom:18px;border-bottom:1px solid #dfe3e8;padding-bottom:10px;">
    <a href="index.php?tab=aktif<?= $rfidSearch !== '' ? '&rfid=' . urlencode($rfidSearch) : '' ?>" 
       class="btn <?= $tab === 'aktif' ? 'btn-primary' : '' ?>" style="font-weight:600;">
        Peminjaman Aktif (<?= count($rowsAktif) ?>)
    </a>
    <a href="index.php?tab=selesai<?= $rfidSearch !== '' ? '&rfid=' . urlencode($rfidSearch) : '' ?>" 
       class="btn <?= $tab === 'selesai' ? 'btn-primary' : '' ?>" style="font-weight:600;">
        Riwayat Selesai Dikembalikan (<?= count($rowsSelesai) ?>)
    </a>
</div>

<!-- =========================================================
     TABEL 1: PEMINJAMAN AKTIF (BELUM KEMBALI)
========================================================= -->
<?php if ($tab === 'aktif'): ?>
<div class="panel">
    <div class="panel-title">
        <span>Daftar Alat yang Sedang Dipinjam (Belum Kembali)</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Kode Peminjaman</th>
                    <th>Peminjam (Siswa)</th>
                    <th>Tgl Pinjam</th>
                    <th>Batas Pengembalian</th>
                    <th>Jumlah Item</th>
                    <th>Status Keterlambatan</th>
                    <th>Tindakan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rowsAktif)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty">Tidak ada peminjaman alat yang sedang aktif (semua alat sudah dikembalikan).</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $today = new DateTime(date('Y-m-d'));
                    foreach ($rowsAktif as $i => $r): 
                        $due = new DateTime($r['tanggal_rencana_kembali']);
                        $isLate = ($today > $due);
                        $diffDays = $isLate ? (int)$today->diff($due)->days : 0;
                    ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <strong><?= e($r['kode_peminjaman']) ?></strong>
                                <?php if (!empty($r['rincian_unit'])): ?>
                                    <div style="font-size:11.5px;color:#047857;margin-top:2px;font-family:monospace;font-weight:600;">
                                        🏷️ <?= e($r['rincian_unit']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><strong><?= e($r['nama_peminjam'] ?? '-') ?></strong></div>
                                <div style="font-size:12px;color:#64748b;">
                                    NIS: <?= e($r['nis'] ?: '-') ?> | <?= e($r['kelas'] ?: '-') ?>
                                </div>
                            </td>
                            <td><?= e(date('d-m-Y', strtotime($r['tanggal_pinjam']))) ?></td>
                            <td>
                                <div><?= e(date('d-m-Y', strtotime($r['tanggal_rencana_kembali']))) ?></div>
                            </td>
                            <td>
                                <span style="font-weight:700;"><?= $r['total_item'] ?></span> unit
                            </td>
                            <td>
                                <?php if ($isLate && $diffDays > 0): ?>
                                    <span class="badge DITOLAK" style="font-size:11px;">
                                        Terlambat <?= $diffDays ?> Hari
                                    </span>
                                <?php else: ?>
                                    <!-- Jika masih dipinjam & belum lewat batas: status Masih Diproses -->
                                    <span class="badge DIPINJAM" style="font-size:11px;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;">
                                        Masih Diproses
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions" style="gap:5px;">
                                    <?php 
                                    $rawHp = (string)($r['no_hp'] ?? '');
                                    ?>
                                    <?php if ($rawHp !== ''): ?>
                                        <button type="button" class="btn btn-sm" data-wa-target
                                                data-wa-id="<?= $r['id'] ?>"
                                                data-wa-nama="<?= e($r['nama_peminjam'] ?? '') ?>"
                                                data-wa-kelas="<?= e($r['kelas'] ?? '') ?>"
                                                data-wa-phone="<?= e($rawHp) ?>"
                                                data-wa-kode="<?= e($r['kode_peminjaman']) ?>"
                                                data-wa-kembali="<?= e(date('d-m-Y', strtotime($r['tanggal_rencana_kembali']))) ?>"
                                                data-wa-type="<?= ($isLate && $diffDays > 0) ? 'terlambat' : 'pengingat' ?>"
                                                style="background:#22c55e;color:#ffffff;border:none;display:inline-flex;align-items:center;gap:4px;padding:4px 8px;font-size:11px;font-weight:700;"
                                                title="Kirim pesan WhatsApp ke <?= e($rawHp) ?>">
                                            📱 WA
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-ok" onclick="openReturnModal(<?= $r['id'] ?>, '<?= e($r['kode_peminjaman']) ?>', '<?= e($r['nama_peminjam']) ?>', <?= $diffDays ?>, <?= !empty($r['single_unit_id']) ? "{ id: " . (int)$r['single_unit_id'] . ", barcode: '" . e($r['single_unit_barcode']) . "', nama_barang: '" . e($r['single_tool_name']) . "' }" : "null" ?>)">
                                        Terima Pengembalian
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- =========================================================
     TABEL 2: RIWAYAT PENGEMBALIAN SELESAI
========================================================= -->
<?php if ($tab === 'selesai'): ?>
<div class="panel">
    <div class="panel-title">
        <span>Riwayat Alat yang Sudah Dikembalikan</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Kode Peminjaman</th>
                    <th>Peminjam (Siswa)</th>
                    <th>Tgl Pinjam / Batas</th>
                    <th>Tgl Dikembalikan</th>
                    <th>Status Pengembalian</th>
                    <th>Kondisi & Denda</th>
                    <th>Tindakan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rowsSelesai)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty">Belum ada riwayat pengembalian yang selesai.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rowsSelesai as $i => $r): 
                        $tglRencana = strtotime($r['tanggal_rencana_kembali']);
                        $tglRealisasi = strtotime($r['tanggal_realisasi_kembali'] ?: $r['tanggal_pengembalian'] ?: date('Y-m-d'));
                        $terlambatHari = (int)($r['terlambat_hari'] ?? 0);
                        if ($terlambatHari === 0 && $tglRealisasi > $tglRencana) {
                            $terlambatHari = (int)ceil(($tglRealisasi - $tglRencana) / 86400);
                        }
                    ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <strong><?= e($r['kode_peminjaman']) ?></strong>
                                <?php if (!empty($r['rincian_unit'])): ?>
                                    <div style="font-size:11px;color:#047857;margin-top:2px;font-family:monospace;">
                                        🏷️ <?= e($r['rincian_unit']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><strong><?= e($r['nama_peminjam'] ?? '-') ?></strong></div>
                                <div style="font-size:12px;color:#64748b;">
                                    NIS: <?= e($r['nis'] ?: '-') ?> | <?= e($r['kelas'] ?: '-') ?>
                                </div>
                            </td>
                            <td>
                                <div><?= e(date('d-m-Y', strtotime($r['tanggal_pinjam']))) ?></div>
                                <div style="font-size:12px;color:#64748b;">
                                    Batas: <?= e(date('d-m-Y', strtotime($r['tanggal_rencana_kembali']))) ?>
                                </div>
                            </td>
                            <td>
                                <strong style="color:#059669;">
                                    <?= e(date('d-m-Y', strtotime($r['tanggal_realisasi_kembali'] ?: $r['tanggal_pengembalian']))) ?>
                                </strong>
                            </td>
                            <td>
                                <?php if ($terlambatHari > 0): ?>
                                    <span class="badge DITOLAK" style="font-size:11px;">
                                        Terlambat <?= $terlambatHari ?> Hari
                                    </span>
                                <?php else: ?>
                                    <!-- Jika sudah dikembalikan tepat waktu: status Tepat Waktu -->
                                    <span class="badge SELESAI" style="font-size:11px;">
                                        Tepat Waktu
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div>Kondisi: <strong><?= e($r['kondisi_barang'] ?: 'Baik') ?></strong></div>
                                <?php if ((float)($r['denda'] ?? 0) > 0): ?>
                                    <div style="font-size:12px;color:#dc2626;font-weight:600;">
                                        Denda: <?= format_rupiah((float)$r['denda']) ?>
                                    </div>
                                    <div style="margin-top:4px;">
                                        <?php if (($r['status_denda'] ?? 'LUNAS') === 'LUNAS'): ?>
                                            <span class="badge SELESAI" style="font-size:10px;padding:2px 6px;">Lunas</span>
                                        <?php else: ?>
                                            <span class="badge DITOLAK" style="font-size:10px;padding:2px 6px;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;">Belum Lunas</span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div style="font-size:12px;color:#64748b;">Tanpa Denda</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                    <a class="btn btn-sm" href="../peminjaman/detail.php?id=<?= $r['id'] ?>">Detail</a>
                                    <?php if ((float)($r['denda'] ?? 0) > 0 && ($r['status_denda'] ?? 'LUNAS') === 'BELUM_LUNAS' && !empty($r['pengembalian_id'])): ?>
                                        <button type="button" class="btn btn-sm btn-ok" onclick="openPayFineModal(<?= (int)$r['pengembalian_id'] ?>, '<?= e($r['kode_peminjaman']) ?>', '<?= e($r['nama_peminjam']) ?>', <?= (float)$r['denda'] ?>)">
                                            Pelunasan Denda
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
<?php endif; ?>

<!-- MODAL TERIMA PENGEMBALIAN -->
<div class="modal" id="returnModal">
    <div class="modal-box">
        <h3>Penerimaan Pengembalian Alat Lab</h3>
        <p id="returnText" style="margin-bottom:16px;">Konfirmasi barang kembali.</p>
        
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="returnId">
            <input type="hidden" name="unit_id" id="returnUnitId" value="">
            <input type="hidden" name="barcode" id="returnBarcode" value="">
            <input type="hidden" name="terlambat_hari" id="returnLateDays" value="0">

            <!-- PREVIEW UNIT FISIK YANG DIKEMBALIKAN -->
            <div id="returnUnitPreviewBox" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 14px;margin-bottom:14px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:11.5px;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #86efac;padding:2px 8px;border-radius:12px;display:inline-flex;align-items:center;gap:4px;">
                        ✓ Unit Fisik Terverifikasi Barcode
                    </span>
                    <span id="returnUnitBarcodeBadge" style="font-family:'JetBrains Mono', monospace;font-weight:700;color:#047857;background:#ffffff;border:1px solid #86efac;padding:2px 8px;border-radius:4px;font-size:12px;"></span>
                </div>
                <div style="font-size:14px;font-weight:700;color:#0f172a;" id="returnUnitName">-</div>
                <div style="font-size:12px;color:#475569;margin-top:2px;">
                    Kode Unit: <strong id="returnUnitKode">-</strong> &bull; Kondisi Awal: <span id="returnUnitKondisiAwal" class="badge aktif" style="font-size:10.5px;">Baik</span>
                </div>
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label>Kondisi Alat Saat Dikembalikan *</label>
                <select name="kondisi_barang" required>
                    <option value="Baik">Baik (Lengkap & Berfungsi)</option>
                    <option value="Rusak Ringan">Rusak Ringan</option>
                    <option value="Rusak Berat">Rusak Berat / Hilang Sebagian</option>
                </select>
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label>Denda / Biaya Keterlambatan & Kerusakan (Rp)</label>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-weight:700;color:#334155;">Rp</span>
                    <input type="number" min="0" step="500" name="denda" id="returnDenda" value="0" style="flex:1;">
                </div>
                <div id="dendaNotice" style="display:none;font-size:12px;color:#b45309;background:#fef3c7;padding:6px 10px;border-radius:5px;margin-top:6px;border:1px solid #fde68a;">
                    <span id="dendaNoticeText"></span>
                </div>
                <small style="color:#64748b;font-size:11px;margin-top:4px;">
                    Tarif denda standar sistem: <strong>Rp <?= number_format($tarifDendaHarian, 0, ',', '.') ?> / hari</strong> (bisa diedit jika ada denda kerusakan fisik atau dispensasi khusus).
                </small>
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label>Status Pelunasan Denda</label>
                <select name="status_denda">
                    <option value="LUNAS">LUNAS (Langsung Dibayar / Tidak Ada Denda)</option>
                    <option value="BELUM_LUNAS">BELUM LUNAS (Dicatat Sebagai Piutang Denda)</option>
                </select>
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label>Catatan Pengembalian (Opsional)</label>
                <textarea name="catatan" placeholder="Catatan kondisi fisik alat saat diterima petugas..."></textarea>
            </div>

            <div class="modal-actions" style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn" onclick="document.getElementById('returnModal').classList.remove('show')">Batal</button>
                <button type="submit" class="btn btn-primary">Konfirmasi Terima & Tambah Stok</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL PELUNASAN DENDA -->
<div class="modal" id="payFineModal">
    <div class="modal-box">
        <h3>Konfirmasi Pelunasan Denda</h3>
        <p id="payFineText" style="margin-bottom:16px;">Konfirmasi penerimaan pembayaran denda dari siswa.</p>
        
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bayar_denda">
            <input type="hidden" name="pengembalian_id" id="payFinePengembalianId">

            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:16px;">
                <div style="font-size:13px;color:#64748b;margin-bottom:4px;">Total Denda yang Harus Dibayar:</div>
                <div style="font-size:22px;font-weight:700;color:#dc2626;" id="payFineAmount">Rp 0</div>
            </div>

            <p style="font-size:12.5px;color:#475569;margin-bottom:18px;">
                Pastikan siswa telah membayarkan uang denda secara tunai / lunas ke petugas laboratorium sebelum menekan tombol konfirmasi.
            </p>

            <div class="modal-actions" style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn" onclick="document.getElementById('payFineModal').classList.remove('show')">Batal</button>
                <button type="submit" class="btn btn-ok">Konfirmasi Lunas</button>
            </div>
        </form>
    </div>
</div>

<script>
const tarifDendaHarian = <?= $tarifDendaHarian ?>;
let returnScanTimer = null;
const barcodeInput = document.getElementById('barcodeReturnInput');
const feedbackContainer = document.getElementById('barcodeReturnFeedback');

function playBarcodeBeep(isSuccess) {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        if (isSuccess) {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.type = 'sine';
            osc.frequency.setValueAtTime(1200, audioCtx.currentTime);
            gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.12);
            osc.start(audioCtx.currentTime);
            osc.stop(audioCtx.currentTime + 0.12);
        } else {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(300, audioCtx.currentTime);
            gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.25);
            osc.start(audioCtx.currentTime);
            osc.stop(audioCtx.currentTime + 0.25);
        }
    } catch (e) {}
}

function clearReturnBarcodeScan() {
    if (barcodeInput) {
        barcodeInput.value = '';
        barcodeInput.focus();
    }
    if (feedbackContainer) {
        feedbackContainer.style.display = 'none';
        feedbackContainer.innerHTML = '';
    }
}

async function processReturnBarcode() {
    if (!barcodeInput) return;
    const barcode = barcodeInput.value.trim();
    if (!barcode) {
        barcodeInput.focus();
        return;
    }

    feedbackContainer.style.display = 'block';
    feedbackContainer.innerHTML = `
        <div style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:10px 14px;border-radius:6px;font-size:13px;display:flex;align-items:center;gap:8px;">
            <span style="display:inline-block;animation:spin 1s linear infinite;">⏳</span>
            <span>Memeriksa unit barcode <strong>${escapeHtml(barcode)}</strong> di server...</span>
        </div>
    `;

    try {
        const res = await fetch(`../api_barcode_lookup.php?action=verify_return_unit&barcode=${encodeURIComponent(barcode)}`);
        const data = await res.json();

        if (!data.success) {
            playBarcodeBeep(false);
            feedbackContainer.innerHTML = `
                <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 14px;border-radius:8px;font-size:13px;display:flex;align-items:flex-start;gap:10px;">
                    <div style="font-size:18px;line-height:1;">⚠️</div>
                    <div style="flex:1;">
                        <strong style="display:block;margin-bottom:2px;">Pengembalian Tidak Dapat Diproses:</strong>
                        <span>${escapeHtml(data.message)}</span>
                    </div>
                </div>
            `;
            barcodeInput.select();
            return;
        }

        // Sukses verifikasi barcode
        playBarcodeBeep(true);
        const u = data.unit;
        const l = data.loan;

        feedbackContainer.innerHTML = `
            <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:14px 16px;border-radius:8px;font-size:13px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
                    <span style="font-weight:700;color:#047857;display:flex;align-items:center;gap:6px;">
                        <span style="width:8px;height:8px;border-radius:50%;background:#10b981;"></span>
                        Barcode Terverifikasi: Transaksi Peminjaman Aktif Ditemukan
                    </span>
                    <span style="font-family:'JetBrains Mono', monospace;font-weight:700;background:#ffffff;border:1px solid #a7f3d0;padding:2px 8px;border-radius:4px;color:#047857;">
                        ${escapeHtml(u.barcode)}
                    </span>
                </div>
                <div style="color:#0f172a;font-size:14px;font-weight:700;margin-bottom:4px;">
                    ${escapeHtml(u.nama_barang)}
                </div>
                <div style="color:#334155;font-size:12.5px;line-height:1.5;">
                    Peminjam: <strong>${escapeHtml(l.peminjam_nama)}</strong> (NIS: ${escapeHtml(l.peminjam_nis)} | Kelas: ${escapeHtml(l.peminjam_kelas)})<br>
                    Transaksi: <strong>${escapeHtml(l.kode_peminjaman)}</strong> &bull; Batas Kembali: <strong>${escapeHtml(l.tanggal_rencana_kembali)}</strong> &bull;
                    Status: <strong style="color:${l.terlambat_hari > 0 ? '#dc2626' : '#15803d'};">${l.terlambat_hari > 0 ? 'Terlambat ' + l.terlambat_hari + ' Hari (' + l.denda_formatted + ')' : 'Tepat Waktu'}</strong>
                </div>
            </div>
        `;

        // Buka returnModal otomatis
        openReturnModal(l.peminjaman_id, l.kode_peminjaman, l.peminjam_nama, l.terlambat_hari, {
            id: u.id,
            barcode: u.barcode,
            kode_unit: u.kode_unit,
            nama_barang: u.nama_barang,
            kondisi_awal: l.kondisi_awal || u.kondisi
        });

    } catch (err) {
        playBarcodeBeep(false);
        feedbackContainer.innerHTML = `
            <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px;border-radius:6px;font-size:13px;">
                Terjadi kesalahan jaringan atau server saat memvalidasi barcode. Silakan ulangi pemindaian.
            </div>
        `;
    }
}

if (barcodeInput) {
    barcodeInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            processReturnBarcode();
        }
    });

    barcodeInput.addEventListener('input', function() {
        clearTimeout(returnScanTimer);
        const val = this.value.trim();
        if (val.length >= 8 && (val.startsWith('SIP-') || val.includes('-'))) {
            returnScanTimer = setTimeout(() => {
                processReturnBarcode();
            }, 300);
        }
    });
}

function openReturnModal(id, kode, nama, lateDays, unitInfo = null) {
    document.getElementById('returnId').value = id;
    document.getElementById('returnLateDays').value = lateDays;
    
    const unitBox = document.getElementById('returnUnitPreviewBox');
    const inputUnitId = document.getElementById('returnUnitId');
    const inputBarcode = document.getElementById('returnBarcode');

    if (unitInfo) {
        if (inputUnitId) inputUnitId.value = unitInfo.id || '';
        if (inputBarcode) inputBarcode.value = unitInfo.barcode || '';
        if (unitBox) {
            document.getElementById('returnUnitBarcodeBadge').textContent = unitInfo.barcode || '-';
            document.getElementById('returnUnitName').textContent = unitInfo.nama_barang || '-';
            document.getElementById('returnUnitKode').textContent = unitInfo.kode_unit || unitInfo.barcode || '-';
            const badgeKondisi = document.getElementById('returnUnitKondisiAwal');
            if (badgeKondisi) badgeKondisi.textContent = unitInfo.kondisi_awal || 'Baik';
            unitBox.style.display = 'block';
        }
    } else {
        if (inputUnitId) inputUnitId.value = '';
        if (inputBarcode) inputBarcode.value = '';
        if (unitBox) unitBox.style.display = 'none';
    }

    let info = 'Terima alat untuk ' + kode + ' (Peminjam: ' + nama + ').';
    const notice = document.getElementById('dendaNotice');
    const noticeText = document.getElementById('dendaNoticeText');

    if (lateDays > 0) {
        const totalDenda = lateDays * tarifDendaHarian;
        info += ' Perhatian: Siswa terlambat ' + lateDays + ' hari.';
        document.getElementById('returnDenda').value = totalDenda;
        if (notice && noticeText) {
            noticeText.innerHTML = '<strong>Kalkulasi Otomatis:</strong> Terlambat ' + lateDays + ' hari &times; Rp ' + tarifDendaHarian.toLocaleString('id-ID') + ' = <strong>Rp ' + totalDenda.toLocaleString('id-ID') + '</strong>';
            notice.style.display = 'block';
        }
    } else {
        document.getElementById('returnDenda').value = 0;
        if (notice && noticeText) {
            noticeText.innerHTML = '<strong>Tepat Waktu:</strong> Tidak dikenakan denda keterlambatan.';
            notice.style.display = 'block';
        }
    }
    
    document.getElementById('returnText').textContent = info;
    document.getElementById('returnModal').classList.add('show');
}

function openPayFineModal(pengembalianId, kode, nama, denda) {
    document.getElementById('payFinePengembalianId').value = pengembalianId;
    document.getElementById('payFineText').textContent = 'Penerimaan pembayaran denda untuk transaksi ' + kode + ' (Peminjam: ' + nama + ').';
    document.getElementById('payFineAmount').textContent = 'Rp ' + Number(denda).toLocaleString('id-ID');
    document.getElementById('payFineModal').classList.add('show');
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

<?php page_end(); ?>
