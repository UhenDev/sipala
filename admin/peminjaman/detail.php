<?php
require_once __DIR__ . '/../includes/admin.php';

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
    SELECT d.jumlah, pr.kode_barang, pr.nama_barang, pr.kondisi, pr.lokasi, k.nama_kategori,
           u.kode_unit, u.serial_number, u.rfid_uid AS unit_rfid
    FROM detail_peminjaman d 
    JOIN peralatan pr ON pr.id = d.peralatan_id 
    LEFT JOIN kategori k ON k.id = pr.kategori_id
    LEFT JOIN unit_peralatan u ON u.id = d.unit_peralatan_id
    WHERE d.peminjaman_id = ? 
    ORDER BY d.id
');
$q->execute([$id]);
$items = $q->fetchAll();

page_start('Detail Peminjaman', 'Rincian transaksi & status validasi RFID', 'peminjaman');
?>

<div class="head">
    <div>
        <h2>Detail Peminjaman: <?= e($p['kode_peminjaman']) ?></h2>
        <p>Tanggal Pengajuan: <?= e(date('d-m-Y H:i', strtotime($p['tanggal_pengajuan']))) ?></p>
    </div>
    <div class="actions">
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

<?php page_end(); ?>
