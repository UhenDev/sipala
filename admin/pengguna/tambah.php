<?php
require_once __DIR__ . '/../includes/admin.php';

$nama = '';
$nis = '';
$kelas = '';
$rfid_uid = '';
$username = '';
$role = 'peminjam';
$status = 'aktif';
$no_hp = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $role = (string)($_POST['role'] ?? 'peminjam');
    if (($_SESSION['role'] ?? '') !== 'admin' && $role === 'admin') {
        flash('error', 'Hanya Administrator utama yang berwenang membuat akun dengan peranan Administrator.');
        redirect('index.php');
    }
    $nama = trim((string)($_POST['nama'] ?? ''));
    $nis = trim((string)($_POST['nis'] ?? ''));
    $kelas = trim((string)($_POST['kelas'] ?? ''));
    $rfid_uid = trim((string)($_POST['rfid_uid'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '123456');
    $role = (string)($_POST['role'] ?? 'peminjam');
    $status = (string)($_POST['status'] ?? 'aktif');
    $no_hp = trim((string)($_POST['no_hp'] ?? ''));
    $foto = null;

    if ($nama === '' || $username === '') {
        flash('error', 'Nama dan Username wajib diisi.');
    } elseif ($password === '' || strlen($password) < 5) {
        flash('error', 'Password minimal 5 karakter.');
    } else {
        try {
            // Cek duplikasi username
            $chkUser = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $chkUser->execute([$username]);
            if ($chkUser->fetch()) {
                throw new RuntimeException('Username "' . $username . '" sudah digunakan. Silakan gunakan yang lain.');
            }

            // Cek duplikasi NIS
            if ($nis !== '') {
                $chkNis = $pdo->prepare('SELECT id FROM users WHERE nis = ? LIMIT 1');
                $chkNis->execute([$nis]);
                if ($chkNis->fetch()) {
                    throw new RuntimeException('NIS "' . $nis . '" sudah terdaftar pada pengguna lain.');
                }
            }

            // Cek duplikasi RFID
            if ($rfid_uid !== '') {
                $chkRfid = $pdo->prepare('SELECT id, nama FROM users WHERE rfid_uid = ? LIMIT 1');
                $chkRfid->execute([$rfid_uid]);
                $existing = $chkRfid->fetch();
                if ($existing) {
                    throw new RuntimeException('Kartu RFID (' . $rfid_uid . ') sudah terdaftar atas nama "' . $existing['nama'] . '".');
                }
            }

            // Handle upload foto profil terverifikasi
            $croppedBase64 = trim((string)($_POST['foto_cropped'] ?? ''));
            if (!empty($croppedBase64) && str_starts_with($croppedBase64, 'data:image/')) {
                $savedPath = secure_save_base64_image($croppedBase64, 'profile', 'profile');
                if ($savedPath) {
                    $foto = $savedPath;
                }
            } elseif (isset($_FILES['foto_file']) && $_FILES['foto_file']['error'] === UPLOAD_ERR_OK) {
                try {
                    $foto = secure_upload_image($_FILES['foto_file'], 'profile', 'profile');
                } catch (Throwable $uploadEx) {
                    throw new RuntimeException($uploadEx->getMessage());
                }
            }

            $stmt = $pdo->prepare('
                INSERT INTO users (nama, nis, kelas, rfid_uid, username, password, role, no_hp, status, foto)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $nama,
                $nis !== '' ? $nis : null,
                $kelas !== '' ? $kelas : null,
                $rfid_uid !== '' ? $rfid_uid : null,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $role,
                $no_hp !== '' ? $no_hp : null,
                $status,
                $foto
            ]);
            $newUserId = (int)$pdo->lastInsertId();

            if ($rfid_uid !== '') {
                $stCard = $pdo->prepare("
                    INSERT INTO rfid_cards (user_id, uid, tipe, status, catatan)
                    VALUES (?, ?, 'user', 'aktif', ?)
                    ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)
                ");
                $stCard->execute([$newUserId, $rfid_uid, 'Kartu pengguna: ' . $nama]);
            }

            audit_log('CREATE', 'PENGGUNA', $newUserId, "Menambahkan pengguna baru $nama ($username, role: $role)");

            flash('success', 'Pengguna ' . $nama . ' berhasil didaftarkan' . ($rfid_uid !== '' ? ' bersama Kartu RFID.' : '.'));
            redirect('index.php');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
    }
}

page_start('Tambah Pengguna & RFID', 'Daftarkan pengguna baru dan pasangkan ID Card ber-chip', 'pengguna');
?>

<div class="head">
    <div>
        <h2>Pendaftaran Siswa / Pengguna Baru</h2>
        <p>Admin mendaftarkan akun siswa yang disamakan dengan ID Card siswa ber-chip RFID.</p>
    </div>
    <a class="btn" href="index.php">Kembali</a>
</div>

<!-- RFID READER SCANNING BOX -->
<div class="rfid-box" style="margin-bottom:24px;">
    <div style="display:flex;align-items:center;gap:18px;">
        <div class="rfid-wave-icon">📶</div>
        <div style="flex:1;">
            <h3 style="margin:0 0 4px 0;font-size:16px;color:#ffffff;">Reader RFID Siap Mendeteksi Kartu Siswa</h3>
            <p style="margin:0;font-size:13px;color:#94a3b8;">
                Hubungkan USB RFID Reader, lalu <strong>tempelkan kartu chip siswa</strong> pada reader untuk mengisi nomor kartu otomatis.
            </p>
        </div>
    </div>
</div>

<div class="form-panel">
    <form method="post" id="formTambahUser" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="grid">
            <!-- RFID UID INPUT -->
            <div class="field full" style="background:#f1f5f9;padding:16px;border-radius:10px;border:2px dashed #93c5fd;">
                <label style="color:#1e40af;display:flex;align-items:center;gap:8px;">
                    <span>💳 NOMOR CHIP KARTU RFID (UID)</span>
                    <span style="font-size:11px;background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:12px;font-weight:700;">READER INPUT</span>
                </label>
                <div style="display:flex;gap:10px;margin-top:6px;">
                    <input type="text" name="rfid_uid" id="rfid_uid" value="<?= e($rfid_uid) ?>" 
                           placeholder="Tempelkan kartu siswa ke reader di sini (atau ketik manual UID)..." 
                           class="rfid-autofocus" data-rfid-scanner="true"
                           style="flex:1;font-family:monospace;font-size:16px;letter-spacing:1px;font-weight:700;background:#ffffff;border-color:#3b82f6;">
                    <button type="button" class="btn btn-primary" onclick="focusRfidInput()">
                        📡 Siapkan Reader
                    </button>
                </div>
                <small style="color:#64748b;margin-top:4px;display:block;">
                    * Ketika kartu di-tap ke alat reader USB, nomor chip akan otomatis terisi dan mengeluarkan bunyi konfirmasi (Beep).
                </small>
            </div>

            <!-- FOTO PROFIL INPUT & PREVIEW -->
            <div class="field full">
                <label>Foto Profil Pengguna (Opsional - JPG/PNG/WEBP, Maks. 2MB)</label>
                <div style="display:flex;gap:16px;align-items:center;padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;flex-wrap:wrap;">
                    <div id="fotoPreviewContainer" style="width:72px;height:72px;border-radius:50%;border:2px dashed #cbd5e1;background:#ffffff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
                        <img id="fotoPreviewImg" src="" alt="Pratinjau Foto" class="sipala-zoomable-img" style="width:100%;height:100%;object-fit:cover;display:none;">
                        <span id="fotoPlaceholderText" style="font-size:11px;color:#94a3b8;text-align:center;">Tanpa Foto</span>
                    </div>
                    <div style="flex:1;min-width:240px;">
                        <input type="file" name="foto_file" id="foto_file" accept=".jpg,.jpeg,.png,.webp" style="padding:7px;background:#ffffff;width:100%;">
                        <input type="hidden" name="foto_cropped" id="foto_cropped" value="">
                        <div style="font-size:11px;color:#0284c7;margin-top:4px;">
                            &#10024; <em>Pilih file gambar untuk membuka pemotong foto profil (rasio 1:1 lingkaran).</em>
                        </div>
                        <small style="color:#64748b;display:block;margin-top:4px;">Foto profil dapat diunggah sekarang atau dilengkapi siswa sendiri melalui halaman profil.</small>
                    </div>
                </div>
            </div>

            <div class="field">
                <label>Nama Lengkap Siswa / Pengguna *</label>
                <input type="text" name="nama" id="inputNama" required value="<?= e($nama) ?>" placeholder="Contoh: Hendrik Wildansyah">
            </div>

            <div class="field">
                <label>NIS (Nomor Induk Siswa)</label>
                <input type="text" name="nis" id="inputNis" value="<?= e($nis) ?>" placeholder="Contoh: 20261019">
            </div>

            <div class="field">
                <label>Kelas / Jurusan</label>
                <input type="text" name="kelas" value="<?= e($kelas) ?>" placeholder="Contoh: XII RPL 1 / XI TKJ 2">
            </div>

            <div class="field">
                <label>No. WhatsApp / HP</label>
                <input type="text" name="no_hp" value="<?= e($no_hp) ?>" placeholder="08xxxxxxxxxx">
            </div>

            <div class="field">
                <label>Username untuk Login *</label>
                <input type="text" name="username" id="inputUsername" required value="<?= e($username) ?>" placeholder="username_siswa">
            </div>

            <div class="field">
                <label>Password Akun *</label>
                <input type="text" name="password" required value="123456" placeholder="Minimal 5 karakter">
                <small style="color:#64748b;">* Default: <strong>123456</strong> (dapat diganti oleh siswa setelah login)</small>
            </div>

            <div class="field">
                <label>Hak Akses (Role) *</label>
                <select name="role">
                    <option value="peminjam" <?= $role === 'peminjam' ? 'selected' : '' ?>>Peminjam (Siswa)</option>
                    <option value="petugas" <?= $role === 'petugas' ? 'selected' : '' ?>>Petugas Laboratorium</option>
                    <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Administrator</option>
                </select>
            </div>

            <div class="field">
                <label>Status Akun *</label>
                <select name="status">
                    <option value="aktif" <?= $status === 'aktif' ? 'selected' : '' ?>>Aktif (Bisa Pinjam)</option>
                    <option value="nonaktif" <?= $status === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <a class="btn" href="index.php">Batal</a>
            <button type="submit" class="btn btn-primary" style="padding:10px 24px;font-size:14px;">
                💾 Simpan Akun Siswa & RFID
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

function focusRfidInput() {
    const el = document.getElementById('rfid_uid');
    if (el) {
        el.focus();
        el.select();
        if (window.SIPALASound) SIPALASound.beepSuccess();
    }
}

// Otomatis bantu buatkan username dari NIS jika username belum diisi
document.getElementById('inputNis').addEventListener('input', function() {
    const uInput = document.getElementById('inputUsername');
    if (uInput && !uInput.value) {
        uInput.value = this.value.trim();
    }
});
</script>

<?php page_end(); ?>
