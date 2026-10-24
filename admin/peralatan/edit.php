<?php

/**
 * FILE       : admin/peralatan/edit.php
 * MODUL      : Master Inventaris Peralatan
 * FUNGSI     : Memperbarui data spesifikasi master alat laboratorium, foto, kategori, dan sinkronisasi stok unit fisik
 * AKSES      : Administrator / Petugas Laboratorium
 *
 * ALUR:
 * 1. Mengambil data master alat eksisting berdasarkan ID parameter GET.
 * 2. Memproses input POST: nama, kode, kategori teks/datalist, lokasi, dan foto cropped base64.
 * 3. Menghitung penyesuaian delta unit fisik jika ada perubahan total stok atau stok tersedia.
 * 4. Menyinkronkan perubahan ke tabel `peralatan` dan `unit_peralatan` dalam transaksi database.
 * 5. Mencatat riwayat perubahan ke tabel `log_aktivitas`.
 */

require_once __DIR__ . '/../includes/admin.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$q = $pdo->prepare('SELECT p.*, k.nama_kategori FROM peralatan p LEFT JOIN kategori k ON k.id = p.kategori_id WHERE p.id = ?');
$q->execute([$id]);
$row = $q->fetch();
if (!$row) {
    redirect('index.php');
}

$cats = $pdo->query('SELECT id, nama_kategori FROM kategori ORDER BY nama_kategori')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $kode = trim((string)($_POST['kode_barang'] ?? ''));
    $nama = trim((string)($_POST['nama_barang'] ?? ''));
    $namaKategori = trim((string)($_POST['kategori'] ?? ''));
    $kat = resolve_kategori_id($namaKategori);
    $jumlah = max(0, (int)($_POST['jumlah'] ?? 0));
    $tersedia = max(0, (int)($_POST['jumlah_tersedia'] ?? 0));
    $kondisi = (string)($_POST['kondisi'] ?? 'Baik');
    $lokasi = trim((string)($_POST['lokasi'] ?? ''));
    $desk = trim((string)($_POST['deskripsi'] ?? ''));
    $status = (string)($_POST['status'] ?? 'aktif');
    $hapusFoto = !empty($_POST['hapus_foto']);
    $foto = $row['foto'];

    if ($kode === '' || $nama === '') {
        flash('error', 'Kode dan nama barang wajib diisi.');
    } elseif ($tersedia > $jumlah) {
        flash('error', 'Jumlah tersedia tidak boleh lebih besar dari jumlah total.');
    } else {
        // Handle upload foto baru (Mendukung Cropped Base64 atau File Multipart terverifikasi)
        $croppedBase64 = trim((string)($_POST['foto_cropped'] ?? ''));
        if (!empty($croppedBase64) && str_starts_with($croppedBase64, 'data:image/')) {
            $savedPath = secure_save_base64_image($croppedBase64, 'peralatan', 'alat');
            if ($savedPath) {
                if (!empty($row['foto']) && str_starts_with($row['foto'], 'assets/uploads/peralatan/')) {
                    $oldPath = __DIR__ . '/../../' . $row['foto'];
                    if (file_exists($oldPath)) @unlink($oldPath);
                }
                $foto = $savedPath;
            } else {
                flash('error', 'Format gambar hasil crop tidak valid.');
            }
        } elseif (isset($_FILES['foto_file']) && $_FILES['foto_file']['error'] === UPLOAD_ERR_OK) {
            try {
                $savedPath = secure_upload_image($_FILES['foto_file'], 'peralatan', 'alat');
                if (!empty($row['foto']) && str_starts_with($row['foto'], 'assets/uploads/peralatan/')) {
                    $oldPath = __DIR__ . '/../../' . $row['foto'];
                    if (file_exists($oldPath)) @unlink($oldPath);
                }
                $foto = $savedPath;
            } catch (Throwable $uploadEx) {
                flash('error', $uploadEx->getMessage());
            }
        } elseif ($hapusFoto) {
            if (!empty($row['foto']) && str_starts_with($row['foto'], 'assets/uploads/')) {
                $oldPath = __DIR__ . '/../../' . $row['foto'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $foto = null;
        }

        if (empty($_SESSION['flash_msg'])) {
            try {
                $pdo->beginTransaction();

                // 1. Ambil statistik unit fisik saat ini
                $stU = $pdo->prepare("
                    SELECT 
                        COUNT(*) AS total_unit,
                        SUM(CASE WHEN status = 'TERSEDIA' THEN 1 ELSE 0 END) AS unit_tersedia,
                        SUM(CASE WHEN status = 'DIPINJAM' THEN 1 ELSE 0 END) AS unit_dipinjam,
                        SUM(CASE WHEN status = 'RUSAK' THEN 1 ELSE 0 END) AS unit_rusak,
                        SUM(CASE WHEN status = 'HILANG' THEN 1 ELSE 0 END) AS unit_hilang,
                        SUM(CASE WHEN status = 'MAINTENANCE' THEN 1 ELSE 0 END) AS unit_maintenance
                    FROM unit_peralatan
                    WHERE peralatan_id = ?
                ");
                $stU->execute([$id]);
                $curStats = $stU->fetch() ?: [];
                $currentTotal = (int)($curStats['total_unit'] ?? 0);
                $currentDipinjam = (int)($curStats['unit_dipinjam'] ?? 0);

                // Validasi: Total stok tidak boleh kurang dari unit yang sedang dipinjam
                if ($jumlah < $currentDipinjam) {
                    throw new Exception("Total stok tidak dapat dikurangi menjadi $jumlah unit karena terdapat $currentDipinjam unit yang sedang dipinjam.");
                }

                $baseCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $kode));
                if ($baseCode === '') $baseCode = 'EQ' . $id;

                // 2. Sinkronkan jumlah unit fisik di tabel unit_peralatan
                if ($currentTotal === 0 && $jumlah > 0) {
                    // Jika sebelumnya belum ada unit fisik terdaftar, buat unit baru
                    $stIns = $pdo->prepare("
                        INSERT INTO unit_peralatan (peralatan_id, kode_unit, kondisi, lokasi, status, catatan)
                        VALUES (?, ?, ?, ?, ?, 'Inisialisasi unit dari penyesuaian master peralatan')
                    ");
                    for ($i = 1; $i <= $jumlah; $i++) {
                        $unitCode = sprintf("%s-%03d", $baseCode, $i);
                        $unitStatus = ($i <= $tersedia) ? (($kondisi === 'Rusak Berat') ? 'RUSAK' : 'TERSEDIA') : 'RUSAK';
                        $stIns->execute([$id, $unitCode, $kondisi, $lokasi !== '' ? $lokasi : null, $unitStatus]);
                    }
                } elseif ($jumlah > $currentTotal) {
                    // Total stok bertambah -> Tambahkan unit fisik baru
                    $stCodes = $pdo->prepare("SELECT kode_unit FROM unit_peralatan WHERE peralatan_id = ?");
                    $stCodes->execute([$id]);
                    $existingCodes = $stCodes->fetchAll(PDO::FETCH_COLUMN);
                    $maxSuffix = $currentTotal;
                    foreach ($existingCodes as $c) {
                        if (preg_match('/-(\d+)$/', (string)$c, $m)) {
                            $val = (int)$m[1];
                            if ($val > $maxSuffix) $maxSuffix = $val;
                        }
                    }

                    $unitsToAdd = $jumlah - $currentTotal;
                    $stIns = $pdo->prepare("
                        INSERT INTO unit_peralatan (peralatan_id, kode_unit, kondisi, lokasi, status, catatan)
                        VALUES (?, ?, ?, ?, 'TERSEDIA', 'Penambahan unit fisik dari edit master peralatan')
                    ");
                    for ($i = 1; $i <= $unitsToAdd; $i++) {
                        $maxSuffix++;
                        $unitCode = sprintf("%s-%03d", $baseCode, $maxSuffix);
                        
                        $chk = $pdo->prepare("SELECT id FROM unit_peralatan WHERE kode_unit = ?");
                        $chk->execute([$unitCode]);
                        while ($chk->fetch()) {
                            $maxSuffix++;
                            $unitCode = sprintf("%s-%03d", $baseCode, $maxSuffix);
                            $chk->execute([$unitCode]);
                        }
                        $stIns->execute([$id, $unitCode, $kondisi, $lokasi !== '' ? $lokasi : null]);
                    }
                } elseif ($jumlah < $currentTotal) {
                    // Total stok berkurang -> Hapus unit fisik yang TIDAK sedang dipinjam
                    $unitsToRemove = $currentTotal - $jumlah;
                    $stDel = $pdo->prepare("
                        SELECT id FROM unit_peralatan 
                        WHERE peralatan_id = ? AND status != 'DIPINJAM'
                        ORDER BY CASE WHEN status = 'TERSEDIA' THEN 1 WHEN status = 'RUSAK' THEN 2 ELSE 3 END, id DESC
                        LIMIT " . (int)$unitsToRemove
                    );
                    $stDel->execute([$id]);
                    $delIds = $stDel->fetchAll(PDO::FETCH_COLUMN);

                    if (count($delIds) < $unitsToRemove) {
                        throw new Exception("Hanya dapat mengurangi " . count($delIds) . " unit karena unit lainnya sedang berstatus DIPINJAM.");
                    }

                    $delStmt = $pdo->prepare("DELETE FROM unit_peralatan WHERE id = ?");
                    foreach ($delIds as $delId) {
                        $delStmt->execute([$delId]);
                    }
                }

                // 3. Sesuaikan unit ketersediaan jika user mengedit jumlah_tersedia
                $stAvail = $pdo->prepare("SELECT COUNT(*) FROM unit_peralatan WHERE peralatan_id = ? AND status = 'TERSEDIA' AND kondisi != 'Rusak Berat'");
                $stAvail->execute([$id]);
                $nowAvailable = (int)$stAvail->fetchColumn();

                if ($tersedia < $nowAvailable) {
                    $diff = $nowAvailable - $tersedia;
                    $stSetUnavail = $pdo->prepare("
                        UPDATE unit_peralatan 
                        SET status = 'RUSAK', kondisi = 'Rusak Ringan', catatan = CONCAT(COALESCE(catatan, ''), ' | Ditetapkan nonaktif dari edit master')
                        WHERE peralatan_id = ? AND status = 'TERSEDIA'
                        LIMIT " . (int)$diff
                    );
                    $stSetUnavail->execute([$id]);
                } elseif ($tersedia > $nowAvailable) {
                    $diff = $tersedia - $nowAvailable;
                    $stSetAvail = $pdo->prepare("
                        UPDATE unit_peralatan 
                        SET status = 'TERSEDIA', kondisi = 'Baik', catatan = CONCAT(COALESCE(catatan, ''), ' | Dipulihkan ketersediaannya dari edit master')
                        WHERE peralatan_id = ? AND status IN ('RUSAK', 'MAINTENANCE')
                        LIMIT " . (int)$diff
                    );
                    $stSetAvail->execute([$id]);
                }

                // 4. Update data master peralatan
                $q = $pdo->prepare('
                    UPDATE peralatan 
                    SET kode_barang = ?, nama_barang = ?, kategori_id = ?, jumlah = ?, 
                        jumlah_tersedia = ?, kondisi = ?, lokasi = ?, deskripsi = ?, 
                        foto = ?, status = ? 
                    WHERE id = ?
                ');
                $q->execute([
                    $kode,
                    $nama,
                    $kat,
                    $jumlah,
                    $tersedia,
                    $kondisi,
                    $lokasi,
                    $desk !== '' ? $desk : null,
                    $foto,
                    $status,
                    $id
                ]);

                // 5. Sinkronisasi final dengan data unit_peralatan
                sync_peralatan_stock($id);

                $pdo->commit();

                audit_log('UPDATE', 'PERALATAN', $id, "Memperbarui master peralatan $nama ($kode), total stok: $jumlah, tersedia: $tersedia");

                flash('success', "Peralatan $nama berhasil diperbarui (Total Stok: $jumlah unit, Tersedia: $tersedia unit).");
                redirect('index.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('error', 'Gagal memperbarui peralatan: ' . $e->getMessage());
            }
        }
    }
}

// Ambil statistik unit fisik saat ini untuk ditampilkan ke admin
$stUStats = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_unit,
        SUM(CASE WHEN status = 'TERSEDIA' THEN 1 ELSE 0 END) AS unit_tersedia,
        SUM(CASE WHEN status = 'DIPINJAM' THEN 1 ELSE 0 END) AS unit_dipinjam,
        SUM(CASE WHEN status = 'RUSAK' THEN 1 ELSE 0 END) AS unit_rusak,
        SUM(CASE WHEN status = 'HILANG' THEN 1 ELSE 0 END) AS unit_hilang,
        SUM(CASE WHEN status = 'MAINTENANCE' THEN 1 ELSE 0 END) AS unit_maintenance
    FROM unit_peralatan
    WHERE peralatan_id = ?
");
$stUStats->execute([$id]);
$uStats = $stUStats->fetch() ?: [
    'total_unit' => 0,
    'unit_tersedia' => 0,
    'unit_dipinjam' => 0,
    'unit_rusak' => 0,
    'unit_hilang' => 0,
    'unit_maintenance' => 0
];

page_start('Edit Peralatan', 'Perbarui data alat laboratorium', 'peralatan'); 
?>

<div class="head">
    <div>
        <h2>Edit Peralatan</h2>
        <p>Perbarui informasi detail, stok, dan foto dokumentasi alat.</p>
    </div>
</div>

<div class="form-panel">
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="grid">
            <div style="grid-column: 1 / -1; padding: 12px 16px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; font-size: 13px; color: #0369a1; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                <div>
                    <strong style="color:#0284c7;"><i class="fas fa-microchip"></i> Unit Fisik Terdaftar:</strong>
                    Saat ini ada <strong><?= (int)$uStats['total_unit'] ?> unit</strong>
                    (<?= (int)$uStats['unit_tersedia'] ?> Tersedia, 
                     <?= (int)$uStats['unit_dipinjam'] ?> Dipinjam, 
                     <?= (int)$uStats['unit_rusak'] ?> Rusak, 
                     <?= (int)$uStats['unit_maintenance'] ?> Maintenance)
                </div>
                <a href="../unit/index.php?peralatan_id=<?= $id ?>" style="color: #0284c7; font-weight: 600; text-decoration: underline; font-size: 12px;">
                    Kelola Nomor Seri & RFID Per Unit &rarr;
                </a>
            </div>

            <div class="field">
                <label>Kode Barang *</label>
                <input name="kode_barang" required value="<?= e((string)($_POST['kode_barang'] ?? $row['kode_barang'])) ?>">
            </div>

            <div class="field">
                <label>Nama Barang *</label>
                <input name="nama_barang" required value="<?= e((string)($_POST['nama_barang'] ?? $row['nama_barang'])) ?>">
            </div>

            <div class="field">
                <label>Kategori Peralatan</label>
                <input name="kategori" maxlength="100" list="kategoriList" placeholder="Ketik atau pilih kategori..." value="<?= e((string)($_POST['kategori'] ?? $row['nama_kategori'] ?? '')) ?>">
                <datalist id="kategoriList">
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= e($c['nama_kategori']) ?>">
                    <?php endforeach; ?>
                </datalist>
                <small style="color:#64748b;font-size:11px;display:block;margin-top:4px;">
                    Kategori tersimpan langsung bersama master alat tanpa perlu membuka menu terpisah.
                </small>
            </div>

            <div class="field">
                <label>Total Stok *</label>
                <input type="number" min="0" name="jumlah" required value="<?= e((string)($_POST['jumlah'] ?? $row['jumlah'])) ?>">
                <small style="color:#64748b;font-size:11px;display:block;margin-top:4px;">
                    Mengubah total stok akan otomatis menambah/menyesuaikan unit fisik di database.
                </small>
            </div>

            <div class="field">
                <label>Stok Tersedia *</label>
                <input type="number" min="0" name="jumlah_tersedia" required value="<?= e((string)($_POST['jumlah_tersedia'] ?? $row['jumlah_tersedia'])) ?>">
                <small style="color:#64748b;font-size:11px;display:block;margin-top:4px;">
                    Unit siap dipinjam saat ini. Otomatis disinkronkan dengan unit fisik.
                </small>
            </div>

            <div class="field">
                <label>Kondisi</label>
                <select name="kondisi">
                    <?php foreach (['Baik', 'Rusak Ringan', 'Rusak Berat'] as $x): ?>
                        <option value="<?= $x ?>" <?= ((string)($_POST['kondisi'] ?? $row['kondisi']) === $x ? 'selected' : '') ?>>
                            <?= $x ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Lokasi Rak / Lab</label>
                <input name="lokasi" value="<?= e((string)($_POST['lokasi'] ?? $row['lokasi'] ?? '')) ?>">
            </div>

            <div class="field">
                <label>Status Alat</label>
                <select name="status">
                    <option value="aktif" <?= ((string)($_POST['status'] ?? $row['status']) === 'aktif' ? 'selected' : '') ?>>Aktif (Dapat Dipinjam)</option>
                    <option value="nonaktif" <?= ((string)($_POST['status'] ?? $row['status']) === 'nonaktif' ? 'selected' : '') ?>>Nonaktif (Tidak Ditampilkan)</option>
                </select>
            </div>

            <div class="field full">
                <label>Deskripsi & Spesifikasi</label>
                <textarea name="deskripsi" rows="3"><?= e((string)($_POST['deskripsi'] ?? $row['deskripsi'] ?? '')) ?></textarea>
            </div>

            <div class="field full">
                <label>Foto Peralatan (JPG/PNG/WEBP, Maks. 2MB)</label>
                <div style="display:flex;gap:16px;align-items:flex-start;padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                    <div id="fotoPreviewContainer" style="width:84px;height:84px;border-radius:8px;border:1px dashed #cbd5e1;background:#ffffff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
                        <?php if (!empty($row['foto']) && file_exists(__DIR__ . '/../../' . $row['foto'])): ?>
                            <img id="fotoPreviewImg" src="../../<?= e($row['foto']) ?>" alt="Foto Peralatan" class="sipala-zoomable-img"
                                 data-lightbox-src="../../<?= e($row['foto']) ?>"
                                 data-lightbox-title="<?= e($row['nama_barang']) ?>"
                                 data-lightbox-sub="Kode: <?= e($row['kode_barang']) ?> &bull; Kondisi: <?= e($row['kondisi']) ?>"
                                 data-lightbox-badge="Foto Tersimpan"
                                 title="Klik untuk melihat foto ukuran penuh"
                                 style="width:100%;height:100%;object-fit:cover;cursor:zoom-in;">
                            <span id="fotoPlaceholderText" style="font-size:11px;color:#94a3b8;display:none;text-align:center;">Pratinjau</span>
                        <?php else: ?>
                            <img id="fotoPreviewImg" src="" alt="Pratinjau Foto" class="sipala-zoomable-img" style="width:100%;height:100%;object-fit:cover;display:none;">
                            <span id="fotoPlaceholderText" style="font-size:11px;color:#94a3b8;text-align:center;">Tanpa Foto</span>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;">
                                                <input type="file" name="foto_file" id="foto_file" accept=".jpg,.jpeg,.png,.webp" style="padding:7px;background:#ffffff;width:100%;">
                        <input type="hidden" name="foto_cropped" id="foto_cropped" value="">
                        <div style="font-size:11px;color:#0284c7;margin-top:4px;">
                            &#10024; <em>Pilih file untuk membuka pemotong & penyesuai foto (rasio 4:3 proporsional).</em>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
                            <small style="color:#64748b;">Pilih file baru jika ingin mengganti foto yang ada.</small>
                            <?php if (!empty($row['foto'])): ?>
                                <label style="font-size:12px;color:#dc2626;display:inline-flex;align-items:center;gap:4px;cursor:pointer;">
                                    <input type="checkbox" name="hapus_foto" value="1"> Hapus foto saat ini
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <a class="btn" href="index.php">Batal</a>
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.SipalaCropper) {
        SipalaCropper.setup({
            inputId: 'foto_file',
            previewId: 'fotoPreviewImg',
            placeholderId: 'fotoPlaceholderText',
            hiddenInputId: 'foto_cropped',
            aspectRatio: 4 / 3, // 4:3 proporsional alat
            shape: 'square',
            title: 'Potong & Sesuaikan Foto Peralatan Laboratorium'
        });
    }
});

function previewUpload(input) {
    const previewImg = document.getElementById('fotoPreviewImg');
    const placeholderText = document.getElementById('fotoPlaceholderText');
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (file.size > 2 * 1024 * 1024) {
            alert('Ukuran file maksimal 2 MB.');
            input.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewImg.style.display = 'block';
            if (placeholderText) placeholderText.style.display = 'none';
        };
        reader.readAsDataURL(file);
    }
}
</script>

<?php page_end(); ?>