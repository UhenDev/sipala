<?php
require_once __DIR__ . '/../includes/admin.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('index.php');
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    flash('error', 'Pengguna tidak ditemukan.');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $requestedRole = (string)($_POST['role'] ?? $user['role']);
    $currentAdminRole = (string)($_SESSION['role'] ?? '');
    if ($currentAdminRole !== 'admin') {
        if ($user['role'] === 'admin') {
            flash('error', 'Anda tidak memiliki wewenang untuk menyunting akun Administrator.');
            redirect('index.php');
        }
        if ($requestedRole === 'admin') {
            flash('error', 'Hanya Administrator yang berwenang mengubah peranan akun menjadi Administrator.');
            redirect('index.php');
        }
    }
    $nama = trim((string)($_POST['nama'] ?? ''));
    $nis = trim((string)($_POST['nis'] ?? ''));
    $kelas = trim((string)($_POST['kelas'] ?? ''));
    $rfid_uid = trim((string)($_POST['rfid_uid'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role = (string)($_POST['role'] ?? $user['role']);
    $status = (string)($_POST['status'] ?? $user['status']);
    $no_hp = trim((string)($_POST['no_hp'] ?? ''));
    $hapusFoto = !empty($_POST['hapus_foto']);
    $foto = $user['foto'] ?? null;

    if ($nama === '' || $username === '') {
        flash('error', 'Nama dan Username wajib diisi.');
    } else {
        try {
            // Cek duplikasi username
            $chkUser = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1');
            $chkUser->execute([$username, $id]);
            if ($chkUser->fetch()) {
                throw new RuntimeException('Username sudah digunakan oleh akun lain.');
            }

            // Cek duplikasi NIS
            if ($nis !== '') {
                $chkNis = $pdo->prepare('SELECT id FROM users WHERE nis = ? AND id != ? LIMIT 1');
                $chkNis->execute([$nis, $id]);
                if ($chkNis->fetch()) {
                    throw new RuntimeException('NIS sudah terdaftar pada akun lain.');
                }
            }

            // Cek duplikasi RFID
            if ($rfid_uid !== '') {
                $chkRfid = $pdo->prepare('SELECT id, nama FROM users WHERE rfid_uid = ? AND id != ? LIMIT 1');
                $chkRfid->execute([$rfid_uid, $id]);
                $ex = $chkRfid->fetch();
                if ($ex) {
                    throw new RuntimeException('Kartu RFID (' . $rfid_uid . ') sudah terdaftar atas nama "' . $ex['nama'] . '".');
                }
            }

            // Handle upload foto profil terverifikasi
            $croppedBase64 = trim((string)($_POST['foto_cropped'] ?? ''));
            if (!empty($croppedBase64) && str_starts_with($croppedBase64, 'data:image/')) {
                $savedPath = secure_save_base64_image($croppedBase64, 'profile', 'profile_' . $id);
                if ($savedPath) {
                    if (!empty($user['foto']) && str_starts_with($user['foto'], 'assets/uploads/profile/')) {
                        $oldPath = __DIR__ . '/../../' . $user['foto'];
                        if (file_exists($oldPath)) @unlink($oldPath);
                    }
                    $foto = $savedPath;
                }
            } elseif (isset($_FILES['foto_file']) && $_FILES['foto_file']['error'] === UPLOAD_ERR_OK) {
                try {
                    $savedPath = secure_upload_image($_FILES['foto_file'], 'profile', 'profile_' . $id);
                    if (!empty($user['foto']) && str_starts_with($user['foto'], 'assets/uploads/profile/')) {
                        $oldPath = __DIR__ . '/../../' . $user['foto'];
                        if (file_exists($oldPath)) @unlink($oldPath);
                    }
                    $foto = $savedPath;
                } catch (Throwable $uploadEx) {
                    throw new RuntimeException($uploadEx->getMessage());
                }
            } elseif ($hapusFoto) {
                if (!empty($user['foto']) && str_starts_with($user['foto'], 'assets/uploads/profile/')) {
                    $oldPath = __DIR__ . '/../../' . $user['foto'];
                    if (file_exists($oldPath)) @unlink($oldPath);
                }
                $foto = null;
            }

            if ($password !== '') {
                if (strlen($password) < 5) {
                    throw new RuntimeException('Password minimal 5 karakter.');
                }
                $update = $pdo->prepare('
                    UPDATE users 
                    SET nama = ?, nis = ?, kelas = ?, rfid_uid = ?, username = ?, password = ?, role = ?, no_hp = ?, status = ?, foto = ?
                    WHERE id = ?
                ');
                $update->execute([
                    $nama,
                    $nis !== '' ? $nis : null,
                    $kelas !== '' ? $kelas : null,
                    $rfid_uid !== '' ? $rfid_uid : null,
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role,
                    $no_hp !== '' ? $no_hp : null,
                    $status,
                    $foto,
                    $id
                ]);
            } else {
                $update = $pdo->prepare('
                    UPDATE users 
                    SET nama = ?, nis = ?, kelas = ?, rfid_uid = ?, username = ?, role = ?, no_hp = ?, status = ?, foto = ?
                    WHERE id = ?
                ');
                $update->execute([
                    $nama,
                    $nis !== '' ? $nis : null,
                    $kelas !== '' ? $kelas : null,
                    $rfid_uid !== '' ? $rfid_uid : null,
                    $username,
                    $role,
                    $no_hp !== '' ? $no_hp : null,
                    $status,
                    $foto,
                    $id
                ]);
            }

            if ($id === (int)$_SESSION['user_id']) {
                $_SESSION['nama'] = $nama;
                $_SESSION['foto'] = $foto ?? '';
            }

            if ($rfid_uid !== '') {
                $stCard = $pdo->prepare("
                    INSERT INTO rfid_cards (user_id, uid, tipe, status, catatan)
                    VALUES (?, ?, 'user', 'aktif', ?)
                    ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)
                ");
                $stCard->execute([$id, $rfid_uid, 'Kartu pengguna: ' . $nama]);
            }

            audit_log('UPDATE', 'PENGGUNA', $id, "Mengubah data pengguna $nama ($username, status: $status)");

            flash('success', 'Data akun dan kartu RFID pengguna ' . $nama . ' berhasil diperbarui.');
            redirect('index.php');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
    }
}

page_start('Edit Pengguna & RFID', 'Perbarui data siswa atau daftarkan kartu chip RFID baru', 'pengguna');
?>

<div class="head">
    <div>
        <h2>Edit Pengguna: <?= e($user['nama']) ?></h2>
        <p>Kelola data akun siswa dan pasangkan kartu RFID.</p>
    </div>
    <a class="btn" href="index.php">Kembali</a>
</div>

<!-- RFID READER SCANNING BOX -->
<div class="rfid-box" style="margin-bottom:24px;">
    <div style="display:flex;align-items:center;gap:18px;">
        <div class="rfid-wave-icon">📶</div>
        <div style="flex:1;">
            <h3 style="margin:0 0 4px 0;font-size:16px;color:#ffffff;">Reader RFID Siswa</h3>
            <p style="margin:0;font-size:13px;color:#94a3b8;">
                Untuk mendaftarkan atau mengganti kartu pintar siswa ini, <strong>tempelkan kartu baru pada alat reader USB</strong>.
            </p>
        </div>
        <div>
            <?php if (!empty($user['rfid_uid'])): ?>
                <span class="rfid-badge rfid-badge-active" style="padding:8px 14px;font-size:13px;">
                    💳 Chip Terpasang: <?= e($user['rfid_uid']) ?>
                </span>
            <?php else: ?>
                <span class="rfid-badge rfid-badge-inactive" style="padding:8px 14px;font-size:13px;">
                    ⚠️ Belum Ada Chip
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="form-panel">
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="grid">
            <!-- RFID UID INPUT -->
            <div class="field full" style="background:#f1f5f9;padding:16px;border-radius:10px;border:2px dashed #93c5fd;">
                <label style="color:#1e40af;display:flex;align-items:center;gap:8px;">
                    <span>💳 NOMOR CHIP KARTU RFID (UID)</span>
                    <span style="font-size:11px;background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:12px;font-weight:700;">READER INPUT</span>
                </label>
                <div style="display:flex;gap:10px;margin-top:6px;">
                    <input type="text" name="rfid_uid" id="rfid_uid" value="<?= e($user['rfid_uid'] ?? '') ?>" 
                           placeholder="Tempelkan kartu siswa ke reader (atau ketik UID)..." 
                           class="rfid-autofocus" data-rfid-scanner="true"
                           style="flex:1;font-family:monospace;font-size:16px;letter-spacing:1px;font-weight:700;background:#ffffff;border-color:#3b82f6;">
                    <button type="button" class="btn btn-primary" onclick="document.getElementById('rfid_uid').focus();">
                        📡 Siapkan Reader
                    </button>
                </div>
            </div>

            <!-- FOTO PROFIL INPUT & PREVIEW -->
            <div class="field full">
                <label>Foto Profil Pengguna (JPG/PNG/WEBP, Maks. 2MB)</label>
                <div style="display:flex;gap:16px;align-items:center;padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;flex-wrap:wrap;">
                    <div id="fotoPreviewContainer" style="width:72px;height:72px;border-radius:50%;border:2px dashed #cbd5e1;background:#ffffff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
                        <?php if (!empty($user['foto']) && file_exists(__DIR__ . '/../../' . $user['foto'])): ?>
                            <img id="fotoPreviewImg" src="../../<?= e($user['foto']) ?>" alt="Foto Profil" class="sipala-zoomable-img"
                                 data-lightbox-src="../../<?= e($user['foto']) ?>"
                                 data-lightbox-title="<?= e($user['nama']) ?>"
                                 data-lightbox-sub="Username: @<?= e($user['username']) ?> &bull; <?= ucfirst(e($user['role'])) ?>"
                                 data-lightbox-badge="Foto Profil Pengguna"
                                 title="Klik untuk melihat foto ukuran penuh"
                                 style="width:100%;height:100%;object-fit:cover;cursor:zoom-in;">
                            <span id="fotoPlaceholderText" style="font-size:11px;color:#94a3b8;display:none;text-align:center;">Foto</span>
                        <?php else: ?>
                            <img id="fotoPreviewImg" src="" alt="Pratinjau Foto" class="sipala-zoomable-img" style="width:100%;height:100%;object-fit:cover;display:none;">
                            <span id="fotoPlaceholderText" style="font-size:11px;color:#94a3b8;text-align:center;">Tanpa Foto</span>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:240px;">
                        <input type="file" name="foto_file" id="foto_file" accept=".jpg,.jpeg,.png,.webp" style="padding:7px;background:#ffffff;width:100%;">
                        <input type="hidden" name="foto_cropped" id="foto_cropped" value="">
                        <div style="font-size:11px;color:#0284c7;margin-top:4px;">
                            &#10024; <em>Pilih file gambar untuk membuka pemotong foto profil (rasio 1:1 lingkaran).</em>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;flex-wrap:wrap;gap:6px;">
                            <small style="color:#64748b;">Pilih gambar baru untuk mengganti foto profil saat ini.</small>
                            <?php if (!empty($user['foto'])): ?>
                                <label style="font-size:12px;color:#dc2626;display:inline-flex;align-items:center;gap:4px;cursor:pointer;">
                                    <input type="checkbox" name="hapus_foto" value="1"> Hapus foto profil ini
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="field">
                <label>Nama Lengkap Siswa / Pengguna *</label>
                <input type="text" name="nama" required value="<?= e($user['nama']) ?>">
            </div>

            <div class="field">
                <label>NIS (Nomor Induk Siswa)</label>
                <input type="text" name="nis" value="<?= e($user['nis'] ?? '') ?>">
            </div>

            <div class="field">
                <label>Kelas / Jurusan</label>
                <input type="text" name="kelas" value="<?= e($user['kelas'] ?? '') ?>">
            </div>

            <div class="field">
                <label>No. WhatsApp / HP</label>
                <input type="text" name="no_hp" value="<?= e($user['no_hp'] ?? '') ?>">
            </div>

            <div class="field">
                <label>Username *</label>
                <input type="text" name="username" required value="<?= e($user['username']) ?>">
            </div>

            <div class="field">
                <label>Password Baru (Opsional)</label>
                <input type="password" name="password" placeholder="Kosongkan jika tidak ingin mengubah password">
            </div>

            <div class="field">
                <label>Hak Akses (Role) *</label>
                <select name="role">
                    <option value="peminjam" <?= $user['role'] === 'peminjam' ? 'selected' : '' ?>>Peminjam (Siswa)</option>
                    <option value="petugas" <?= $user['role'] === 'petugas' ? 'selected' : '' ?>>Petugas Laboratorium</option>
                    <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
                </select>
            </div>

            <div class="field">
                <label>Status Akun *</label>
                <select name="status">
                    <option value="aktif" <?= $user['status'] === 'aktif' ? 'selected' : '' ?>>Aktif (Bisa Pinjam)</option>
                    <option value="nonaktif" <?= $user['status'] === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <a class="btn" href="index.php">Batal</a>
            <button type="submit" class="btn btn-primary" style="padding:10px 24px;font-size:14px;">
                💾 Simpan Perubahan
            </button>
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
            aspectRatio: 1,
            shape: 'round',
            title: 'Sesuaikan & Potong Foto Profil Pengguna'
        });
    }
});
</script>

<?php page_end(); ?>
