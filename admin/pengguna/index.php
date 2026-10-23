<?php

/**
 * FILE       : admin/pengguna/index.php
 * MODUL      : Manajemen Pengguna & Siswa
 * FUNGSI     : Menampilkan daftar akun, mengelola hak akses/peran, status akun, nomor kartu RFID, dan tindakan penghapusan massal terproteksi
 * AKSES      : Administrator / Petugas Laboratorium
 *
 * ALUR:
 * 1. Memeriksa otorisasi admin/petugas.
 * 2. Menangani aksi POST hapus massal dengan pembersihan relasi dependensi (peminjaman, detail, audit log).
 * 3. Mengambil filter pencarian (nama, username, NIS, RFID, kelas, peranan, status).
 * 4. Menyajikan daftar pengguna dalam tabel interaktif dengan foto profil dan status kartu RFID.
 */

require_once __DIR__ . '/../includes/admin.php';
require_admin();

// PROSES PENGHAPUSAN MASSAL (BULK DELETE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete') {
    require_csrf();
    $rawIds = $_POST['selected_ids'] ?? [];
    $ids = array_filter(array_map('intval', (array)$rawIds), fn($x) => $x > 0 && $x !== (int)$_SESSION['user_id']);

    if (empty($ids)) {
        flash('error', 'Tidak ada pengguna yang dipilih untuk dihapus.');
        redirect('index.php');
    }

    $deletedCount = 0;
    $adminId = (int)($_SESSION['user_id'] ?? 1);

    try {
        $pdo->beginTransaction();

        $stmtP = $pdo->prepare('SELECT id, status FROM peminjaman WHERE user_id = ?');
        $stmtDetails = $pdo->prepare('SELECT peralatan_id, jumlah FROM detail_peminjaman WHERE peminjaman_id = ?');
        $stmtRestore = $pdo->prepare('UPDATE peralatan SET jumlah_tersedia = jumlah_tersedia + ? WHERE id = ?');
        $stmtDelRet = $pdo->prepare('DELETE FROM pengembalian WHERE peminjaman_id = ?');
        $stmtDelDet = $pdo->prepare('DELETE FROM detail_peminjaman WHERE peminjaman_id = ?');
        $stmtDelLoan = $pdo->prepare('DELETE FROM peminjaman WHERE id = ?');
        $stmtNullAppr = $pdo->prepare('UPDATE peminjaman SET disetujui_oleh = NULL WHERE disetujui_oleh = ?');
        $stmtReassignRet = $pdo->prepare('UPDATE pengembalian SET diproses_oleh = ? WHERE diproses_oleh = ?');
        $stmtDelUser = $pdo->prepare('DELETE FROM users WHERE id = ?');

        foreach ($ids as $delId) {
            // 1. Ambil semua peminjaman milik pengguna ini
            $stmtP->execute([$delId]);
            $loans = $stmtP->fetchAll(PDO::FETCH_ASSOC);

            foreach ($loans as $loan) {
                $loanId = (int)$loan['id'];

                // Jika status pinjaman sedang aktif (DIPINJAM), pulihkan stok barang
                if ($loan['status'] === 'DIPINJAM') {
                    $stmtDetails->execute([$loanId]);
                    $items = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($items as $it) {
                        $stmtRestore->execute([(int)$it['jumlah'], (int)$it['peralatan_id']]);
                    }
                }

                // Hapus catatan relasi agar tidak melanggar foreign key constraint
                $stmtDelRet->execute([$loanId]);
                $stmtDelDet->execute([$loanId]);
                $stmtDelLoan->execute([$loanId]);
            }

            // Lepaskan referensi jika pernah bertindak sebagai staf/petugas
            $stmtNullAppr->execute([$delId]);
            $stmtReassignRet->execute([$adminId, $delId]);

            // Hapus record pengguna
            $stmtDelUser->execute([$delId]);
            $deletedCount++;
        }

        $pdo->commit();
        flash('success', "Berhasil menghapus $deletedCount pengguna terpilih beserta riwayat transaksinya.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Gagal memproses penghapusan massal: ' . $e->getMessage());
    }

    redirect('index.php');
}

$search = trim((string)($_GET['q'] ?? ''));

$sql = 'SELECT id, nama, username, role, status, nis, kelas, rfid_uid, foto, created_at FROM users ';
$params = [];

if ($search !== '') {
    $sql .= 'WHERE (nama LIKE ? OR username LIKE ? OR nis LIKE ? OR kelas LIKE ? OR rfid_uid LIKE ?) ';
    $term = '%' . $search . '%';
    $params = [$term, $term, $term, $term, $term];
}

$sql .= 'ORDER BY id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$totalUsers = count($users);
$totalPeminjam = 0;
$totalRfid = 0;
$totalNoRfid = 0;

foreach ($users as $u) {
    if ($u['role'] === 'peminjam') $totalPeminjam++;
    if (!empty($u['rfid_uid'])) {
        $totalRfid++;
    } else {
        $totalNoRfid++;
    }
}

page_start('Pengguna & Kartu RFID', 'Kelola akun pengguna dan sinkronisasi kartu pintar siswa', 'pengguna');
?>

<div class="head">
    <div>
        <h2>Manajemen Pengguna & ID Card RFID</h2>
        <p>Data akun siswa, staf laboratorium, serta status kartu chip RFID yang terdaftar.</p>
    </div>
    <div class="actions">
        <a class="btn btn-primary" href="tambah.php">+ Tambah Pengguna (RFID)</a>
    </div>
</div>

<div class="stats">
    <div class="stat">
        <small>Total Pengguna</small>
        <strong><?= $totalUsers ?></strong>
    </div>
    <div class="stat">
        <small>Siswa / Peminjam</small>
        <strong><?= $totalPeminjam ?></strong>
    </div>
    <div class="stat">
        <small>Kartu RFID Aktif</small>
        <strong style="color:#16a34a"><?= $totalRfid ?></strong>
    </div>
    <div class="stat">
        <small>Belum Punya Kartu</small>
        <strong style="color:#d97706"><?= $totalNoRfid ?></strong>
    </div>
</div>

<div class="panel">
    <div class="panel-title">
        <span>Daftar Pengguna Terdaftar</span>
        <form method="get" style="display:flex;gap:8px;align-items:center;">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nama, NIS, atau Tap Kartu..." 
                   data-rfid-scanner="true" data-auto-submit="true"
                   style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;min-width:240px;">
            <?php if ($search !== ''): ?>
                <a href="index.php" class="btn" style="padding:6px 10px;">Reset</a>
            <?php endif; ?>
            <button class="btn btn-primary" style="padding:6px 12px;">Cari</button>
        </form>
    </div>

    <!-- TOOLBAR AKSI MASSAL (MUNCUL OTOMATIS SAAT ADA DATA DIPILIH) -->
    <div id="bulkToolbar" style="display:none;align-items:center;justify-content:space-between;background:#fef2f2;border-bottom:1px solid #fecaca;padding:12px 20px;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span id="selectedCountBadge" style="display:inline-flex;width:24px;height:24px;border-radius:50%;background:#dc2626;color:#ffffff;align-items:center;justify-content:center;font-size:12px;font-weight:700;">0</span>
            <strong style="color:#991b1b;font-size:13px;"><span id="selectedCountText">0</span> Pengguna Terpilih</strong>
            <span style="font-size:12px;color:#64748b;">(Gunakan kotak Pilih Semua untuk menandai seluruh baris)</span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <button type="button" class="btn btn-sm" onclick="deselectAllUsers()" style="background:#ffffff;">Batal Pilih</button>
            <button type="button" class="btn btn-sm btn-danger" onclick="confirmBulkDelete()">
                Hapus Pengguna Terpilih
            </button>
        </div>
    </div>

    <form method="post" id="formBulkUsers" action="index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="bulk_action" value="delete">

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:38px;text-align:center;">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" title="Pilih Semua Pengguna" style="cursor:pointer;width:15px;height:15px;">
                        </th>
                        <th style="width:40px;">No</th>
                        <th style="width:52px;text-align:center;">Foto</th>
                        <th>Nama & Username</th>
                        <th>NIS / Kelas</th>
                        <th>Role</th>
                        <th>Status Kartu RFID</th>
                        <th>Status Akun</th>
                        <th style="text-align:right;">Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="9">
                                <div class="empty">Tidak ada data pengguna ditemukan.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $i => $u): ?>
                            <tr>
                                <td style="text-align:center;">
                                    <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                                        <input type="checkbox" name="selected_ids[]" value="<?= $u['id'] ?>" class="user-chk" 
                                               data-name="<?= e($u['nama']) ?>" onchange="updateBulkUI()" style="cursor:pointer;width:15px;height:15px;">
                                    <?php else: ?>
                                        <span title="Akun Anda sendiri tidak dapat dipilih/dihapus" style="color:#cbd5e1;cursor:not-allowed;font-size:16px;">&bull;</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $i + 1 ?></td>
                                <td style="text-align:center;">
                                    <?php if (!empty($u['foto']) && file_exists(__DIR__ . '/../../' . $u['foto'])): ?>
                                        <img src="../../<?= e($u['foto']) ?>" alt="<?= e($u['nama']) ?>" class="sipala-zoomable-img"
                                             data-lightbox-src="../../<?= e($u['foto']) ?>"
                                             data-lightbox-title="<?= e($u['nama']) ?>"
                                             data-lightbox-sub="Username: @<?= e($u['username']) ?> &bull; Role: <?= ucfirst(e($u['role'])) ?>"
                                             data-lightbox-badge="Foto Profil"
                                             title="Klik untuk melihat foto profil ukuran penuh"
                                             style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:1px solid #cbd5e1;display:inline-block;cursor:zoom-in;vertical-align:middle;">
                                    <?php else: ?>
                                        <div style="width:40px;height:40px;border-radius:50%;background:#eaf1f8;color:#1f4f82;border:1px solid #cbd5e1;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;vertical-align:middle;">
                                            <?= strtoupper(substr($u['nama'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= e($u['nama']) ?></strong>
                                    <?php if ((int)$u['id'] === (int)$_SESSION['user_id']): ?>
                                        <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:10px;margin-left:4px;">Anda</span>
                                    <?php endif; ?>
                                    <div style="font-size:12px;color:#64748b;">@<?= e($u['username']) ?></div>
                                </td>
                                <td>
                                    <div><?= e($u['nis'] ?: '-') ?></div>
                                    <div style="font-size:12px;color:#64748b;"><?= e($u['kelas'] ?: 'Umum / Staf') ?></div>
                                </td>
                                <td>
                                    <span class="badge <?= e($u['role']) ?>"><?= e($u['role']) ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($u['rfid_uid'])): ?>
                                        <span class="rfid-badge rfid-badge-active" title="Nomor Chip RFID: <?= e($u['rfid_uid']) ?>">
                                            <span>&#x1F4B3;</span>
                                            <strong style="font-family:monospace;"><?= e($u['rfid_uid']) ?></strong>
                                        </span>
                                    <?php else: ?>
                                        <span class="rfid-badge rfid-badge-inactive">
                                            Belum Ada Kartu
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= e($u['status']) ?>"><?= e($u['status']) ?></span>
                                </td>
                                <td style="text-align:right;">
                                    <div class="actions" style="justify-content:flex-end;">
                                        <a class="btn btn-sm btn-edit" href="edit.php?id=<?= $u['id'] ?>" title="Edit data & daftarkan kartu RFID">
                                            Edit / RFID
                                        </a>
                                        <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                                            <button type="button" class="btn btn-sm btn-danger" 
                                               onclick="confirmDelete('hapus.php?id=<?= $u['id'] ?>', 'Pengguna <?= e(addslashes($u['nama'])) ?>')">
                                                Hapus
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<script>
function updateBulkUI() {
    const chks = document.querySelectorAll('.user-chk:checked');
    const count = chks.length;
    const toolbar = document.getElementById('bulkToolbar');
    const badge = document.getElementById('selectedCountBadge');
    const text = document.getElementById('selectedCountText');
    const selectAllChk = document.getElementById('selectAll');

    const totalChks = document.querySelectorAll('.user-chk').length;
    if (selectAllChk) {
        selectAllChk.checked = totalChks > 0 && count === totalChks;
        selectAllChk.indeterminate = count > 0 && count < totalChks;
    }

    if (count > 0) {
        toolbar.style.display = 'flex';
        badge.textContent = count;
        text.textContent = count;
    } else {
        toolbar.style.display = 'none';
    }
}

function toggleSelectAll(master) {
    const chks = document.querySelectorAll('.user-chk');
    chks.forEach(chk => {
        chk.checked = master.checked;
    });
    updateBulkUI();
}

function deselectAllUsers() {
    const chks = document.querySelectorAll('.user-chk');
    chks.forEach(chk => {
        chk.checked = false;
    });
    const selectAllChk = document.getElementById('selectAll');
    if (selectAllChk) {
        selectAllChk.checked = false;
        selectAllChk.indeterminate = false;
    }
    updateBulkUI();
}

function confirmBulkDelete() {
    const chks = document.querySelectorAll('.user-chk:checked');
    const count = chks.length;
    if (count === 0) return;

    if (!window.Swal) {
        if (confirm('Apakah Anda yakin ingin menghapus ' + count + ' pengguna yang dipilih?')) {
            document.getElementById('formBulkUsers').submit();
        }
        return;
    }

    Swal.fire({
        title: 'Hapus ' + count + ' Pengguna Terpilih?',
        html: 'Apakah Anda yakin ingin menghapus <strong>' + count + ' pengguna</strong> yang telah dipilih?<br><small style="color:#64748b;font-size:12px;margin-top:6px;display:block;">Seluruh data akun terpilih beserta riwayatnya akan dihapus permanen. Jika terdapat alat yang masih dipinjam, stok alat akan dipulihkan otomatis ke laboratorium.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#94a3b8',
        confirmButtonText: 'Ya, Hapus ' + count + ' Pengguna',
        cancelButtonText: 'Batal',
        reverseButtons: true,
        focusCancel: true
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Menghapus Pengguna...',
                text: 'Sedang memproses penghapusan data terpilih.',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            document.getElementById('formBulkUsers').submit();
        }
    });
}
</script>

<?php page_end(); ?>