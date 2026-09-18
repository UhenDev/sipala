<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

// Handle aksi POST (Tambah, Toggle Status, Hapus)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'create') {
        $uid = trim((string)($_POST['uid'] ?? ''));
        $tipe = (string)($_POST['tipe'] ?? 'user');
        $userId = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
        $unitId = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
        $catatan = trim((string)($_POST['catatan'] ?? ''));

        if ($uid === '') {
            flash('error', 'UID RFID wajib diisi (tempelkan kartu ke reader).');
        } else {
            try {
                // Cek keunikan UID
                $chk = $pdo->prepare("SELECT id FROM rfid_cards WHERE uid = ?");
                $chk->execute([$uid]);
                if ($chk->fetch()) {
                    throw new RuntimeException("Kartu dengan UID '$uid' sudah terdaftar di sistem.");
                }

                $ins = $pdo->prepare("
                    INSERT INTO rfid_cards (user_id, unit_id, uid, tipe, status, catatan, created_at)
                    VALUES (?, ?, ?, ?, 'aktif', ?, NOW())
                ");
                $ins->execute([$userId, $unitId, $uid, $tipe, $catatan !== '' ? $catatan : null]);
                $cardId = (int)$pdo->lastInsertId();

                // Sinkronkan ke tabel users jika tipe user
                if ($tipe === 'user' && $userId !== null) {
                    $updU = $pdo->prepare("UPDATE users SET rfid_uid = ? WHERE id = ?");
                    $updU->execute([$uid, $userId]);
                }

                // Sinkronkan ke tabel unit_peralatan jika tipe alat
                if ($tipe === 'alat' && $unitId !== null) {
                    $updEq = $pdo->prepare("UPDATE unit_peralatan SET rfid_uid = ? WHERE id = ?");
                    $updEq->execute([$uid, $unitId]);
                }

                audit_log('CREATE', 'RFID', $cardId, "Mendaftarkan kartu RFID baru UID: $uid (Tipe: $tipe)");
                flash('success', "Kartu RFID UID $uid berhasil didaftarkan.");
            } catch (Throwable $e) {
                flash('error', 'Gagal mendaftarkan kartu RFID: ' . $e->getMessage());
            }
        }
    } elseif ($act === 'toggle_status') {
        $cardId = (int)($_POST['id'] ?? 0);
        try {
            $st = $pdo->prepare("SELECT id, uid, status FROM rfid_cards WHERE id = ?");
            $st->execute([$cardId]);
            $c = $st->fetch();

            if ($c) {
                $newStatus = ($c['status'] === 'aktif') ? 'nonaktif' : 'aktif';
                $upd = $pdo->prepare("UPDATE rfid_cards SET status = ? WHERE id = ?");
                $upd->execute([$newStatus, $cardId]);

                audit_log('UPDATE_STATUS', 'RFID', $cardId, "Mengubah status kartu RFID {$c['uid']} menjadi $newStatus");
                flash('success', "Status kartu RFID {$c['uid']} berhasil diubah menjadi $newStatus.");
            }
        } catch (Throwable $e) {
            flash('error', 'Gagal memperbarui status: ' . $e->getMessage());
        }
    } elseif ($act === 'delete') {
        $cardId = (int)($_POST['id'] ?? 0);
        try {
            $st = $pdo->prepare("SELECT id, uid, user_id, unit_id, tipe FROM rfid_cards WHERE id = ?");
            $st->execute([$cardId]);
            $c = $st->fetch();

            if ($c) {
                // Lepaskan referensi di users / unit_peralatan
                if ($c['tipe'] === 'user' && !empty($c['user_id'])) {
                    $pdo->prepare("UPDATE users SET rfid_uid = NULL WHERE id = ? AND rfid_uid = ?")->execute([$c['user_id'], $c['uid']]);
                }
                if ($c['tipe'] === 'alat' && !empty($c['unit_id'])) {
                    $pdo->prepare("UPDATE unit_peralatan SET rfid_uid = NULL WHERE id = ? AND rfid_uid = ?")->execute([$c['unit_id'], $c['uid']]);
                }

                $del = $pdo->prepare("DELETE FROM rfid_cards WHERE id = ?");
                $del->execute([$cardId]);

                audit_log('DELETE', 'RFID', $cardId, "Menghapus kartu RFID UID: {$c['uid']}");
                flash('success', "Kartu RFID {$c['uid']} berhasil dihapus.");
            }
        } catch (Throwable $e) {
            flash('error', 'Gagal menghapus kartu RFID: ' . $e->getMessage());
        }
    }
    redirect('index.php');
}

// Data pengguna dan unit alat untuk modal registrasi
$usersList = $pdo->query("SELECT id, nama, nis, username, role FROM users WHERE status = 'aktif' ORDER BY nama ASC")->fetchAll();
$unitsList = $pdo->query("
    SELECT u.id, u.kode_unit, p.nama_barang 
    FROM unit_peralatan u 
    JOIN peralatan p ON p.id = u.peralatan_id 
    ORDER BY p.nama_barang ASC, u.kode_unit ASC
")->fetchAll();

// Filter
$filterType = trim((string)($_GET['tipe'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$sql = "
    SELECT r.*, 
           u.nama AS nama_user, u.nis, u.role AS role_user,
           eq.kode_unit, p.nama_barang
    FROM rfid_cards r
    LEFT JOIN users u ON u.id = r.user_id
    LEFT JOIN unit_peralatan eq ON eq.id = r.unit_id
    LEFT JOIN peralatan p ON p.id = eq.peralatan_id
    WHERE 1=1
";
$params = [];

if ($filterType !== '') {
    $sql .= " AND r.tipe = ?";
    $params[] = $filterType;
}

if ($filterStatus !== '') {
    $sql .= " AND r.status = ?";
    $params[] = $filterStatus;
}

if ($search !== '') {
    $sql .= " AND (r.uid LIKE ? OR r.catatan LIKE ? OR u.nama LIKE ? OR u.nis LIKE ? OR eq.kode_unit LIKE ? OR p.nama_barang LIKE ?)";
    $t = '%' . $search . '%';
    $params = array_merge($params, [$t, $t, $t, $t, $t, $t]);
}

$sql .= " ORDER BY r.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cards = $stmt->fetchAll();

// Stats
$totalCards = (int)$pdo->query("SELECT COUNT(*) FROM rfid_cards")->fetchColumn();
$userCards = (int)$pdo->query("SELECT COUNT(*) FROM rfid_cards WHERE tipe = 'user'")->fetchColumn();
$unitCards = (int)$pdo->query("SELECT COUNT(*) FROM rfid_cards WHERE tipe = 'alat'")->fetchColumn();
$activeCards = (int)$pdo->query("SELECT COUNT(*) FROM rfid_cards WHERE status = 'aktif'")->fetchColumn();

page_start('Kartu RFID', 'Kelola kartu RFID pengguna dan unit peralatan lab', 'rfid');
?>

<div class="head">
    <div>
        <h2>Manajemen Kartu Smart RFID</h2>
        <p>Integrasi kartu RFID untuk identifikasi cepat siswa dan pelacakan unit fisik laboratorium.</p>
    </div>
    <button type="button" class="btn btn-primary" onclick="openRegisterRfidModal()">+ Daftarkan Kartu RFID</button>
</div>

<div class="stats">
    <div class="stat"><small>Total Kartu Terdaftar</small><strong><?= $totalCards ?></strong></div>
    <div class="stat"><small>Kartu Pengguna (Siswa/Staff)</small><strong><?= $userCards ?></strong></div>
    <div class="stat"><small>Tag RFID Unit Alat</small><strong><?= $unitCards ?></strong></div>
    <div class="stat"><small>Kartu Aktif</small><strong style="color:#16a34a;"><?= $activeCards ?></strong></div>
</div>

<div class="panel" style="margin-bottom:20px;">
    <form method="GET" action="index.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="field" style="flex:1;min-width:200px;">
            <label>Cari UID / Pemilik / Alat</label>
            <input type="text" name="q" placeholder="UID RFID, nama siswa, unit..." value="<?= e($search) ?>">
        </div>

        <div class="field" style="min-width:140px;">
            <label>Tipe Kartu</label>
            <select name="tipe">
                <option value="">-- Semua Tipe --</option>
                <option value="user" <?= $filterType === 'user' ? 'selected' : '' ?>>User / Siswa</option>
                <option value="alat" <?= $filterType === 'alat' ? 'selected' : '' ?>>Unit Peralatan</option>
            </select>
        </div>

        <div class="field" style="min-width:140px;">
            <label>Status</label>
            <select name="status">
                <option value="">-- Semua Status --</option>
                <option value="aktif" <?= $filterStatus === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                <option value="nonaktif" <?= $filterStatus === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
            </select>
        </div>

        <div style="display:flex;gap:6px;">
            <button type="submit" class="btn btn-primary" style="height:38px;">Filter</button>
            <?php if ($filterType || $filterStatus || $search): ?>
                <a href="index.php" class="btn" style="height:38px;">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-title">Daftar Kartu RFID (<?= count($cards) ?> terdata)</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:40px;">No</th>
                    <th>UID Kartu</th>
                    <th>Tipe Kartu</th>
                    <th>Terhubung Ke</th>
                    <th>Catatan / Label</th>
                    <th>Status</th>
                    <th>Tgl Didaftarkan</th>
                    <th style="text-align:right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$cards): ?>
                    <tr>
                        <td colspan="8"><div class="empty">Belum ada kartu RFID yang terdaftar.</div></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($cards as $i => $c): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <strong style="font-family:monospace;font-size:14px;letter-spacing:0.5px;color:#1e293b;"><?= e($c['uid']) ?></strong>
                            </td>
                            <td>
                                <span class="badge <?= $c['tipe'] === 'user' ? 'peminjam' : 'admin' ?>">
                                    <?= $c['tipe'] === 'user' ? 'Pengguna' : 'Unit Alat' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($c['tipe'] === 'user'): ?>
                                    <?php if ($c['nama_user']): ?>
                                        <strong><?= e($c['nama_user']) ?></strong>
                                        <div style="font-size:11px;color:#64748b;">NIS: <?= e($c['nis'] ?: '-') ?> (<?= e($c['role_user']) ?>)</div>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;font-style:italic;">Belum terikat pengguna</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($c['kode_unit']): ?>
                                        <strong><?= e($c['kode_unit']) ?></strong>
                                        <div style="font-size:11px;color:#64748b;"><?= e($c['nama_barang'] ?? '-') ?></div>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;font-style:italic;">Belum terikat unit alat</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e($c['catatan'] ?: '-') ?></td>
                            <td>
                                <span class="badge <?= $c['status'] === 'aktif' ? 'aktif' : 'nonaktif' ?>">
                                    <?= e($c['status']) ?>
                                </span>
                            </td>
                            <td><?= e(date('d-m-Y H:i', strtotime($c['created_at']))) ?></td>
                            <td style="text-align:right;">
                                <div class="actions" style="justify-content:flex-end;">
                                    <form method="POST" action="index.php" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="font-size:11px;">
                                            <?= $c['status'] === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' ?>
                                        </button>
                                    </form>

                                    <form method="POST" action="index.php" style="display:inline;" onsubmit="return confirm('Hapus kartu RFID <?= e($c['uid']) ?>?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="font-size:11px;">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL DAFTARKAN KARTU RFID BARU -->
<div class="modal" id="registerRfidModal">
    <div class="modal-box" style="max-width:480px;">
        <h3>Daftarkan Kartu RFID Baru</h3>
        <p style="margin-bottom:16px;">Tempelkan kartu RFID pada scanner atau ketikkan UID secara manual.</p>

        <form method="POST" action="index.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="field" style="margin-bottom:14px;">
                <label>UID Kartu RFID (Scan / Input) *</label>
                <input type="text" name="uid" id="modalRfidUid" required placeholder="Tempelkan kartu RFID di reader..." style="font-family:monospace;font-size:15px;font-weight:700;" autocomplete="off">
                <small style="color:#64748b;">Gunakan scanner USB (tekan Enter otomatis) atau input manual.</small>
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label>Tipe Penggunaan *</label>
                <select name="tipe" id="modalRfidType" required onchange="toggleAssignField(this.value)">
                    <option value="user">Kartu Pengguna (Siswa / Petugas)</option>
                    <option value="alat">Tag Unit Peralatan</option>
                </select>
            </div>

            <div class="field" id="assignUserDiv" style="margin-bottom:14px;">
                <label>Pilih Pengguna</label>
                <select name="user_id">
                    <option value="">-- Pilih Pengguna Terdaftar --</option>
                    <?php foreach ($usersList as $u): ?>
                        <option value="<?= $u['id'] ?>">
                            <?= e($u['nama']) ?> (<?= e($u['nis'] ?: $u['username']) ?> - <?= e($u['role']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field" id="assignUnitDiv" style="display:none;margin-bottom:14px;">
                <label>Pilih Unit Alat Fisik</label>
                <select name="unit_id">
                    <option value="">-- Pilih Unit Alat Fisik --</option>
                    <?php foreach ($unitsList as $un): ?>
                        <option value="<?= $un['id'] ?>">
                            <?= e($un['kode_unit']) ?> - <?= e($un['nama_barang']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label>Catatan / Keterangan</label>
                <input type="text" name="catatan" placeholder="Contoh: Kartu cadangan siswa / Tag alat baru">
            </div>

            <div class="modal-actions">
                <button type="button" class="btn" onclick="document.getElementById('registerRfidModal').classList.remove('show')">Batal</button>
                <button type="submit" class="btn btn-primary">Daftarkan Kartu</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRegisterRfidModal() {
    document.getElementById('registerRfidModal').classList.add('show');
    setTimeout(() => {
        const inp = document.getElementById('modalRfidUid');
        if (inp) inp.focus();
    }, 200);
}

function toggleAssignField(val) {
    const userDiv = document.getElementById('assignUserDiv');
    const unitDiv = document.getElementById('assignUnitDiv');
    if (val === 'user') {
        userDiv.style.display = 'block';
        unitDiv.style.display = 'none';
    } else {
        userDiv.style.display = 'none';
        unitDiv.style.display = 'block';
    }
}
</script>

<?php page_end(); ?>
