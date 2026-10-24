<?php

/**
 * FILE       : admin/peralatan/index.php
 * MODUL      : Master Inventaris Peralatan
 * FUNGSI     : Menampilkan katalog master jenis alat laboratorium beserta agregasi unit fisiknya
 * AKSES      : Administrator / Petugas Laboratorium
 *
 * RELASI ARSITEKTUR:
 * - Tabel `peralatan` menyimpan definisi jenis/master alat (misal: "Mikroskop Binokuler", "Multimeter Digital").
 * - Tabel `unit_peralatan` menyimpan unit fisik nyata setiap barang (misal: "MKS-001", "MKS-002").
 * - Kolom Kondisi Fisik pada halaman ini merupakan hasil kalkulasi dinamis dari tabel unit fisik nyata.
 *
 * ALUR:
 * 1. Memeriksa autentikasi dan hak akses admin/laboran.
 * 2. Menangani aksi hapus jika ada request POST dengan validasi constraint relasi.
 * 3. Mengambil ringkasan master alat dengan agregasi kondisi fisik unit (Baik, Rusak Ringan, Rusak Berat).
 * 4. Menyajikan data dalam tabel informatif dilengkapi tombol filter interaktif ke modul unit fisik.
 */

require_once __DIR__ . '/../includes/admin.php';
require_admin();
require_once __DIR__ . '/../../includes/qrcode.php';

// Menangani permintaan penghapusan master alat jika dikirim via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    require_csrf();
    try {
        $q = $pdo->prepare('DELETE FROM peralatan WHERE id = ?');
        $q->execute([(int)$_POST['delete_id']]);
        flash('success', 'Peralatan berhasil dihapus.');
    } catch (Throwable $e) {
        flash('error', 'Peralatan gagal dihapus karena masih terkait data unit fisik atau riwayat transaksi peminjaman.');
    }
    redirect('index.php');
}

$rows = $pdo->query("
    SELECT p.id, p.kode_barang, p.nama_barang, p.kategori_id, p.jumlah, p.jumlah_tersedia, 
           p.kondisi, p.lokasi, p.foto, p.status, k.nama_kategori,
           COUNT(u.id) AS total_unit_fisik,
           COALESCE(SUM(CASE WHEN u.kondisi = 'Baik' THEN 1 ELSE 0 END), 0) AS unit_baik,
           COALESCE(SUM(CASE WHEN u.kondisi = 'Rusak Ringan' THEN 1 ELSE 0 END), 0) AS unit_rusak_ringan,
           COALESCE(SUM(CASE WHEN u.kondisi = 'Rusak Berat' THEN 1 ELSE 0 END), 0) AS unit_rusak_berat
    FROM peralatan p 
    LEFT JOIN kategori k ON k.id = p.kategori_id 
    LEFT JOIN unit_peralatan u ON u.peralatan_id = p.id
    GROUP BY p.id, p.kode_barang, p.nama_barang, p.kategori_id, p.jumlah, p.jumlah_tersedia, 
             p.kondisi, p.lokasi, p.foto, p.status, k.nama_kategori
    ORDER BY p.id DESC
")->fetchAll();

$totalMasterAlat = count($rows);
$totalUnitFisik = array_sum(array_map(fn($r) => (int)$r['jumlah'], $rows));
$totalUnitBaik = array_sum(array_map(fn($r) => (int)$r['unit_baik'], $rows));
$totalUnitRusakRingan = array_sum(array_map(fn($r) => (int)$r['unit_rusak_ringan'], $rows));
$totalUnitRusakBerat = array_sum(array_map(fn($r) => (int)$r['unit_rusak_berat'], $rows));

page_start('Peralatan', 'Kelola data alat laboratorium', 'peralatan'); 
?>

<style>
.kondisi-stack {
    display: inline-flex;
    flex-direction: column;
    gap: 3px;
    min-width: 124px;
}
.kondisi-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 2.5px 8px;
    border-radius: 6px;
    font-size: 11px;
    text-decoration: none;
    line-height: 1.25;
    transition: all 0.15s ease;
    border: 1px solid transparent;
}
.kondisi-pill:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.06);
}
.kondisi-pill .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}
.kondisi-pill .num {
    font-weight: 700;
    font-size: 11px;
}
.kondisi-pill .txt {
    font-weight: 500;
    font-size: 10.5px;
}

/* Kondisi Baik (Hijau) */
.kondisi-pill.cond-baik {
    background: #f0fdf4;
    color: #166534;
    border-color: #bbf7d0;
}
.kondisi-pill.cond-baik .dot {
    background: #16a34a;
}

/* Kondisi Rusak Ringan (Kuning/Amber) */
.kondisi-pill.cond-ringan {
    background: #fffbeb;
    color: #92400e;
    border-color: #fde68a;
}
.kondisi-pill.cond-ringan .dot {
    background: #d97706;
}

/* Kondisi Rusak Berat (Merah) */
.kondisi-pill.cond-berat {
    background: #fef2f2;
    color: #991b1b;
    border-color: #fecaca;
}
.kondisi-pill.cond-berat .dot {
    background: #dc2626;
}

/* Kondisi 0 Unit (Netral/Muted) */
.kondisi-pill.cond-zero {
    background: #f8fafc;
    color: #94a3b8;
    border-color: #e2e8f0;
    opacity: 0.85;
}
.kondisi-pill.cond-zero .dot {
    background: #cbd5e1;
}
.kondisi-pill.cond-zero .num {
    color: #64748b;
}
</style>

<div class="head">
    <div>
        <h2>Data Peralatan Laboratorium</h2>
        <p>Kelola inventaris master, foto alat, stok fisik, kondisi, dan status ketersediaan.</p>
    </div>
    <div class="actions">
        <a class="btn" href="cetak_label.php" target="_blank" style="background:#f8fafc;color:#0f172a;border:1px solid #cbd5e1;font-weight:600;">🖨️ Cetak Label QR Master</a>
        <a class="btn" href="../unit/index.php">&#x25a6; Kelola Unit Fisik</a>
        <a class="btn btn-primary" href="tambah.php">+ Tambah Master Peralatan</a>
    </div>
</div>

<div class="stats" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));">
    <div class="stat"><small>Jenis Master Alat</small><strong><?= $totalMasterAlat ?></strong><span style="font-size:11px;color:#64748b;">Master aktif</span></div>
    <div class="stat"><small>Total Unit Fisik</small><strong><?= $totalUnitFisik ?></strong><span style="font-size:11px;color:#64748b;">Semua unit</span></div>
    <div class="stat"><small>Kondisi Baik</small><strong><?= $totalUnitBaik ?></strong><span style="font-size:11px;color:#16a34a;font-weight:600;">Siap dipakai</span></div>
    <div class="stat"><small>Rusak Ringan</small><strong><?= $totalUnitRusakRingan ?></strong><span style="font-size:11px;color:#d97706;font-weight:600;">Perlu perbaikan</span></div>
    <div class="stat"><small>Rusak Berat</small><strong><?= $totalUnitRusakBerat ?></strong><span style="font-size:11px;color:#dc2626;font-weight:600;">Tidak layak pakai</span></div>
</div>

<div class="panel">
    <div class="panel-title">Daftar Peralatan</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:40px;">No</th>
                    <th style="width:60px;">Foto</th>
                    <th>Kode Alat</th>
                    <th>Nama Barang</th>
                    <th>Kategori</th>
                    <th>Stok</th>
                    <th>Tersedia</th>
                    <th style="min-width:130px;">Kondisi Fisik Unit</th>
                    <th>Lokasi</th>
                    <th style="text-align:right;">Tindakan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="10"><div class="empty">Belum ada data peralatan laboratorium.</div></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <?php if (!empty($r['foto']) && file_exists(__DIR__ . '/../../' . $r['foto'])): ?>
                                    <img src="../../<?= e($r['foto']) ?>" alt="<?= e($r['nama_barang']) ?>" class="sipala-zoomable-img"
                                         data-lightbox-src="../../<?= e($r['foto']) ?>"
                                         data-lightbox-title="<?= e($r['nama_barang']) ?>"
                                         data-lightbox-sub="Kode: <?= e($r['kode_barang']) ?> &bull; Kategori: <?= e($r['nama_kategori'] ?? '-') ?>"
                                         data-lightbox-badge="Unit: <?= (int)$r['unit_baik'] ?> Baik &bull; <?= (int)$r['unit_rusak_ringan'] ?> R. Ringan &bull; <?= (int)$r['unit_rusak_berat'] ?> R. Berat"
                                         data-lightbox-meta="Lokasi: <?= e($r['lokasi'] ?: '-') ?> &bull; Stok: <?= $r['jumlah_tersedia'] ?> / <?= $r['jumlah'] ?> unit"
                                         title="Klik untuk melihat foto ukuran besar"
                                         style="width:42px;height:42px;object-fit:cover;border-radius:6px;border:1px solid #cbd5e1;display:block;cursor:zoom-in;">
                                <?php else: ?>
                                    <div style="width:42px;height:42px;border-radius:6px;background:#f1f5f9;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:10px;font-weight:700;">
                                        FOTO
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><strong style="font-family:monospace;"><?= e((string)$r['kode_barang']) ?></strong></td>
                            <td>
                                <strong><?= e((string)$r['nama_barang']) ?></strong>
                                <?php if ($r['status'] === 'nonaktif'): ?>
                                    <span class="badge nonaktif" style="margin-left:6px;font-size:10px;">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string)($r['nama_kategori'] ?? '-')) ?></td>
                            <td><?= $r['jumlah'] ?></td>
                            <td>
                                <?php if ($r['jumlah_tersedia'] > 0): ?>
                                    <strong style="color:#16a34a;"><?= $r['jumlah_tersedia'] ?></strong>
                                <?php else: ?>
                                    <span style="color:#dc2626;font-weight:600;">0 (Habis)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$r['total_unit_fisik'] > 0): ?>
                                    <div class="kondisi-stack">
                                        <a href="../unit/index.php?peralatan_id=<?= $r['id'] ?>&kondisi=Baik" 
                                           class="kondisi-pill cond-baik" 
                                           title="Klik untuk filter <?= (int)$r['unit_baik'] ?> unit kondisi Baik">
                                            <span class="dot"></span>
                                            <span class="num"><?= (int)$r['unit_baik'] ?></span>
                                            <span class="txt">Baik</span>
                                        </a>
                                        <a href="../unit/index.php?peralatan_id=<?= $r['id'] ?>&kondisi=Rusak+Ringan" 
                                           class="kondisi-pill <?= (int)$r['unit_rusak_ringan'] > 0 ? 'cond-ringan' : 'cond-zero' ?>" 
                                           title="Klik untuk filter <?= (int)$r['unit_rusak_ringan'] ?> unit kondisi Rusak Ringan">
                                            <span class="dot"></span>
                                            <span class="num"><?= (int)$r['unit_rusak_ringan'] ?></span>
                                            <span class="txt">Rusak Ringan</span>
                                        </a>
                                        <a href="../unit/index.php?peralatan_id=<?= $r['id'] ?>&kondisi=Rusak+Berat" 
                                           class="kondisi-pill <?= (int)$r['unit_rusak_berat'] > 0 ? 'cond-berat' : 'cond-zero' ?>" 
                                           title="Klik untuk filter <?= (int)$r['unit_rusak_berat'] ?> unit kondisi Rusak Berat">
                                            <span class="dot"></span>
                                            <span class="num"><?= (int)$r['unit_rusak_berat'] ?></span>
                                            <span class="txt">Rusak Berat</span>
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <span style="font-size:11px;color:#94a3b8;font-style:italic;">Belum ada unit</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string)($r['lokasi'] ?? '-')) ?></td>
                            <td style="text-align:right;">
                                <div class="actions" style="justify-content:flex-end;">
                                    <button type="button" class="btn btn-sm" data-qr-target
                                            data-qr-title="<?= e($r['nama_barang']) ?>"
                                            data-qr-code="<?= e($r['kode_barang']) ?>"
                                            data-qr-scan="<?= e(get_alat_scan_url($r['kode_barang'])) ?>"
                                            data-qr-print="cetak_label.php?id=<?= $r['id'] ?>"
                                            data-qr-desc="Kategori: <?= e($r['nama_kategori'] ?? 'Umum') ?> &bull; Lokasi: <?= e($r['lokasi'] ?: '-') ?>"
                                            style="background:#f8fafc;border:1px solid #cbd5e1;color:#0f172a;font-size:11px;font-weight:600;"
                                            title="Lihat & Unduh Kode QR Master Alat">📱 QR</button>
                                    <a class="btn btn-sm" href="../unit/index.php?peralatan_id=<?= $r['id'] ?>" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;font-size:11px;">Unit (<?= $r['jumlah'] ?>)</a>
                                    <a class="btn btn-sm btn-edit" href="edit.php?id=<?= $r['id'] ?>">Edit</a>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="confirmDelete('hapus.php?id=<?= $r['id'] ?>', 'Peralatan <?= e(addslashes($r['nama_barang'])) ?>')">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php page_end(); ?>