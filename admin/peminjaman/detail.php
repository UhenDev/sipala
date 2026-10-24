<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/qrcode.php';

$id = (int)($_GET['id'] ?? 0);
$q = $pdo->prepare('
    SELECT p.*, u.nama AS nama_peminjam, u.username, u.nis, u.kelas, u.no_hp,
           petugas.nama AS nama_petugas
    FROM peminjaman p 
    LEFT JOIN users u ON u.id = p.user_id 
    LEFT JOIN users petugas ON petugas.id = p.rfid_verified_by
    WHERE p.id = ?
');
$q->execute([$id]);
$p = $q->fetch();

if (!$p) {
    redirect('index.php');
}

$q = $pdo->prepare('
    SELECT d.jumlah, 
           COALESCE(pr.kode_barang, d.kode_barang_snapshot, "-") AS kode_barang,
           COALESCE(pr.nama_barang, d.nama_barang_snapshot, "Alat Terhapus") AS nama_barang,
           COALESCE(pr.kondisi, d.kondisi_awal, "-") AS kondisi,
           COALESCE(pr.lokasi, "-") AS lokasi,
           COALESCE(k.nama_kategori, "-") AS nama_kategori,
           COALESCE(u.kode_unit, d.kode_unit_snapshot, "-") AS kode_unit,
           COALESCE(u.barcode, d.barcode_snapshot, "-") AS barcode,
           COALESCE(u.serial_number, "-") AS serial_number,
           u.rfid_uid AS unit_rfid
    FROM detail_peminjaman d 
    LEFT JOIN peralatan pr ON pr.id = d.peralatan_id 
    LEFT JOIN kategori k ON k.id = pr.kategori_id
    LEFT JOIN unit_peralatan u ON u.id = d.unit_peralatan_id
    WHERE d.peminjaman_id = ? 
    ORDER BY d.id
');
$q->execute([$id]);
$items = $q->fetchAll();

page_start('Detail Peminjaman', 'Rincian transaksi & status validasi RFID', 'peminjaman');
?>

<style>
@media print {
    .sidebar, .sidebar-overlay, .topbar, .actions, .sidebar-toggle-btn, button {
        display: none !important;
    }
    .main {
        margin-left: 0 !important;
        width: 100% !important;
    }
    .content {
        padding: 10mm 15mm !important;
    }
    body {
        background: #ffffff !important;
        color: #000000 !important;
        font-size: 11pt !important;
    }
    .head {
        display: none !important;
    }
    .print-kop, .print-signatures {
        display: block !important;
    }
    .panel, .form-panel {
        box-shadow: none !important;
        border: 1px solid #777 !important;
        margin-bottom: 14px !important;
        page-break-inside: avoid;
    }
    .grid {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 8px !important;
    }
    .field input {
        border: 1px solid #999 !important;
        background: transparent !important;
        padding: 4px 6px !important;
        font-size: 10pt !important;
    }
    table {
        width: 100% !important;
        border-collapse: collapse !important;
    }
    th, td {
        border: 1px solid #444 !important;
        padding: 6px 8px !important;
        font-size: 10pt !important;
    }
    th {
        background: #e5e7eb !important;
        color: #000 !important;
    }
    .badge {
        border: none !important;
        padding: 0 !important;
        background: transparent !important;
        color: #000 !important;
        font-weight: 600 !important;
    }
}
</style>

<!-- KOP RESMI CETAK (Hanya Muncul Saat Print) -->
<div class="print-kop" style="display:none;">
    <div style="display:flex;align-items:center;gap:16px;border-bottom:3px double #000000;padding-bottom:12px;margin-bottom:14px;">
        <img src="../../assets/img/logo.jpg" alt="Logo" style="width:60px;height:60px;object-fit:cover;border-radius:4px;">
        <div style="flex:1;text-align:center;">
            <h3 style="margin:0;font-size:15px;text-transform:uppercase;letter-spacing:0.5px;color:#000;">SMK NEGERI 1 PANYINGKIRAN</h3>
            <h2 style="margin:2px 0 0;font-size:17px;font-weight:bold;color:#000;">LABORATORIUM REKAYASA PERANGKAT LUNAK</h2>
            <p style="margin:2px 0 0;font-size:10.5px;color:#333;">Jl. Kirana, Majalengka &bull; SIPALA - Sistem Informasi Peminjaman Alat</p>
        </div>
    </div>
    <div style="text-align:center;margin-bottom:14px;">
        <h4 style="margin:0;font-size:14px;text-transform:uppercase;text-decoration:underline;">TANDA TERIMA PEMINJAMAN ALAT LABORATORIUM</h4>
        <span style="font-size:11.5px;color:#444;">Nomor Dokumen: <?= e($p['kode_peminjaman']) ?></span>
    </div>
</div>

<div class="head">
    <div>
        <h2>Detail Peminjaman: <?= e($p['kode_peminjaman']) ?></h2>
        <p>Tanggal Pengajuan: <?= e(date('d-m-Y H:i', strtotime($p['tanggal_pengajuan']))) ?></p>
    </div>
    <div class="actions">
        <?php if (!empty($p['no_hp']) && ($role ?? '') === 'petugas'): ?>
            <button type="button" class="btn" data-wa-target
                    data-wa-id="<?= $p['id'] ?>"
                    data-wa-nama="<?= e($p['nama_peminjam'] ?? '') ?>"
                    data-wa-kelas="<?= e($p['kelas'] ?? '') ?>"
                    data-wa-phone="<?= e($p['no_hp']) ?>"
                    data-wa-kode="<?= e($p['kode_peminjaman']) ?>"
                    data-wa-kembali="<?= e(date('d-m-Y', strtotime($p['tanggal_rencana_kembali']))) ?>"
                    data-wa-type="pengingat"
                    style="background:#22c55e;color:#ffffff;border:none;font-weight:700;">
                💬 Kirim Pesan WA
            </button>
        <?php endif; ?>
        <button class="btn" onclick="window.print()">🖨️ Cetak Tanda Terima</button>
        <a class="btn" href="index.php">Kembali</a>
    </div>
</div>

<!-- BANNER VALIDASI RFID -->
<?php if (!empty($p['rfid_verified_at'])): ?>
    <div style="background:#ecfdf5;border:1px solid #86efac;border-radius:12px;padding:18px 22px;margin-bottom:24px;display:flex;align-items:center;gap:16px;">
        <div style="font-size:32px;">🛡️</div>
        <div>
            <h4 style="margin:0 0 4px 0;color:#15803d;font-size:16px;">Peminjaman Terverifikasi Sah dengan Smart Card RFID</h4>
            <div style="font-size:13px;color:#166534;">
                Nomor Chip Kartu: <strong><?= e($p['rfid_uid'] ?? '-') ?></strong> &bull;
                Waktu Validasi: <strong><?= e(date('d-m-Y H:i:s', strtotime($p['rfid_verified_at']))) ?></strong> &bull;
                Petugas Verifikator: <strong><?= e($p['nama_petugas'] ?? 'Administrator') ?></strong>
            </div>
        </div>
    </div>
<?php else: ?>
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:18px 22px;margin-bottom:24px;display:flex;align-items:center;gap:16px;">
        <div style="font-size:32px;">⚠️</div>
        <div>
            <h4 style="margin:0 0 4px 0;color:#b45309;font-size:16px;">Belum Terverifikasi Fisik Melalui RFID</h4>
            <div style="font-size:13px;color:#92400e;">
                Transaksi ini dibuat tanpa melalui pemindaian chip fisik RFID saat penyerahan alat.
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="form-panel" style="margin-bottom:24px;">
    <h3 style="margin:0 0 16px 0;font-size:16px;">Informasi Peminjam & Jadwal</h3>
    <div class="grid">
        <div class="field">
            <label>Nama Siswa (Peminjam)</label>
            <input readonly value="<?= e($p['nama_peminjam'] ?? '-') ?>">
        </div>
        <div class="field">
            <label>NIS & Kelas</label>
            <input readonly value="<?= e(($p['nis'] ?: '-') . ' / ' . ($p['kelas'] ?: '-')) ?>">
        </div>
        <div class="field">
            <label>Tanggal Pinjam</label>
            <input readonly value="<?= e(date('d-m-Y', strtotime($p['tanggal_pinjam'] ?: $p['tanggal_pengajuan']))) ?>">
        </div>
        <div class="field">
            <label>Batas Tanggal Kembali</label>
            <input readonly value="<?= e(date('d-m-Y', strtotime($p['tanggal_rencana_kembali']))) ?>">
        </div>
        <div class="field">
            <label>Status Transaksi</label>
            <input readonly value="<?= e($p['status']) ?>">
        </div>
        <div class="field">
            <label>Keperluan</label>
            <input readonly value="<?= e($p['keperluan'] ?: '-') ?>">
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-title">Daftar Peralatan Laboratorium yang Dipinjam</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Kode Barang</th>
                    <th>Nama Peralatan</th>
                    <th>Unit Fisik Dialokasikan</th>
                    <th>Kategori</th>
                    <th>Jumlah</th>
                    <th>Kondisi Saat Dipinjam</th>
                    <th>Lokasi Rak/Lab</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="8"><div class="empty">Detail peralatan tidak ditemukan.</div></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $i => $it): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong style="font-family:monospace;"><?= e($it['kode_barang']) ?></strong></td>
                            <td><?= e($it['nama_barang']) ?></td>
                            <td>
                                <?php if (!empty($it['kode_unit'])): ?>
                                    <strong style="font-family:monospace;color:#1e40af;"><?= e($it['kode_unit']) ?></strong>
                                    <?php if (!empty($it['barcode']) && $it['barcode'] !== '-'): ?>
                                        <div style="font-family:'JetBrains Mono', monospace;font-size:11px;color:#047857;font-weight:700;margin-top:2px;">
                                            🏷️ <?= e($it['barcode']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm" data-qr-target
                                            data-qr-title="<?= e($it['nama_barang']) ?>"
                                            data-qr-code="<?= e($it['barcode'] !== '-' ? $it['barcode'] : $it['kode_unit']) ?>"
                                            data-qr-scan="<?= e(get_unit_scan_url($it['barcode'] !== '-' ? $it['barcode'] : $it['kode_unit'])) ?>"
                                            data-qr-desc="Peminjaman: <?= e($p['kode_peminjaman']) ?>"
                                            style="background:#f1f5f9;border:1px solid #cbd5e1;padding:2px 6px;font-size:10px;margin-top:4px;cursor:pointer;">
                                        📱 QR / Barcode
                                    </button>
                                    <?php if (!empty($it['unit_rfid'])): ?>
                                        <div style="font-size:10px;color:#64748b;font-family:monospace;">RFID: <?= e($it['unit_rfid']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:12px;font-style:italic;">Belum dialokasikan</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($it['nama_kategori'] ?? '-') ?></td>
                            <td><span style="font-weight:700;"><?= $it['jumlah'] ?></span> unit</td>
                            <td><span class="badge aktif"><?= e($it['kondisi']) ?></span></td>
                            <td><?= e($it['lokasi'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- TANDA TANGAN RESMI (Hanya Muncul Saat Print) -->
<div class="print-signatures" style="display:none;margin-top:40px;page-break-inside:avoid;">
    <div style="display:flex;justify-content:space-between;text-align:center;font-size:11pt;padding:0 20px;">
        <div style="width:220px;">
            <p style="margin:0 0 70px 0;">Peminjam / Siswa,</p>
            <p style="margin:0;font-weight:bold;text-decoration:underline;"><?= e($p['nama_peminjam'] ?? '-') ?></p>
            <p style="margin:2px 0 0;color:#555;font-size:10pt;">NIS: <?= e($p['nis'] ?: '-') ?> &bull; <?= e($p['kelas'] ?: '-') ?></p>
        </div>
        <div style="width:220px;">
            <p style="margin:0 0 70px 0;">Petugas / Laboran Lab,</p>
            <p style="margin:0;font-weight:bold;text-decoration:underline;"><?= e($p['nama_petugas'] ?? $_SESSION['nama'] ?? 'Petugas Lab') ?></p>
            <p style="margin:2px 0 0;color:#555;font-size:10pt;">Lab Komputer RPL</p>
        </div>
    </div>
</div>

<?php page_end(); ?>
