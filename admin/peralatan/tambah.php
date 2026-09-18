<?php

/**
 * FILE       : admin/peralatan/tambah.php
 * MODUL      : Master Inventaris Peralatan
 * FUNGSI     : Menambahkan jenis/master alat baru ke laboratorium beserta inisialisasi unit fisik perdananya
 * AKSES      : Administrator / Petugas Laboratorium
 *
 * ALUR DATA:
 * - Data Input : Formulir POST (kode barang, nama barang, kategori teks/datalist, jumlah unit perdana, foto cropped base64/file, deskripsi).
 * - Pengolahan : Kategori otomatis dipetakan via `resolve_kategori_id()`.
 * - Database   : Data master disimpan ke tabel `peralatan`, kemudian sistem secara otomatis membuat rekaman baris unit fisik individual ke `unit_peralatan`.
 * - Hubungan   : Tersinkronisasi dengan modul Unit Fisik dan Audit Log aktivitas.
 */

require_once __DIR__ . '/../includes/admin.php';

$cats = $pdo->query('SELECT id, nama_kategori FROM kategori ORDER BY nama_kategori')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $kode = trim((string)($_POST['kode_barang'] ?? ''));
    $nama = trim((string)($_POST['nama_barang'] ?? ''));
    $namaKategori = trim((string)($_POST['kategori'] ?? ''));
    $kat = resolve_kategori_id($namaKategori);
    $jumlah = max(0, (int)($_POST['jumlah'] ?? 0));
    $kondisi = (string)($_POST['kondisi'] ?? 'Baik');
    $lokasi = trim((string)($_POST['lokasi'] ?? ''));
    $desk = trim((string)($_POST['deskripsi'] ?? ''));
    $status = (string)($_POST['status'] ?? 'aktif');
    $foto = null;

    if ($kode === '' || $nama === '') {
        flash('error', 'Kode dan nama barang wajib diisi.');
    } else {
        // Handle file upload aman (Mendukung Cropped Base64 atau File Multipart terverifikasi)
        $croppedBase64 = trim((string)($_POST['foto_cropped'] ?? ''));
        if (!empty($croppedBase64) && str_starts_with($croppedBase64, 'data:image/')) {
            $savedPath = secure_save_base64_image($croppedBase64, 'peralatan', 'alat');
            if ($savedPath) {
                $foto = $savedPath;
            } else {
                flash('error', 'Format gambar hasil crop tidak valid.');
            }
        } elseif (isset($_FILES['foto_file']) && $_FILES['foto_file']['error'] === UPLOAD_ERR_OK) {
            try {
                $foto = secure_upload_image($_FILES['foto_file'], 'peralatan', 'alat');
            } catch (Throwable $uploadEx) {
                flash('error', $uploadEx->getMessage());
            }
        } elseif (!empty($_POST['foto_text'])) {
            $foto = trim((string)$_POST['foto_text']);
        }

        // Simpan jika tidak ada error flash yang aktif
        if (empty($_SESSION['flash_msg'])) {
            try {
                $pdo->beginTransaction();
                $q = $pdo->prepare('INSERT INTO peralatan(kode_barang, nama_barang, kategori_id, jumlah, jumlah_tersedia, kondisi, lokasi, deskripsi, foto, status) VALUES(?,?,?,?,?,?,?,?,?,?)');
                $q->execute([
                    $kode,
                    $nama,
                    $kat,
                    $jumlah,
                    $jumlah,
                    $kondisi,
                    $lokasi,
                    $desk !== '' ? $desk : null,
                    $foto,
                    $status
                ]);
                $newAlatId = (int)$pdo->lastInsertId();

                // Buat unit fisik otomatis di tabel unit_peralatan
                $baseCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $kode));
                if ($baseCode === '') $baseCode = 'EQ' . $newAlatId;

                $stUnit = $pdo->prepare("
                    INSERT INTO unit_peralatan (peralatan_id, kode_unit, kondisi, lokasi, status, catatan)
                    VALUES (?, ?, ?, ?, ?, 'Unit inisialisasi awal saat registrasi alat master')
                ");
                for ($i = 1; $i <= $jumlah; $i++) {
                    $unitCode = sprintf("%s-%03d", $baseCode, $i);
                    $unitStatus = ($kondisi === 'Rusak Berat') ? 'RUSAK' : 'TERSEDIA';
                    $stUnit->execute([$newAlatId, $unitCode, $kondisi, $lokasi !== '' ? $lokasi : null, $unitStatus]);
                }

                $pdo->commit();
                sync_peralatan_stock($newAlatId);
                audit_log('CREATE', 'PERALATAN', $newAlatId, "Menambahkan master peralatan $nama ($kode) dan $jumlah unit fisik");

                flash('success', 'Peralatan dan ' . $jumlah . ' unit fisik berhasil ditambahkan ke sistem.');
                redirect('index.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('error', 'Gagal menambah peralatan: ' . $e->getMessage());
            }
        }
    }
}

page_start('Tambah Peralatan', 'Tambah alat laboratorium baru', 'peralatan'); 
?>

<div class="head">
    <div>
        <h2>Tambah Peralatan</h2>
        <p>Masukkan data spesifikasi alat, lokasi rak, dan stok awal.</p>
    </div>
</div>

<div class="form-panel">
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="grid">
            <div class="field">
                <label>Kode Barang / Inventaris *</label>
                <input name="kode_barang" maxlength="50" required placeholder="Contoh: LAB-BIO-001" value="<?= e((string)($_POST['kode_barang'] ?? '')) ?>">
            </div>

            <div class="field">
                <label>Nama Barang *</label>
                <input name="nama_barang" maxlength="150" required placeholder="Contoh: Mikroskop Binokuler X-400" value="<?= e((string)($_POST['nama_barang'] ?? '')) ?>">
            </div>

            <div class="field">
                <label>Kategori Peralatan</label>
                <input name="kategori" maxlength="100" list="kategoriList" placeholder="Ketik atau pilih kategori (contoh: Optik, Elektronik, Laboratorium...)" value="<?= e((string)($_POST['kategori'] ?? '')) ?>">
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
                <label>Jumlah Stok Awal *</label>
                <input type="number" min="1" name="jumlah" required value="<?= e((string)($_POST['jumlah'] ?? 1)) ?>">
            </div>

            <div class="field">
                <label>Kondisi Alat</label>
                <select name="kondisi">
                    <option value="Baik" <?= ((string)($_POST['kondisi'] ?? '') === 'Baik' ? 'selected' : '') ?>>Baik</option>
                    <option value="Rusak Ringan" <?= ((string)($_POST['kondisi'] ?? '') === 'Rusak Ringan' ? 'selected' : '') ?>>Rusak Ringan</option>
                    <option value="Rusak Berat" <?= ((string)($_POST['kondisi'] ?? '') === 'Rusak Berat' ? 'selected' : '') ?>>Rusak Berat</option>
                </select>
            </div>

            <div class="field">
                <label>Lokasi Rak / Lemari</label>
                <input name="lokasi" maxlength="150" placeholder="Contoh: Lemari A - Rak 2" value="<?= e((string)($_POST['lokasi'] ?? '')) ?>">
            </div>

            <div class="field full">
                <label>Deskripsi & Spesifikasi</label>
                <textarea name="deskripsi" rows="3" placeholder="Keterangan fungsi, instruksi pemakaian, atau catatan khusus alat..."><?= e((string)($_POST['deskripsi'] ?? '')) ?></textarea>
            </div>

            <div class="field full">
                <label>Foto Peralatan (Opsional, JPG/PNG/WEBP, Maks. 2MB)</label>
                <div style="display:flex;gap:16px;align-items:center;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                    <div id="fotoPreviewContainer" style="width:76px;height:76px;border-radius:8px;border:1px dashed #cbd5e1;background:#ffffff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
                        <span id="fotoPlaceholderText" style="font-size:11px;color:#94a3b8;text-align:center;padding:4px;">Pratinjau Foto</span>
                        <img id="fotoPreviewImg" src="" alt="Pratinjau Foto" style="width:100%;height:100%;object-fit:cover;display:none;">
                    </div>
                    <div style="flex:1;">
                                                <input type="file" name="foto_file" id="foto_file" accept=".jpg,.jpeg,.png,.webp" style="padding:7px;background:#ffffff;width:100%;">
                        <input type="hidden" name="foto_cropped" id="foto_cropped" value="">
                        <div style="font-size:11px;color:#0284c7;margin-top:4px;">
                            &#10024; <em>Pilih file untuk membuka pemotong & penyesuai foto (rasio 4:3 proporsional).</em>
                        </div>
                        <small style="display:block;color:#64748b;margin-top:6px;">Pilih file foto alat laboratorium yang jelas agar mudah dikenali siswa saat mengajukan pinjaman.</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <a class="btn" href="index.php">Batal</a>
            <button type="submit" class="btn btn-primary">Simpan Peralatan</button>
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
            previewImg.style.display = 'none';
            placeholderText.style.display = 'block';
            return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewImg.style.display = 'block';
            placeholderText.style.display = 'none';
        };
        reader.readAsDataURL(file);
    } else {
        previewImg.style.display = 'none';
        placeholderText.style.display = 'block';
    }
}
</script>

<?php page_end(); ?>