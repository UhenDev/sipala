<?php

/**
 * FILE       : admin/kategori/index.php
 * MODUL      : Master Kategori (Legacy / Redirect)
 * FUNGSI     : Mengalihkan lalu lintas modul kategori terpisah langsung ke Master Peralatan
 * AKSES      : Administrator / Petugas Laboratorium
 *
 * ALUR:
 * 1. Memeriksa otorisasi admin/petugas.
 * 2. Mengirimkan flash message informatif bahwa input kategori kini langsung dikelola di Master Peralatan.
 * 3. Mengalihkan (302 Redirect) pengguna ke admin/peralatan/index.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/admin.php';

// Pengelolaan kategori aktif mandiri sebagai modul tersendiri


// PROSES TAMBAH KATEGORI
$formNama = '';
$formDeskripsi = '';
$showModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'tambah_kategori') {
        $formNama = trim((string)($_POST['nama_kategori'] ?? ''));
        $formDeskripsi = trim((string)($_POST['deskripsi'] ?? ''));

        if ($formNama === '') {
            flash('error', 'Nama kategori wajib diisi.');
            $showModal = true;
        } elseif (mb_strlen($formNama) < 2) {
            flash('error', 'Nama kategori minimal 2 karakter.');
            $showModal = true;
        } else {
            $stmt = $pdo->prepare('SELECT id FROM kategori WHERE LOWER(nama_kategori) = LOWER(?) LIMIT 1');
            $stmt->execute([$formNama]);

            if ($stmt->fetch()) {
                flash('error', 'Nama kategori "' . $formNama . '" sudah tersedia.');
                $showModal = true;
            } else {
                try {
                    $stmt = $pdo->prepare('INSERT INTO kategori (nama_kategori, deskripsi) VALUES (?, ?)');
                    $stmt->execute([
                        $formNama,
                        $formDeskripsi !== '' ? $formDeskripsi : null
                    ]);
                    $newKatId = (int)$pdo->lastInsertId();
                    audit_log('CREATE', 'KATEGORI', $newKatId, "Menambahkan kategori $formNama");

                    flash('success', 'Kategori "' . $formNama . '" berhasil ditambahkan.');
                    redirect('index.php');
                } catch (Throwable $exception) {
                    flash('error', 'Gagal menambahkan kategori. Silakan coba lagi.');
                    $showModal = true;
                }
            }
        }
    }
}

// Query data kategori beserta jumlah alat
$categories = $pdo->query('
    SELECT k.id, k.nama_kategori, k.deskripsi, k.created_at, COUNT(p.id) AS total_alat
    FROM kategori k
    LEFT JOIN peralatan p ON p.kategori_id = k.id
    GROUP BY k.id, k.nama_kategori, k.deskripsi, k.created_at
    ORDER BY k.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

$totalCategories = count($categories);
$totalEquipments = array_sum(array_map(fn($c) => (int)$c['total_alat'], $categories));

page_start('Kategori', 'Kelola kategori peralatan laboratorium', 'kategori');
?>

<div class="head">
    <div>
        <h2>Data Kategori Peralatan</h2>
        <p>Kelola kelompok dan klasifikasi alat praktikum laboratorium.</p>
    </div>
    <button type="button" class="btn btn-primary" onclick="openModalTambah()">+ Tambah Kategori</button>
</div>

<div class="stats">
    <div class="stat">
        <small>Total Kategori</small>
        <strong><?= number_format($totalCategories) ?></strong>
    </div>
    <div class="stat">
        <small>Total Alat Terkategori</small>
        <strong><?= number_format($totalEquipments) ?></strong>
    </div>
</div>

<div class="panel">
    <div class="panel-title">
        <span>Daftar Kategori</span>
        <span style="font-size:12px;color:#7b8492;font-weight:normal;">Total: <?= $totalCategories ?> kategori</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:50px;">No</th>
                    <th>Nama Kategori</th>
                    <th>Deskripsi</th>
                    <th style="width:120px;text-align:center;">Jumlah Alat</th>
                    <th style="width:140px;text-align:right;">Tindakan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="5" class="empty">Belum ada kategori yang ditambahkan.</td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; foreach ($categories as $cat): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <strong style="color:#172033;font-size:13.5px;"><?= e((string)$cat['nama_kategori']) ?></strong>
                            </td>
                            <td>
                                <span style="color:#64748b;font-size:12.5px;">
                                    <?= !empty($cat['deskripsi']) ? e((string)$cat['deskripsi']) : '<em style="color:#94a3b8;">Tidak ada deskripsi</em>' ?>
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge <?= (int)$cat['total_alat'] > 0 ? 'DISETUJUI' : 'nonaktif' ?>" style="font-size:11px;">
                                    <?= (int)$cat['total_alat'] ?> Alat
                                </span>
                            </td>
                            <td style="text-align:right;">
                                <div class="actions" style="justify-content:flex-end;">
                                    <a class="btn btn-sm btn-edit" href="edit.php?id=<?= (int)$cat['id'] ?>" title="Edit Kategori">
                                        Edit
                                    </a>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="confirmDelete('hapus.php?id=<?= (int)$cat['id'] ?>', 'Kategori <?= e(addslashes((string)$cat['nama_kategori'])) ?>')"
                                            title="Hapus Kategori">
                                        Hapus
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL TAMBAH KATEGORI -->
<div class="modal <?= $showModal ? 'show' : '' ?>" id="modalTambah">
    <div class="modal-box">
        <h3>Tambah Kategori Baru</h3>
        <p>Masukkan nama dan keterangan singkat untuk kategori peralatan baru.</p>
        <form method="post" action="index.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_kategori">
            <div class="field" style="margin-bottom:14px;">
                <label>Nama Kategori *</label>
                <input type="text" name="nama_kategori" required maxlength="100" 
                       placeholder="Contoh: Alat Ukur Elektronik" 
                       value="<?= e($formNama) ?>" id="inputNamaKat">
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label>Deskripsi (Opsional)</label>
                <textarea name="deskripsi" rows="3" placeholder="Penjelasan singkat mengenai kategori ini..."><?= e($formDeskripsi) ?></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn" onclick="closeModalTambah()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Kategori</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModalTambah() {
    const m = document.getElementById('modalTambah');
    if (m) {
        m.classList.add('show');
        setTimeout(function() {
            const inp = document.getElementById('inputNamaKat');
            if (inp) inp.focus();
        }, 100);
    }
}
function closeModalTambah() {
    const m = document.getElementById('modalTambah');
    if (m) m.classList.remove('show');
}
</script>

<?php page_end(); ?>