<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

$id = (int)($_GET['id'] ?? 0);
$q = $pdo->prepare('SELECT id, nama_kategori, deskripsi FROM kategori WHERE id = ?');
$q->execute([$id]);
$row = $q->fetch();

if (!$row) {
    flash('error', 'Kategori tidak ditemukan.');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $nama = trim((string)($_POST['nama_kategori'] ?? ''));
    $desk = trim((string)($_POST['deskripsi'] ?? ''));

    if ($nama === '') {
        flash('error', 'Nama kategori wajib diisi.');
    } elseif (mb_strlen($nama) < 2) {
        flash('error', 'Nama kategori minimal 2 karakter.');
    } else {
        // Cek nama kembar
        $stmtChk = $pdo->prepare('SELECT id FROM kategori WHERE LOWER(nama_kategori) = LOWER(?) AND id != ? LIMIT 1');
        $stmtChk->execute([$nama, $id]);
        if ($stmtChk->fetch()) {
            flash('error', 'Nama kategori "' . $nama . '" sudah digunakan oleh kategori lain.');
        } else {
            try {
                $q = $pdo->prepare('UPDATE kategori SET nama_kategori = ?, deskripsi = ? WHERE id = ?');
                $q->execute([$nama, $desk !== '' ? $desk : null, $id]);
                audit_log('UPDATE', 'KATEGORI', $id, "Memperbarui kategori $nama");
                flash('success', 'Kategori berhasil diperbarui.');
                redirect('index.php');
            } catch (Throwable $e) {
                flash('error', 'Kategori gagal diperbarui: ' . $e->getMessage());
            }
        }
    }
}

page_start('Edit Kategori', 'Perbarui data kategori', 'kategori');
?>

<div class="head">
    <div>
        <h2>Edit Kategori: <?= e($row['nama_kategori']) ?></h2>
        <p>Perbarui informasi klasifikasi alat laboratorium.</p>
    </div>
    <a class="btn" href="index.php">Kembali ke Daftar</a>
</div>

<div class="form-panel">
    <form method="post">
        <?= csrf_field() ?>
        <div class="field" style="margin-bottom:16px;">
            <label>Nama Kategori *</label>
            <input type="text" name="nama_kategori" maxlength="100" required value="<?= e((string)($_POST['nama_kategori'] ?? $row['nama_kategori'])) ?>">
        </div>
        <div class="field" style="margin-bottom:16px;">
            <label>Deskripsi Kategori</label>
            <textarea name="deskripsi" rows="3" placeholder="Deskripsi jenis peralatan dalam kelompok ini..."><?= e((string)($_POST['deskripsi'] ?? $row['deskripsi'] ?? '')) ?></textarea>
        </div>
        <div class="form-actions">
            <a class="btn" href="index.php">Batal</a>
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
        </div>
    </form>
</div>

<?php page_end(); ?>
