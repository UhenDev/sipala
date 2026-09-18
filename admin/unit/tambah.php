<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

$equipmentList = $pdo->query("SELECT id, nama_barang, kode_barang FROM peralatan ORDER BY nama_barang ASC")->fetchAll();
$preSelectedId = (int)($_GET['peralatan_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $mode = (string)($_POST['mode'] ?? 'single'); // 'single' atau 'batch'
    $peralatanId = (int)($_POST['peralatan_id'] ?? 0);

    // Ambil data master peralatan
    $stM = $pdo->prepare("SELECT id, nama_barang, kode_barang, lokasi, kondisi FROM peralatan WHERE id = ?");
    $stM->execute([$peralatanId]);
    $master = $stM->fetch();

    if (!$master) {
        flash('error', 'Silakan pilih master peralatan yang valid.');
    } elseif ($mode === 'single') {
        $kodeUnit = trim((string)($_POST['kode_unit'] ?? ''));
        $serial = trim((string)($_POST['serial_number'] ?? ''));
        $rfid = trim((string)($_POST['rfid_uid'] ?? ''));
        $kondisi = (string)($_POST['kondisi'] ?? 'Baik');
        $lokasi = trim((string)($_POST['lokasi'] ?? ''));
        $status = (string)($_POST['status'] ?? 'TERSEDIA');
        $catatan = trim((string)($_POST['catatan'] ?? ''));

        if ($kodeUnit === '') {
            flash('error', 'Kode inventaris unit wajib diisi.');
        } else {
            try {
                // Cek keunikan kode_unit
                $chk = $pdo->prepare("SELECT id FROM unit_peralatan WHERE kode_unit = ?");
                $chk->execute([$kodeUnit]);
                if ($chk->fetch()) {
                    flash('error', "Kode unit '$kodeUnit' sudah digunakan.");
                } else {
                    // Cek RFID jika diisi
                    if ($rfid !== '') {
                        $chkR = $pdo->prepare("SELECT id FROM unit_peralatan WHERE rfid_uid = ?");
                        $chkR->execute([$rfid]);
                        if ($chkR->fetch()) {
                            throw new RuntimeException("UID RFID '$rfid' sudah terdaftar pada unit lain.");
                        }
                    }

                    $ins = $pdo->prepare("
                        INSERT INTO unit_peralatan (peralatan_id, kode_unit, serial_number, rfid_uid, kondisi, lokasi, status, catatan)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([
                        $peralatanId,
                        $kodeUnit,
                        $serial !== '' ? $serial : null,
                        $rfid !== '' ? $rfid : null,
                        $kondisi,
                        $lokasi !== '' ? $lokasi : null,
                        $status,
                        $catatan !== '' ? $catatan : null
                    ]);
                    $newUnitId = (int)$pdo->lastInsertId();

                    // Jika ada RFID, catat juga di rfid_cards
                    if ($rfid !== '') {
                        $stCard = $pdo->prepare("
                            INSERT INTO rfid_cards (unit_id, uid, tipe, status, catatan)
                            VALUES (?, ?, 'alat', 'aktif', ?)
                            ON DUPLICATE KEY UPDATE unit_id = VALUES(unit_id)
                        ");
                        $stCard->execute([$newUnitId, $rfid, 'Tag unit alat: ' . $kodeUnit]);
                    }

                    sync_peralatan_stock($peralatanId);
                    audit_log('CREATE', 'UNIT_PERALATAN', $newUnitId, "Menambahkan unit $kodeUnit untuk alat {$master['nama_barang']}");

                    flash('success', "Unit peralatan $kodeUnit berhasil ditambahkan.");
                    redirect('index.php?peralatan_id=' . $peralatanId);
                }
            } catch (Throwable $e) {
                flash('error', 'Gagal menyimpan unit: ' . $e->getMessage());
            }
        }
    } elseif ($mode === 'batch') {
        $prefix = trim((string)($_POST['batch_prefix'] ?? ''));
        $jumlah = max(1, min(100, (int)($_POST['batch_qty'] ?? 1)));
        $kondisi = (string)($_POST['kondisi'] ?? 'Baik');
        $lokasi = trim((string)($_POST['lokasi'] ?? ''));
        $startNum = max(1, (int)($_POST['start_number'] ?? 1));

        if ($prefix === '') {
            $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$master['kode_barang'])) . '-';
        }

        try {
            $pdo->beginTransaction();
            $stmtIns = $pdo->prepare("
                INSERT INTO unit_peralatan (peralatan_id, kode_unit, kondisi, lokasi, status, catatan)
                VALUES (?, ?, ?, ?, 'TERSEDIA', 'Dibuat secara massal oleh sistem')
            ");

            $createdCount = 0;
            for ($i = 0; $i < $jumlah; $i++) {
                $num = $startNum + $i;
                $kodeUnit = sprintf("%s%03d", $prefix, $num);

                // Cek duplicate
                $chk = $pdo->prepare("SELECT id FROM unit_peralatan WHERE kode_unit = ?");
                $chk->execute([$kodeUnit]);
                if ($chk->fetch()) {
                    continue; // Lewati jika sudah ada
                }

                $stmtIns->execute([
                    $peralatanId,
                    $kodeUnit,
                    $kondisi,
                    $lokasi !== '' ? $lokasi : null
                ]);
                $createdCount++;
            }

            $pdo->commit();
            sync_peralatan_stock($peralatanId);
            audit_log('CREATE_BATCH', 'UNIT_PERALATAN', $peralatanId, "Membuat $createdCount unit secara massal untuk {$master['nama_barang']}");

            flash('success', "Berhasil menambahkan $createdCount unit fisik untuk {$master['nama_barang']}.");
            redirect('index.php?peralatan_id=' . $peralatanId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Gagal memproses pembuatan massal: ' . $e->getMessage());
        }
    }
}

page_start('Tambah Unit Peralatan', 'Registrasi unit fisik baru ke inventaris', 'unit');
?>

<div class="head">
    <div>
        <h2>Tambah Unit Fisik Peralatan</h2>
        <p>Daftarkan satu unit individual atau buat beberapa unit sekaligus dengan penomoran otomatis.</p>
    </div>
    <a class="btn" href="index.php">← Kembali ke Daftar Unit</a>
</div>

<div class="form-panel">
    <!-- Pilihan Mode -->
    <div style="margin-bottom:20px;border-bottom:1px solid #edf0f2;padding-bottom:14px;display:flex;gap:16px;">
        <label style="display:flex;align-items:center;gap:6px;font-weight:600;font-size:13px;cursor:pointer;">
            <input type="radio" name="mode_select" value="single" checked onclick="toggleMode('single')">
            Input Satu Unit Individual
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-weight:600;font-size:13px;cursor:pointer;">
            <input type="radio" name="mode_select" value="batch" onclick="toggleMode('batch')">
            Buat Banyak Unit Sekaligus (Batch Generator)
        </label>
    </div>

    <!-- FORM INPUT SATU UNIT -->
    <form method="POST" action="tambah.php" id="formSingle">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="single">

        <div class="grid">
            <div class="field">
                <label>Master Peralatan *</label>
                <select name="peralatan_id" id="singlePeralatanId" required onchange="autoSuggestCode()">
                    <option value="">-- Pilih Peralatan --</option>
                    <?php foreach ($equipmentList as $eq): ?>
                        <option value="<?= $eq['id'] ?>" data-code="<?= e($eq['kode_barang']) ?>" <?= ($preSelectedId === (int)$eq['id']) ? 'selected' : '' ?>>
                            <?= e($eq['nama_barang']) ?> (<?= e($eq['kode_barang']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Kode Inventaris Unit (Unik) *</label>
                <input type="text" name="kode_unit" id="singleKodeUnit" required placeholder="Contoh: MKS-001" value="<?= e($_POST['kode_unit'] ?? '') ?>">
            </div>

            <div class="field">
                <label>Nomor Seri Pabrik (Serial Number)</label>
                <input type="text" name="serial_number" placeholder="Nomor seri resmi pabrikan jika ada" value="<?= e($_POST['serial_number'] ?? '') ?>">
            </div>

            <div class="field">
                <label>Tag / UID RFID Alat (Opsional)</label>
                <input type="text" name="rfid_uid" placeholder="Tap tag RFID alat ke reader jika ada" value="<?= e($_POST['rfid_uid'] ?? '') ?>">
            </div>

            <div class="field">
                <label>Kondisi Fisik Unit *</label>
                <select name="kondisi" required>
                    <option value="Baik">Baik</option>
                    <option value="Rusak Ringan">Rusak Ringan</option>
                    <option value="Rusak Berat">Rusak Berat</option>
                </select>
            </div>

            <div class="field">
                <label>Lokasi Penyimpanan / Rak</label>
                <input type="text" name="lokasi" placeholder="Contoh: Lemari A - Rak 2" value="<?= e($_POST['lokasi'] ?? '') ?>">
            </div>

            <div class="field">
                <label>Status Ketersediaan Awal *</label>
                <select name="status" required>
                    <option value="TERSEDIA">TERSEDIA (Siap Dipinjam)</option>
                    <option value="RUSAK">RUSAK</option>
                    <option value="MAINTENANCE">MAINTENANCE (Perbaikan)</option>
                </select>
            </div>

            <div class="field full">
                <label>Catatan Unit</label>
                <textarea name="catatan" placeholder="Catatan tambahan spesifik mengenai unit ini"><?= e($_POST['catatan'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="form-actions">
            <a class="btn" href="index.php">Batal</a>
            <button type="submit" class="btn btn-primary">Simpan Unit Peralatan</button>
        </div>
    </form>

    <!-- FORM GENERATOR BANYAK UNIT -->
    <form method="POST" action="tambah.php" id="formBatch" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="batch">

        <div class="grid">
            <div class="field">
                <label>Master Peralatan *</label>
                <select name="peralatan_id" id="batchPeralatanId" required onchange="updateBatchPrefix()">
                    <option value="">-- Pilih Peralatan --</option>
                    <?php foreach ($equipmentList as $eq): ?>
                        <option value="<?= $eq['id'] ?>" data-code="<?= e($eq['kode_barang']) ?>" <?= ($preSelectedId === (int)$eq['id']) ? 'selected' : '' ?>>
                            <?= e($eq['nama_barang']) ?> (<?= e($eq['kode_barang']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Awalan / Prefix Kode Unit *</label>
                <input type="text" name="batch_prefix" id="batchPrefix" required placeholder="Contoh: MKS-">
            </div>

            <div class="field">
                <label>Nomor Mulai (Start Number)</label>
                <input type="number" name="start_number" min="1" value="1" required>
            </div>

            <div class="field">
                <label>Jumlah Unit yang Ingin Dibuat (1 - 100) *</label>
                <input type="number" name="batch_qty" min="1" max="100" value="5" required>
            </div>

            <div class="field">
                <label>Kondisi Seluruh Unit</label>
                <select name="kondisi" required>
                    <option value="Baik">Baik</option>
                    <option value="Rusak Ringan">Rusak Ringan</option>
                </select>
            </div>

            <div class="field">
                <label>Lokasi Penyimpanan Bersama</label>
                <input type="text" name="lokasi" placeholder="Contoh: Rak Alat Kimia 1">
            </div>
        </div>

        <div class="form-actions">
            <a class="btn" href="index.php">Batal</a>
            <button type="submit" class="btn btn-primary">Generate Unit Massal</button>
        </div>
    </form>
</div>

<script>
function toggleMode(mode) {
    const singleForm = document.getElementById('formSingle');
    const batchForm = document.getElementById('formBatch');
    if (mode === 'single') {
        singleForm.style.display = 'block';
        batchForm.style.display = 'none';
    } else {
        singleForm.style.display = 'none';
        batchForm.style.display = 'block';
        updateBatchPrefix();
    }
}

function updateBatchPrefix() {
    const sel = document.getElementById('batchPeralatanId');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.code) {
        const code = opt.dataset.code.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
        document.getElementById('batchPrefix').value = code + '-';
    }
}

function autoSuggestCode() {
    const sel = document.getElementById('singlePeralatanId');
    const opt = sel.options[sel.selectedIndex];
    const codeInput = document.getElementById('singleKodeUnit');
    if (opt && opt.dataset.code && !codeInput.value) {
        const code = opt.dataset.code.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
        codeInput.value = code + '-001';
    }
}
</script>

<?php page_end(); ?>
