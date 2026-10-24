<?php

/**
 * FILE       : admin/dashboard.php
 * MODUL      : Dashboard Utama Admin & Laboran
 * FUNGSI     : Pusat pemantauan statistik operasional laboratorium, sirkulasi peminjaman, jatuh tempo, dan master peralatan terkini
 * AKSES      : Administrator / Petugas Laboratorium
 *
 * ALUR:
 * 1. Memeriksa status sesi login dan otorisasi admin/petugas.
 * 2. Mengambil metrik inventaris unit fisik (total, tersedia, dipinjam, rusak/afkir).
 * 3. Menghitung indikator transaksi kritis (pengajuan baru, pinjaman aktif, jatuh tempo hari ini, keterlambatan).
 * 4. Menyajikan ringkasan statistik dalam grid 1 baris kompak berwarna hitam pekat elegan.
 * 5. Menampilkan tabel pemantauan peminjaman aktif & jatuh tempo serta tabel master peralatan terkini.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/admin.php';

// Ambil tarif denda harian dari pengaturan
$tarifDendaHarian = (int)($pdo->query("SELECT nilai FROM pengaturan WHERE nama = 'tarif_denda_harian'")->fetchColumn() ?: 1000);

/*
|--------------------------------------------------------------------------
| STATISTIK OPERASIONAL LABORATORIUM
|--------------------------------------------------------------------------
*/

// 1. Peralatan & Unit Fisik
$totalJenisAlat = (int)$pdo->query("SELECT COUNT(*) FROM peralatan WHERE status = 'aktif'")->fetchColumn();
$totalUnits = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan")->fetchColumn();
$unitTersedia = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan WHERE status = 'TERSEDIA' AND kondisi != 'Rusak Berat'")->fetchColumn();
$unitDipinjam = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan WHERE status = 'DIPINJAM'")->fetchColumn();
$unitRusak = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan WHERE status = 'RUSAK' OR kondisi = 'Rusak Berat'")->fetchColumn();

// 2. Transaksi Peminjaman
$pengajuanMenunggu = (int)$pdo->query("SELECT COUNT(*) FROM peminjaman WHERE status = 'MENUNGGU'")->fetchColumn();
$peminjamanAktif = (int)$pdo->query("SELECT COUNT(*) FROM peminjaman WHERE status IN ('DIPINJAM', 'MENUNGGU_PENGEMBALIAN')")->fetchColumn();
$jatuhTempoHariIni = (int)$pdo->query("SELECT COUNT(*) FROM peminjaman WHERE status IN ('DIPINJAM', 'MENUNGGU_PENGEMBALIAN') AND tanggal_rencana_kembali = CURDATE()")->fetchColumn();
$peminjamanTerlambat = (int)$pdo->query("SELECT COUNT(*) FROM peminjaman WHERE status IN ('DIPINJAM', 'MENUNGGU_PENGEMBALIAN') AND tanggal_rencana_kembali < CURDATE()")->fetchColumn();

// 3. Pengguna & RFID
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'aktif'")->fetchColumn();
$totalRfid = (int)$pdo->query("SELECT COUNT(*) FROM rfid_cards WHERE status = 'aktif'")->fetchColumn();

/*
|--------------------------------------------------------------------------
| QUERY DATA PANEL
|--------------------------------------------------------------------------
*/

// 1. Pengajuan Terbaru (Status: MENUNGGU)
$stmtWaiting = $pdo->query("
    SELECT p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_rencana_kembali, p.status, p.keperluan,
           u.nama AS nama_peminjam, u.nis, u.kelas,
           COALESCE(SUM(d.jumlah), 0) AS total_item,
           GROUP_CONCAT(CONCAT(COALESCE(pr.nama_barang, d.nama_barang_snapshot, 'Alat'), ' (', d.jumlah, ')') SEPARATOR ', ') AS daftar_alat
    FROM peminjaman p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id
    LEFT JOIN peralatan pr ON pr.id = d.peralatan_id
    WHERE p.status = 'MENUNGGU'
    GROUP BY p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_rencana_kembali, p.status, p.keperluan, u.nama, u.nis, u.kelas
    ORDER BY p.id DESC
    LIMIT 6
");
$pendingLoans = $stmtWaiting->fetchAll();

// 2. Peminjaman Terlambat / Jatuh Tempo
$stmtOverdue = $pdo->query("
    SELECT p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.status,
           u.nama AS nama_peminjam, u.nis, u.kelas, u.no_hp,
           DATEDIFF(CURDATE(), p.tanggal_rencana_kembali) AS terlambat_hari,
           COALESCE(SUM(d.jumlah), 0) AS total_item,
           GROUP_CONCAT(CONCAT(COALESCE(pr.nama_barang, d.nama_barang_snapshot, 'Alat'), ' (', d.jumlah, ')') SEPARATOR ', ') AS daftar_alat
    FROM peminjaman p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id
    LEFT JOIN peralatan pr ON pr.id = d.peralatan_id
    WHERE p.status IN ('DIPINJAM', 'MENUNGGU_PENGEMBALIAN') AND p.tanggal_rencana_kembali <= CURDATE()
    GROUP BY p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.status, u.nama, u.nis, u.kelas, u.no_hp
    ORDER BY p.tanggal_rencana_kembali ASC
    LIMIT 6
");
$overdueLoans = $stmtOverdue->fetchAll();

// 3. Log Aktivitas Terakhir (Khusus Administrator)
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$recentLogs = [];
if ($isAdmin) {
    $stmtRecentLogs = $pdo->query("
        SELECT l.*, u.nama AS nama_user, u.username
        FROM log_aktivitas l
        LEFT JOIN users u ON u.id = l.user_id
        ORDER BY l.id DESC
        LIMIT 6
    ");
    $recentLogs = $stmtRecentLogs->fetchAll();
}

// 4. Master Peralatan Ringkasan Terkini
$recentEquipment = $pdo->query("
    SELECT p.id, p.kode_barang, p.nama_barang, p.jumlah, p.jumlah_tersedia, k.nama_kategori,
           COALESCE(SUM(CASE WHEN u.kondisi = 'Baik' THEN 1 ELSE 0 END), 0) AS unit_baik,
           COALESCE(SUM(CASE WHEN u.kondisi = 'Rusak Ringan' THEN 1 ELSE 0 END), 0) AS unit_rusak_ringan,
           COALESCE(SUM(CASE WHEN u.kondisi = 'Rusak Berat' THEN 1 ELSE 0 END), 0) AS unit_rusak_berat
    FROM peralatan p
    LEFT JOIN kategori k ON k.id = p.kategori_id
    LEFT JOIN unit_peralatan u ON u.peralatan_id = p.id
    WHERE p.status = 'aktif'
    GROUP BY p.id, p.kode_barang, p.nama_barang, p.jumlah, p.jumlah_tersedia, k.nama_kategori
    ORDER BY p.id DESC
    LIMIT 5
")->fetchAll();

page_start('Dashboard Laboratorium', 'Ringkasan operasional dan pemantauan inventaris lab', 'dashboard');
?>

<div class="head">
    <div>
        <h2>Dashboard Pengelolaan Laboratorium</h2>
        <p><?= $isAdmin ? 'Ringkasan data inventaris alat fisik, permohonan peminjaman aktif, keterlambatan, dan log audit.' : 'Ringkasan operasional inventaris laboratorium, sirkulasi peminjaman, dan jatuh tempo.' ?></p>
    </div>
    <div class="actions">
        <a class="btn" href="peminjaman/index.php"><?= $isAdmin ? 'Data Peminjaman' : 'Daftar Peminjaman' ?></a>
        <?php if (!$isAdmin): ?>
        <a class="btn btn-primary" href="peminjaman/tambah.php">+ Pinjamkan Alat</a>
        <?php endif; ?>
    </div>
</div>

<!-- STATS OPERASIONAL UTAMA (1 BARIS KOMPAK) -->
<style>
.dashboard-stats-row {
    display: grid;
    grid-template-columns: repeat(8, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 22px;
}
.stat-box {
    background: #ffffff;
    padding: 10px 10px;
    border-radius: 8px;
    border: 1px solid #e3e7eb;
    min-width: 0;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}
.stat-box:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    border-color: #cbd5e1;
}
.stat-box small {
    display: block;
    font-size: 11px;
    color: #7b8492;
    margin-bottom: 4px;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.stat-box .stat-content {
    display: flex;
    align-items: baseline;
    gap: 5px;
    flex-wrap: wrap;
}
.stat-box strong {
    font-size: 19px;
    color: #0f172a;
    font-weight: 700;
    line-height: 1;
    flex-shrink: 0;
}
.stat-box .stat-sub {
    font-size: 10px;
    color: #64748b;
    line-height: 1.2;
    word-break: break-word;
}

@media (max-width: 900px) {
    .dashboard-stats-row {
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
    }
}
@media (max-width: 540px) {
    .dashboard-stats-row {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }
}
</style>

<div class="dashboard-stats-row">
    <div class="stat-box" title="Master Peralatan: <?= $totalJenisAlat ?> jenis alat aktif">
        <small>Master Peralatan</small>
        <div class="stat-content">
            <strong><?= $totalJenisAlat ?></strong>
            <span class="stat-sub">Jenis alat aktif</span>
        </div>
    </div>
    <div class="stat-box" title="Total Unit Fisik: <?= $totalUnits ?> unit terdata di lab">
        <small>Total Unit Fisik</small>
        <div class="stat-content">
            <strong><?= $totalUnits ?></strong>
            <span class="stat-sub">Unit terdata di lab</span>
        </div>
    </div>
    <div class="stat-box" title="Unit Siap Dipinjam: <?= $unitTersedia ?> kondisi baik & ada di rak">
        <small>Unit Siap Dipinjam</small>
        <div class="stat-content">
            <strong><?= $unitTersedia ?></strong>
            <span class="stat-sub">Kondisi baik & di rak</span>
        </div>
    </div>
    <div class="stat-box" title="Unit Sedang Dipinjam: <?= $unitDipinjam ?> keluar dari laboratorium">
        <small>Unit Dipinjam</small>
        <div class="stat-content">
            <strong><?= $unitDipinjam ?></strong>
            <span class="stat-sub">Keluar dari lab</span>
        </div>
    </div>
    <div class="stat-box" title="Unit Rusak / Hilang: <?= $unitRusak ?> perlu perbaikan">
        <small>Unit Rusak/Hilang</small>
        <div class="stat-content">
            <strong><?= $unitRusak ?></strong>
            <span class="stat-sub">Perlu perbaikan</span>
        </div>
    </div>
    <div class="stat-box" title="Pengajuan Menunggu: <?= $pengajuanMenunggu ?> butuh verifikasi laboran">
        <small>Pengajuan Menunggu</small>
        <div class="stat-content">
            <strong><?= $pengajuanMenunggu ?></strong>
            <span class="stat-sub">Butuh verifikasi</span>
        </div>
    </div>
    <div class="stat-box" title="Peminjaman Terlambat: <?= $peminjamanTerlambat ?> lewat batas waktu">
        <small>Peminjaman Terlambat</small>
        <div class="stat-content">
            <strong><?= $peminjamanTerlambat ?></strong>
            <span class="stat-sub">Lewat batas waktu</span>
        </div>
    </div>
    <div class="stat-box" title="Jatuh Tempo Hari Ini: <?= $jatuhTempoHariIni ?> harus kembali hari ini">
        <small>Jatuh Tempo Hari Ini</small>
        <div class="stat-content">
            <strong><?= $jatuhTempoHariIni ?></strong>
            <span class="stat-sub">Harus kembali hari ini</span>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(460px, 1fr));gap:20px;margin-bottom:24px;">

    <!-- PANEL 1: PENGAJUAN TERBARU (BUTUH VERIFIKASI) -->
    <div class="panel">
        <div class="panel-title" style="display:flex;justify-content:space-between;align-items:center;">
            <span>Pengajuan Menunggu Persetujuan (<?= count($pendingLoans) ?>)</span>
            <a href="peminjaman/index.php" style="font-size:12px;color:#1f4f82;font-weight:600;">Lihat Semua →</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Peminjam</th>
                        <th>Alat yang Diminta</th>
                        <th>Jadwal Kembali</th>
                        <th style="text-align:right;">Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pendingLoans)): ?>
                        <tr>
                            <td colspan="4"><div class="empty">Tidak ada pengajuan peminjaman baru yang menunggu.</div></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pendingLoans as $pl): ?>
                            <tr>
                                <td>
                                    <strong><?= e($pl['nama_peminjam']) ?></strong>
                                    <div style="font-size:11px;color:#64748b;"><?= e($pl['kelas']) ?> &bull; <?= e($pl['kode_peminjaman']) ?></div>
                                </td>
                                <td>
                                    <div style="font-size:12px;font-weight:600;"><?= e($pl['daftar_alat'] ?: '-') ?></div>
                                    <div style="font-size:11px;color:#64748b;"><?= e($pl['keperluan']) ?></div>
                                </td>
                                <td>
                                    <span style="font-size:12px;color:#d97706;font-weight:600;">
                                        <?= date('d-m-Y', strtotime($pl['tanggal_rencana_kembali'])) ?>
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <div class="actions" style="justify-content:flex-end;">
                                        <?php if ($isAdmin): ?>
                                            <a class="btn btn-sm" href="peminjaman/detail.php?id=<?= $pl['id'] ?>">Detail</a>
                                        <?php else: ?>
                                            <a class="btn btn-sm btn-ok" href="peminjaman/index.php">Verifikasi</a>
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

    <!-- PANEL 2: PEMINJAMAN TERLAMBAT / JATUH TEMPO -->
    <div class="panel">
        <div class="panel-title" style="display:flex;justify-content:space-between;align-items:center;">
            <span>Peminjaman Jatuh Tempo & Terlambat (<?= count($overdueLoans) ?>)</span>
            <a href="pengembalian/index.php" style="font-size:12px;color:#1f4f82;font-weight:600;">Proses Kembali →</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Peminjam</th>
                        <th>Alat</th>
                        <th>Keterlambatan</th>
                        <th style="text-align:right;">Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($overdueLoans)): ?>
                        <tr>
                            <td colspan="4"><div class="empty" style="color:#16a34a;">Semua peminjaman alat dalam status aman dan tepat waktu.</div></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($overdueLoans as $ol): ?>
                            <?php 
                                $late = (int)$ol['terlambat_hari']; 
                                $estFine = $late > 0 ? ($late * $tarifDendaHarian) : 0;
                            ?>
                            <tr>
                                <td>
                                    <strong><?= e($ol['nama_peminjam']) ?></strong>
                                    <div style="font-size:11px;color:#64748b;"><?= e($ol['kelas']) ?> &bull; <?= e($ol['kode_peminjaman']) ?></div>
                                </td>
                                <td>
                                    <div style="font-size:12px;"><?= e($ol['daftar_alat'] ?: '-') ?></div>
                                </td>
                                <td>
                                    <?php if ($late > 0): ?>
                                        <span class="badge DITOLAK" style="font-size:10px;">Terlambat <?= $late ?> Hari</span>
                                        <div style="font-size:11px;color:#b91c1c;margin-top:2px;">Denda: <?= format_rupiah($estFine) ?></div>
                                    <?php else: ?>
                                        <span class="badge MENUNGGU" style="font-size:10px;">Jatuh Tempo Hari Ini</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <?php
                                    $rawHp = (string)($ol['no_hp'] ?? '');
                                    $cleanPhone = preg_replace('/[^0-9]/', '', $rawHp);
                                    if (str_starts_with($cleanPhone, '0')) {
                                        $cleanPhone = '62' . substr($cleanPhone, 1);
                                    }
                                    $waText = "Halo {$ol['nama_peminjam']}, kami dari Lab Komputer RPL SMKN 1 Panyingkiran mengingatkan bahwa peminjaman alat: {$ol['daftar_alat']} (Kode: {$ol['kode_peminjaman']}) telah melewati batas pengembalian (" . date('d-m-Y', strtotime($ol['tanggal_rencana_kembali'])) . ")." . ($late > 0 ? " Keterlambatan: {$late} hari (Estimasi Denda: " . format_rupiah($estFine) . ")." : "") . " Mohon segera mengembalikan alat tersebut ke ruang laboratorium. Terima kasih.";
                                    $waLink = $cleanPhone !== '' ? "https://wa.me/{$cleanPhone}?text=" . urlencode($waText) : '';
                                    ?>
                                    <div class="actions" style="justify-content:flex-end;gap:5px;">
                                        <?php if ($waLink !== '' && !$isAdmin): ?>
                                            <a class="btn btn-sm" href="<?= $waLink ?>" target="_blank" rel="noopener"
                                               style="background:#22c55e;color:#ffffff;border:none;font-size:11px;padding:4px 8px;display:inline-flex;align-items:center;gap:4px;"
                                               title="Kirim pengingat WhatsApp ke <?= e($rawHp) ?>">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                                                WA
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($isAdmin): ?>
                                            <a class="btn btn-sm" href="peminjaman/detail.php?id=<?= $ol['id'] ?>">Detail</a>
                                        <?php else: ?>
                                            <a class="btn btn-sm btn-primary" href="pengembalian/index.php?rfid=<?= urlencode($ol['kode_peminjaman']) ?>">Terima</a>
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

</div>

<!-- PANEL BAWAH: AUDIT LOG TERBARU & RINGKASAN KATEGORI -->
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(460px, 1fr));gap:20px;">

    <?php if ($isAdmin): ?>
    <!-- AUDIT TRAIL AKTIVITAS (KHUSUS ADMINISTRATOR) -->
    <div class="panel">
        <div class="panel-title" style="display:flex;justify-content:space-between;align-items:center;">
            <span>Aktivitas Laboratorium Terkini (Audit Log)</span>
            <a href="log/index.php" style="font-size:12px;color:#1f4f82;font-weight:600;">Lihat Log Lengkap →</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Operator</th>
                        <th>Aksi</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentLogs)): ?>
                        <tr>
                            <td colspan="4"><div class="empty">Belum ada catatan audit aktivitas.</div></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentLogs as $rl): ?>
                            <tr>
                                <td style="font-size:11px;color:#64748b;white-space:nowrap;">
                                    <?= date('d-m H:i', strtotime($rl['created_at'])) ?>
                                </td>
                                <td>
                                    <strong style="font-size:12px;"><?= e($rl['nama_user'] ?: 'Sistem') ?></strong>
                                </td>
                                <td>
                                    <span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;font-family:monospace;">
                                        <?= e($rl['aksi'] ?: ($rl['aktivitas'] ?? 'INFO')) ?>
                                    </span>
                                </td>
                                <td style="font-size:12px;line-height:1.3;">
                                    <?= e($rl['deskripsi']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- RINGKASAN MASTER PERALATAN TERKINI -->
    <div class="panel">
        <div class="panel-title" style="display:flex;justify-content:space-between;align-items:center;">
            <span>Master Peralatan Terkini</span>
            <a href="peralatan/index.php" style="font-size:12px;color:#1f4f82;font-weight:600;">Kelola Peralatan →</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nama Alat</th>
                        <th>Kategori</th>
                        <th>Kondisi Fisik</th>
                        <th style="text-align:right;">Stok Tersedia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentEquipment)): ?>
                        <tr>
                            <td colspan="4"><div class="empty">Belum ada master peralatan terdaftar.</div></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentEquipment as $eq): ?>
                            <tr>
                                <td>
                                    <strong><?= e($eq['nama_barang']) ?></strong>
                                    <div style="font-size:11px;color:#64748b;font-family:monospace;"><?= e($eq['kode_barang']) ?></div>
                                </td>
                                <td>
                                    <span style="font-size:11px;background:#f1f5f9;color:#334155;padding:2px 8px;border-radius:12px;font-weight:600;">
                                        <?= e($eq['nama_kategori'] ?: 'Umum') ?>
                                    </span>
                                </td>
                                <td style="font-size:11px;">
                                    <span style="color:#166534;font-weight:600;"><?= (int)$eq['unit_baik'] ?> Baik</span>
                                    <?php if ((int)$eq['unit_rusak_ringan'] > 0): ?>
                                        &bull; <span style="color:#d97706;font-weight:600;"><?= (int)$eq['unit_rusak_ringan'] ?> R. Ringan</span>
                                    <?php endif; ?>
                                    <?php if ((int)$eq['unit_rusak_berat'] > 0): ?>
                                        &bull; <span style="color:#dc2626;font-weight:600;"><?= (int)$eq['unit_rusak_berat'] ?> R. Berat</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <strong style="color:#0f172a;"><?= (int)$eq['jumlah_tersedia'] ?></strong>
                                    <span style="font-size:11px;color:#64748b;">/ <?= (int)$eq['jumlah'] ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php page_end(); ?>