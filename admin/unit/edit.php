<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

$unitId = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("
    SELECT u.*, p.nama_barang, p.kode_barang 
    FROM unit_peralatan u
    JOIN peralatan p ON p.id = u.peralatan_id
    WHERE u.id = ?
");
$st->execute([$unitId]);
$unit = $st->fetch();

if (!$unit) {
    flash('error', 'Unit peralatan tidak ditemukan.');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $kodeUnit = trim((string)($_POST['kode_unit'] ?? ''));
    $serial = trim((string)($_POST['serial_number'] ?? ''));
    $rfid = trim((string)($_POST['rfid_uid'] ?? ''));
    $kondisi = (string)($_POST['kondisi'] ?? 'Baik');
    $lokasi = trim((string)($_POST['lokasi'] ?? ''));
    $status = (string)($_POST['status'] ?? 'TERSEDIA');
    $catatan = trim((string)($_POST['catatan'] ?? ''));

    if ($kodeUnit === '') {
        flash('error', 'Kode inventaris unit tidak boleh kosong.');
    } else {
        try {
            // Cek keunikan kode_unit selain unit ini
            $chk = $pdo->prepare("SELECT id FROM unit_peralatan WHERE kode_unit = ? AND id != ?");
            $chk->execute([$kodeUnit, $unitId]);
            if ($chk->fetch()) {
                throw new RuntimeException("Kode unit '$kodeUnit' sudah digunakan oleh unit lain.");
            }

            // Cek keunikan RFID jika diisi
            if ($rfid !== '') {
                $chkR = $pdo->prepare("SELECT id FROM unit_peralatan WHERE rfid_uid = ? AND id != ?");
                $chkR->execute([$rfid, $unitId]);
                if ($chkR->fetch()) {
                    throw new RuntimeException("UID RFID '$rfid' sudah terdaftar pada unit lain.");
                }
            }

            // Jika status saat ini DIPINJAM dan diubah ke selain DIPINJAM secara manual, berikan proteksi
            if ($unit['status'] === 'DIPINJAM' && $status !== 'DIPINJAM') {
                // Biarkan hanya jika dikonfirmasi secara sadar
            }

            $upd = $pdo->prepare("
                UPDATE unit_peralatan 
                SET kode_unit = ?, serial_number = ?, rfid_uid = ?, kondisi = ?, lokasi = ?, status = ?, catatan = ?
                WHERE id = ?
            ");
            $upd->execute([
                $kodeUnit,
                $serial !== '' ? $serial : null,
                $rfid !== '' ? $rfid : null,
                $kondisi,
                $lokasi !== '' ? $lokasi : null,
                $status,
                $catatan !== '' ? $catatan : null,
                $unitId
            ]);

            // Sinkronisasi RFID di rfid_cards jika ada
            if ($rfid !== '') {
                $stCard = $pdo->prepare("
                    INSERT INTO rfid_cards (unit_id, uid, tipe, status, catatan)
                    VALUES (?, ?, 'alat', 'aktif', ?)
                    ON DUPLICATE KEY UPDATE unit_id = VALUES(unit_id)
                ");
                $stCard->execute([$unitId, $rfid, 'Tag unit alat: ' . $kodeUnit]);
            }

            sync_peralatan_stock((int)$unit['peralatan_id']);
            audit_log('UPDATE', 'UNIT_PERALATAN', $unitId, "Mengubah data unit {$unit['kode_unit']} (status: $status, kondisi: $kondisi)");

            flash('success', "Data unit peralatan $kodeUnit berhasil diperbarui.");
            redirect('index.php?peralatan_id=' . $unit['peralatan_id']);
        } catch (Throwable $e) {
            flash('error', 'Gagal memperbarui unit: ' . $e->getMessage());
        }
    }
}

page_start('Edit Unit Peralatan', 'Perbarui data unit fisik inventaris', 'unit');
?>

<div class="head">
    <div>
        <h2>Edit Unit: <?= e($unit['kode_unit']) ?></h2>
        <p>Master: <strong><?= e($unit['nama_barang']) ?></strong> (<?= e($unit['kode_barang']) ?>)</p>
    </div>
    <a class="btn" href="index.php?peralatan_id=<?= $unit['peralatan_id'] ?>">← Kembali ke Unit</a>
</div>

<div class="form-panel">
    <form method="POST" action="edit.php?id=<?= $unitId ?>">
        <?= csrf_field() ?>

        <div class="grid">
            <div class="field">
                <label>Kode Inventaris Unit (Unik) *</label>
                <input type="text" name="kode_unit" required value="<?= e($unit['kode_unit']) ?>">
            </div>

            <div class="field">
                <label>Nomor Seri Pabrik (Serial Number)</label>
                <input type="text" name="serial_number" value="<?= e($unit['serial_number'] ?? '') ?>" placeholder="Nomor seri resmi pabrikan">
            </div>

            <div class="field">
                <label>Tag / UID RFID Alat</label>
                <input type="text" name="rfid_uid" value="<?= e($unit['rfid_uid'] ?? '') ?>" placeholder="Tap RFID jika ada">
            </div>

            <div class="field">
                <label>Kondisi Fisik Unit *</label>
                <select name="kondisi" required>
                    <option value="Baik" <?= $unit['kondisi'] === 'Baik' ? 'selected' : '' ?>>Baik</option>
                    <option value="Rusak Ringan" <?= $unit['kondisi'] === 'Rusak Ringan' ? 'selected' : '' ?>>Rusak Ringan</option>
                    <option value="Rusak Berat" <?= $unit['kondisi'] === 'Rusak Berat' ? 'selected' : '' ?>>Rusak Berat</option>
                </select>
            </div>

            <div class="field">
                <label>Lokasi Rak / Lemari</label>
                <input type="text" name="lokasi" value="<?= e($unit['lokasi'] ?? '') ?>" placeholder="Lokasi penyimpanan">
            </div>

            <div class="field">
                <label>Status Ketersediaan *</label>
                <select name="status" required>
                    <option value="TERSEDIA" <?= $unit['status'] === 'TERSEDIA' ? 'selected' : '' ?>>TERSEDIA (Siap Dipinjam)</option>
                    <option value="DIPINJAM" <?= $unit['status'] === 'DIPINJAM' ? 'selected' : '' ?>>DIPINJAM</option>
                    <option value="RUSAK" <?= $unit['status'] === 'RUSAK' ? 'selected' : '' ?>>RUSAK</option>
                    <option value="HILANG" <?= $unit['status'] === 'HILANG' ? 'selected' : '' ?>>HILANG</option>
                    <option value="MAINTENANCE" <?= $unit['status'] === 'MAINTENANCE' ? 'selected' : '' ?>>MAINTENANCE (Perbaikan)</option>
                </select>
            </div>

            <div class="field full">
                <label>Catatan Unit</label>
                <textarea name="catatan" placeholder="Catatan kondisi atau riwayat perbaikan"><?= e($unit['catatan'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="form-actions">
            <a class="btn" href="index.php?peralatan_id=<?= $unit['peralatan_id'] ?>">Batal</a>
            <button type="submit" class="btn btn-primary">Simpan Perubahan Unit</button>
        </div>
    </form>
</div>

<?php page_end(); ?>
