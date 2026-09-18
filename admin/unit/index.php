<?php

/**
 * FILE       : admin/unit/index.php
 * MODUL      : Manajemen Unit Fisik Peralatan
 * FUNGSI     : Melacak dan mengelola inventaris unit fisik individual, nomor seri pabrik, tag RFID alat, kondisi, dan status ketersediaan
 * AKSES      : Administrator / Petugas Laboratorium
 *
 * KONSEP ARSITEKTUR:
 * - Master `peralatan` = Jenis katalog alat.
 * - `unit_peralatan`   = Barang fisik nyata dengan identifikasi unik per unit.
 * - Penghapusan unit diblokir jika statusnya sedang DIPINJAM.
 * - Setiap penambahan atau penghapusan unit memicu `sync_peralatan_stock()` untuk menjaga akurasi stok master.
 *
 * ALUR:
 * 1. Memeriksa otorisasi admin/petugas.
 * 2. Menangani aksi hapus unit individual via POST dengan validasi status 'DIPINJAM'.
 * 3. Mengambil filter pencarian (kode unit, serial, RFID, master alat, status, kondisi).
 * 4. Menampilkan data dalam tabel lengkap beserta badge kondisi dan tautan tindakan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

// Handle single delete via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
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
        flash('error', 'Gagal menghapus unit: ' . $e->getMessage());
    }
    redirect('index.php');
}

// Ambil daftar master peralatan untuk dropdown filter
$equipmentList = $pdo->query("SELECT id, nama_barang, kode_barang FROM peralatan ORDER BY nama_barang ASC")->fetchAll();

// Filter parameters
$selectedAlat = (int)($_GET['peralatan_id'] ?? 0);
$selectedStatus = trim((string)($_GET['status'] ?? ''));
$selectedKondisi = trim((string)($_GET['kondisi'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$sql = "
    SELECT u.id, u.kode_unit, u.serial_number, u.rfid_uid, u.kondisi, u.lokasi, u.status, u.catatan, u.created_at,
           p.id AS peralatan_id, p.nama_barang, p.kode_barang, k.nama_kategori
    FROM unit_peralatan u
    JOIN peralatan p ON p.id = u.peralatan_id
    LEFT JOIN kategori k ON k.id = p.kategori_id
    WHERE 1=1
";
$params = [];

if ($selectedAlat > 0) {
    $sql .= " AND u.peralatan_id = ?";
    $params[] = $selectedAlat;
}

if ($selectedStatus !== '') {
    $sql .= " AND u.status = ?";
    $params[] = $selectedStatus;
}

if ($selectedKondisi !== '') {
    $sql .= " AND u.kondisi = ?";
    $params[] = $selectedKondisi;
}

if ($search !== '') {
    $sql .= " AND (u.kode_unit LIKE ? OR u.serial_number LIKE ? OR u.rfid_uid LIKE ? OR u.lokasi LIKE ? OR p.nama_barang LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY p.nama_barang ASC, u.kode_unit ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Global stats
$totalUnits = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan")->fetchColumn();
$totalTersedia = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan WHERE status = 'TERSEDIA' AND kondisi != 'Rusak Berat'")->fetchColumn();
$totalDipinjam = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan WHERE status = 'DIPINJAM'")->fetchColumn();
$totalRusak = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan WHERE status = 'RUSAK' OR kondisi = 'Rusak Berat'")->fetchColumn();

page_start('Unit Peralatan', 'Pelacakan inventaris unit fisik laboratorium', 'unit');
?>

<div class="head">
    <div>
        <h2>Unit Fisik Peralatan Laboratorium</h2>
        <p>Kelola nomor inventaris unit individual, nomor seri, tag RFID alat, kondisi fisik, dan status pinjam.</p>
    </div>
    <div class="actions">
        <a class="btn" href="../peralatan/index.php">← Master Peralatan</a>
        <a class="btn btn-primary" href="tambah.php">+ Tambah Unit Fisik</a>
    </div>
</div>

<div class="stats">
    <div class="stat"><small>Total Seluruh Unit</small><strong><?= $totalUnits ?></strong></div>
    <div class="stat"><small>Unit Tersedia (Siap)</small><strong style="color:#16a34a;"><?= $totalTersedia ?></strong></div>
    <div class="stat"><small>Unit Sedang Dipinjam</small><strong style="color:#2563eb;"><?= $totalDipinjam ?></strong></div>
    <div class="stat"><small>Unit Rusak / Maintenance</small><strong style="color:#dc2626;"><?= $totalRusak ?></strong></div>
</div>

<div class="panel" style="margin-bottom: 20px;">
    <form method="GET" action="index.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="field" style="flex:1;min-width:200px;">
            <label>Cari Unit / Serial / RFID</label>
            <input type="text" name="q" placeholder="Kode unit, nama alat, serial..." value="<?= e($search) ?>">
        </div>

        <div class="field" style="min-width:180px;">
            <label>Master Peralatan</label>
            <select name="peralatan_id">
                <option value="">-- Semua Peralatan --</option>
                <?php foreach ($equipmentList as $eq): ?>
                    <option value="<?= $eq['id'] ?>" <?= $selectedAlat === (int)$eq['id'] ? 'selected' : '' ?>>
                        <?= e($eq['nama_barang']) ?> (<?= e($eq['kode_barang']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="min-width:140px;">
            <label>Status Unit</label>
            <select name="status">
                <option value="">-- Semua Status --</option>
                <option value="TERSEDIA" <?= $selectedStatus === 'TERSEDIA' ? 'selected' : '' ?>>TERSEDIA</option>
                <option value="DIPINJAM" <?= $selectedStatus === 'DIPINJAM' ? 'selected' : '' ?>>DIPINJAM</option>
                <option value="RUSAK" <?= $selectedStatus === 'RUSAK' ? 'selected' : '' ?>>RUSAK</option>
                <option value="HILANG" <?= $selectedStatus === 'HILANG' ? 'selected' : '' ?>>HILANG</option>
                <option value="MAINTENANCE" <?= $selectedStatus === 'MAINTENANCE' ? 'selected' : '' ?>>MAINTENANCE</option>
            </select>
        </div>

        <div class="field" style="min-width:140px;">
            <label>Kondisi Fisik</label>
            <select name="kondisi">
                <option value="">-- Semua Kondisi --</option>
                <option value="Baik" <?= $selectedKondisi === 'Baik' ? 'selected' : '' ?>>Baik</option>
                <option value="Rusak Ringan" <?= $selectedKondisi === 'Rusak Ringan' ? 'selected' : '' ?>>Rusak Ringan</option>
                <option value="Rusak Berat" <?= $selectedKondisi === 'Rusak Berat' ? 'selected' : '' ?>>Rusak Berat</option>
            </select>
        </div>

        <div style="display:flex;gap:6px;">
            <button type="submit" class="btn btn-primary" style="height:38px;">Filter</button>
            <?php if ($selectedAlat || $selectedStatus || $selectedKondisi || $search): ?>
                <a href="index.php" class="btn" style="height:38px;">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-title" style="display:flex;justify-content:space-between;align-items:center;">
        <span>Daftar Unit Inventaris Fisik (<?= count($rows) ?> unit)</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:40px;">No</th>
                    <th>Kode Unit</th>
                    <th>Alat Master</th>
                    <th>No. Seri / RFID</th>
                    <th>Kondisi Fisik</th>
                    <th>Lokasi Rak</th>
                    <th>Status Unit</th>
                    <th style="text-align:right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="8"><div class="empty">Tidak ada unit peralatan yang sesuai dengan filter.</div></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <strong style="font-family:monospace;font-size:13px;color:#1e293b;"><?= e($r['kode_unit']) ?></strong>
                            </td>
                            <td>
                                <strong><?= e($r['nama_barang']) ?></strong>
                                <div style="font-size:11px;color:#64748b;"><?= e($r['nama_kategori'] ?? '-') ?> (Kode: <?= e($r['kode_barang']) ?>)</div>
                            </td>
                            <td>
                                <?php if ($r['serial_number']): ?>
                                    <div style="font-size:11px;font-family:monospace;color:#475569;">SN: <?= e($r['serial_number']) ?></div>
                                <?php endif; ?>
                                <?php if ($r['rfid_uid']): ?>
                                    <span style="display:inline-flex;align-items:center;font-size:10px;background:#f1f5f9;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1;font-family:monospace;">
                                        RFID: <?= e($r['rfid_uid']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:11px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $r['kondisi'] === 'Baik' ? 'aktif' : ($r['kondisi'] === 'Rusak Ringan' ? 'MENUNGGU' : 'DITOLAK') ?>">
                                    <?= e($r['kondisi']) ?>
                                </span>
                            </td>
                            <td><?= e($r['lokasi'] ?: '-') ?></td>
                            <td><?= unit_status_badge($r['status']) ?></td>
                            <td style="text-align:right;">
                                <div class="actions" style="justify-content:flex-end;">
                                    <a class="btn btn-edit" href="edit.php?id=<?= $r['id'] ?>" style="font-size:12px;padding:6px 10px;">Edit</a>
                                    <?php if ($r['status'] !== 'DIPINJAM'): ?>
                                        <form method="POST" action="index.php" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus unit <?= e($r['kode_unit']) ?>?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <button type="submit" class="btn btn-danger" style="font-size:12px;padding:6px 10px;">Hapus</button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn" style="font-size:12px;padding:6px 10px;opacity:0.5;cursor:not-allowed;" title="Tidak dapat dihapus saat sedang dipinjam">Dipinjam</button>
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

<?php page_end(); ?>
