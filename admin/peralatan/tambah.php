<?php

/**
 * FILE       : admin/peralatan/tambah.php
 * MODUL      : Master Inventaris Peralatan
 * FUNGSI     : Menambahkan jenis/master alat baru ke laboratorium beserta inisialisasi unit fisik perdananya
 * AKSES      : Administrator / Petugas Laboratorium
 *
 * FITUR GENERASI KODE OTOMATIS:
 * - Menyediakan pembuatan kode barang unik berurutan secara otomatis (ALT-001, IOT-001, NET-001, dsb.)
 * - Menyesuaikan prefix berdasarkan pilihan kategori secara interaktif.
 * - Tetap memberikan fleksibilitas bagi petugas untuk mengetik kode inventaris kustom jika sekolah memiliki standar khusus.
 */

require_once __DIR__ . '/../includes/admin.php';

// Endpoint AJAX untuk mendapatkan kode barang otomatis berikutnya
if (isset($_GET['action']) && $_GET['action'] === 'get_code') {
    header('Content-Type: application/json; charset=utf-8');
    $kategori = trim((string)($_GET['kategori'] ?? ''));
    $prefix = trim((string)($_GET['prefix'] ?? ''));
    if ($prefix === '') {
        $prefix = get_prefix_from_kategori($kategori);
    }
    $nextCode = get_next_kode_barang($pdo, $prefix);
    echo json_encode(['success' => true, 'code' => $nextCode, 'prefix' => $prefix]);
    exit;
}

$cats = $pdo->query('SELECT id, nama_kategori FROM kategori ORDER BY nama_kategori')->fetchAll();
$defaultKode = get_next_kode_barang($pdo, 'ALT');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $kode = trim((string)($_POST['kode_barang'] ?? ''));
    $nama = trim((string)($_POST['nama_barang'] ?? ''));
    $namaKategori = trim((string)($_POST['kategori'] ?? ''));
    $kat = resolve_kategori_id($namaKategori);
    $jumlah = max(1, (int)($_POST['jumlah'] ?? 1));
    $kondisi = (string)($_POST['kondisi'] ?? 'Baik');
    $lokasi = trim((string)($_POST['lokasi'] ?? ''));
    $desk = trim((string)($_POST['deskripsi'] ?? ''));
    $status = (string)($_POST['status'] ?? 'aktif');
    $foto = null;

    // Jika kode barang dikosongkan pengguna, buatkan otomatis di backend
    if ($kode === '') {
        $prefix = get_prefix_from_kategori($namaKategori);
        $kode = get_next_kode_barang($pdo, $prefix);
    }

    if ($nama === '') {
        flash('error', 'Nama barang wajib diisi.');
    } else {
        // Cek duplikasi kode_barang
        $chkDuplicate = $pdo->prepare('SELECT COUNT(*) FROM peralatan WHERE kode_barang = ?');
        $chkDuplicate->execute([$kode]);
        if ((int)$chkDuplicate->fetchColumn() > 0) {
            flash('error', "Kode barang '$kode' sudah terdaftar. Silakan gunakan kode lain atau klik Generate.");
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
                        INSERT INTO unit_peralatan (peralatan_id, kode_unit, kondisi, lokasi, status, status_label, catatan)
                        VALUES (?, ?, ?, ?, ?, 'BELUM_DILABELI', 'Unit inisialisasi awal saat registrasi alat master')
                    ");
                    $stUpdBarcode = $pdo->prepare("UPDATE unit_peralatan SET barcode = ? WHERE id = ?");

                    for ($i = 1; $i <= $jumlah; $i++) {
                        $unitCode = sprintf("%s-%03d", $baseCode, $i);
                        $unitStatus = ($kondisi === 'Rusak Berat') ? 'RUSAK' : 'TERSEDIA';
                        $stUnit->execute([$newAlatId, $unitCode, $kondisi, $lokasi !== '' ? $lokasi : null, $unitStatus]);
                        $newUnitId = (int)$pdo->lastInsertId();
                        $autoBarcode = sprintf("SIP-ALT-%06d", $newUnitId);
                        $stUpdBarcode->execute([$autoBarcode, $newUnitId]);
                    }

                    $pdo->commit();
                    sync_peralatan_stock($newAlatId);
                    audit_log('CREATE', 'PERALATAN', $newAlatId, "Menambahkan master peralatan $nama ($kode) dan $jumlah unit fisik ber-barcode");

                    flash('success', "Master peralatan '$nama' ($kode) dan $jumlah unit fisik berhasil ditambahkan!");
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
}

page_start('Tambah Peralatan', 'Tambah alat laboratorium baru', 'peralatan'); 
?>

<div class="head">
    <div>
        <h2>Tambah Peralatan</h2>
        <p>Masukkan data spesifikasi alat, lokasi rak, dan stok awal. Kode barang dibuat otomatis secara berurutan.</p>
    </div>
</div>

<div class="form-panel">
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="grid">
            <div class="field">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <label style="margin:0;font-weight:700;">Kode Barang / Inventaris *</label>
                    <span id="badgeKodeAuto" style="font-size:11px;color:#0284c7;background:#e0f2fe;padding:2px 8px;border-radius:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                        <span>✨ Otomatis Dibuat</span>
                    </span>
                </div>
                <div style="display:flex;gap:8px;">
                    <input name="kode_barang" id="kode_barang" maxlength="50" required 
                           placeholder="Contoh: ALT-001" 
                           value="<?= e((string)($_POST['kode_barang'] ?? $defaultKode)) ?>"
                           style="font-family:'JetBrains Mono',monospace;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;">
                    <button type="button" class="btn" id="btnRefreshKode" title="Buat ulang kode unik otomatis"
                            style="padding:0 12px;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;cursor:pointer;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
                        </svg>
                        <span>Generate</span>
                    </button>
                </div>
                <small style="color:#64748b;font-size:11.5px;display:block;margin-top:4px;">
                    Kode terisi otomatis berurutan. Kode akan otomatis menyesuaikan bila Anda memilih kategori, atau Anda dapat mengetik kode kustom sendiri.
                </small>
            </div>

            <div class="field">
                <label>Nama Barang *</label>
                <input name="nama_barang" maxlength="150" required placeholder="Contoh: Mikroskop Binokuler X-400" value="<?= e((string)($_POST['nama_barang'] ?? '')) ?>">
            </div>

            <div class="field">
                <label>Kategori Peralatan</label>
                <input name="kategori" id="kategoriInput" maxlength="100" list="kategoriList" placeholder="Ketik atau pilih kategori (contoh: IoT, Jaringan, Hardware...)" value="<?= e((string)($_POST['kategori'] ?? '')) ?>">
                <datalist id="kategoriList">
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= e($c['nama_kategori']) ?>">
                    <?php endforeach; ?>
                </datalist>
                <small style="color:#64748b;font-size:11px;display:block;margin-top:4px;">
                    Memilih kategori akan otomatis menyesuaikan prefix kode barang di atas.
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
                <input name="lokasi" maxlength="150" placeholder="Contoh: Rak Utama Lab RPL" value="<?= e((string)($_POST['lokasi'] ?? 'Rak Utama Lab RPL')) ?>">
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
                            ✨ <em>Pilih file untuk membuka pemotong & penyesuai foto (rasio 4:3 proporsional).</em>
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
    const inputKode = document.getElementById('kode_barang');
    const inputKat = document.getElementById('kategoriInput');
    const btnRefresh = document.getElementById('btnRefreshKode');
    const badge = document.getElementById('badgeKodeAuto');

    let userManuallyEditedKode = false;

    // Jika pengguna mengetik kode secara manual
    if (inputKode) {
        inputKode.addEventListener('input', function () {
            userManuallyEditedKode = (inputKode.value.trim() !== '');
            if (badge) {
                if (userManuallyEditedKode) {
                    badge.innerHTML = '<span>✏️ Kode Kustom</span>';
                    badge.style.color = '#047857';
                    badge.style.background = '#d1fae5';
                } else {
                    badge.innerHTML = '<span>✨ Otomatis Dibuat</span>';
                    badge.style.color = '#0284c7';
                    badge.style.background = '#e0f2fe';
                }
            }
        });
    }

    // Fungsi fetch kode baru dari server
    async function fetchNextCode(kategoriVal, force = false) {
        if (userManuallyEditedKode && !force) {
            return; // Jangan timpa jika pengguna sudah mengetik kode manual, kecuali ditekan tombol Generate
        }
        try {
            const res = await fetch('tambah.php?action=get_code&kategori=' + encodeURIComponent(kategoriVal || ''));
            const data = await res.json();
            if (data && data.success && data.code) {
                inputKode.value = data.code;
                userManuallyEditedKode = false;
                if (badge) {
                    badge.innerHTML = '<span>✨ Otomatis Dibuat</span>';
                    badge.style.color = '#0284c7';
                    badge.style.background = '#e0f2fe';
                }
                // Animasi flash lembut pada input
                inputKode.style.transition = 'background-color 0.3s ease';
                inputKode.style.backgroundColor = '#ecfeff';
                setTimeout(() => {
                    inputKode.style.backgroundColor = '';
                }, 500);
            }
        } catch (e) {
            console.warn('Gagal generate kode otomatis:', e);
        }
    }

    // Tombol Generate Ulang
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function () {
            fetchNextCode(inputKat ? inputKat.value : '', true);
        });
    }

    // Saat input kategori berubah atau dipilih dari datalist
    if (inputKat) {
        inputKat.addEventListener('change', function () {
            fetchNextCode(inputKat.value, false);
        });
        inputKat.addEventListener('input', function () {
            // Jika cocok dengan salah satu opsi datalist
            const opts = Array.from(document.querySelectorAll('#kategoriList option')).map(o => o.value.toLowerCase());
            if (opts.includes(inputKat.value.trim().toLowerCase())) {
                fetchNextCode(inputKat.value, false);
            }
        });
    }

    // Image Cropper Setup
    if (window.SipalaCropper) {
        SipalaCropper.setup({
            inputId: 'foto_file',
            previewId: 'fotoPreviewImg',
            placeholderId: 'fotoPlaceholderText',
            hiddenInputId: 'foto_cropped',
            aspectRatio: 4 / 3,
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
