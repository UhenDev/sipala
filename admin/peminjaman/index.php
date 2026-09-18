<?php

/**
 * FILE       : admin/peminjaman/index.php
 * MODUL      : Manajemen Transaksi Peminjaman
 * FUNGSI     : Menampilkan daftar transaksi peminjaman, menyetujui permohonan, dan melakukan serah terima alat fisik
 * AKSES      : Administrator / Petugas Laboratorium
 *
 * WORKFLOW STATUS TRANSAKSI:
 *   MENUNGGU (Pengajuan siswa)
 *       ↓ (Disetujui laboran)
 *   DISETUJUI
 *       ↓ (Alat diserahkan + verifikasi kartu RFID / manual)
 *   DIPINJAM (Stok tersedia berkurang, status unit fisik menjadi DIPINJAM)
 *       ↓ (Pengembalian di modul pengembalian)
 *   SELESAI
 *
 * *Kondisi khusus: DITOLAK (permohonan dibatalkan/ditolak), TERLAMBAT (melewati jatuh tempo).
 *
 * ALUR:
 * 1. Memeriksa otorisasi admin/petugas.
 * 2. Menangani aksi POST persetujuan ('approve'), penolakan ('reject'), dan serah terima alat ('handover').
 * 3. Menjalankan alokasi unit fisik dalam blok transaksi database dengan locking (FOR UPDATE).
 * 4. Menyinkronkan stok master peralatan dan mencatat audit log.
 * 5. Menampilkan seluruh data transaksi dengan filter pencarian dan badge status.
 */

require_once __DIR__ . '/../includes/admin.php';

// Handle serahkan alat & persetujuan via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $act = (string)($_POST['action'] ?? '');
    $rfidTap = trim((string)($_POST['rfid_tap'] ?? ''));
    $isManual = !empty($_POST['is_manual']);

    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT id, kode_peminjaman, status, user_id, tanggal_rencana_kembali FROM peminjaman WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $p = $q->fetch();

        if (!$p) {
            throw new RuntimeException('Peminjaman tidak ditemukan.');
        }

        $kodePinjam = (string)$p['kode_peminjaman'];
        $peminjamUserId = (int)$p['user_id'];

        if ($act === 'approve' && $p['status'] === 'MENUNGGU') {
            $q = $pdo->prepare('UPDATE peminjaman SET status = "DISETUJUI", disetujui_oleh = ?, tanggal_disetujui = NOW() WHERE id = ?');
            $q->execute([(int)$_SESSION['user_id'], $id]);
            $pdo->commit();

            audit_log('APPROVE', 'PEMINJAMAN', $id, "Menyetujui pengajuan peminjaman $kodePinjam");
            send_notification($peminjamUserId, 'Pengajuan Peminjaman Disetujui!', "Pengajuan peminjaman alat lab ($kodePinjam) telah disetujui. Silakan datang ke ruang laboratorium untuk mengambil alat.", 'success', 'index.php');

            flash('success', 'Pengajuan peminjaman berhasil disetujui. Siswa dapat mengambil barang di laboratorium.');
            redirect('index.php');
        } elseif ($act === 'reject' && $p['status'] === 'MENUNGGU') {
            $alasan = trim((string)($_POST['alasan_penolakan'] ?? 'Tidak memenuhi syarat / alat sedang diperlukan untuk keperluan lain'));
            $q = $pdo->prepare('UPDATE peminjaman SET status = "DITOLAK", disetujui_oleh = ?, tanggal_disetujui = NOW(), alasan_penolakan = ? WHERE id = ?');
            $q->execute([(int)$_SESSION['user_id'], $alasan, $id]);
            $pdo->commit();

            audit_log('REJECT', 'PEMINJAMAN', $id, "Menolak pengajuan peminjaman $kodePinjam: $alasan");
            send_notification($peminjamUserId, 'Pengajuan Peminjaman Ditolak', "Pengajuan peminjaman ($kodePinjam) ditolak oleh petugas lab. Alasan: $alasan", 'danger', 'index.php');

            flash('success', 'Pengajuan peminjaman ditolak.');
            redirect('index.php');
        } elseif ($act === 'borrow' && in_array($p['status'], ['MENUNGGU', 'DISETUJUI'], true)) {
            // Ambil rincian detail peminjaman
            $d = $pdo->prepare('SELECT id, peralatan_id, jumlah FROM detail_peminjaman WHERE peminjaman_id = ?');
            $d->execute([$id]);
            $items = $d->fetchAll();

            foreach ($items as $it) {
                $alatId = (int)$it['peralatan_id'];
                $qty = (int)$it['jumlah'];

                // Alokasikan unit fisik berstatus TERSEDIA
                $stAvail = $pdo->prepare("
                    SELECT id, kode_unit 
                    FROM unit_peralatan 
                    WHERE peralatan_id = ? AND status = 'TERSEDIA' AND kondisi != 'Rusak Berat' 
                    LIMIT ? FOR UPDATE
                ");
                $stAvail->bindValue(1, $alatId, PDO::PARAM_INT);
                $stAvail->bindValue(2, $qty, PDO::PARAM_INT);
                $stAvail->execute();
                $availUnits = $stAvail->fetchAll();

                if (count($availUnits) < $qty) {
                    throw new RuntimeException("Unit fisik yang siap dipinjam tidak mencukupi untuk diserahkan (Tersedia: " . count($availUnits) . " unit).");
                }

                // Update status unit menjadi DIPINJAM dan kaitkan ke detail_peminjaman secara individual
                $stUpdUnit = $pdo->prepare("UPDATE unit_peralatan SET status = 'DIPINJAM' WHERE id = ?");
                $stUpdDet = $pdo->prepare("UPDATE detail_peminjaman SET unit_peralatan_id = ?, jumlah = 1 WHERE id = ?");
                $stInsDet = $pdo->prepare("INSERT INTO detail_peminjaman (peminjaman_id, peralatan_id, unit_peralatan_id, jumlah, kondisi_awal) VALUES (?, ?, ?, 1, 'Baik')");

                foreach ($availUnits as $idx => $u) {
                    $stUpdUnit->execute([$u['id']]);
                    if ($idx === 0) {
                        $stUpdDet->execute([$u['id'], (int)$it['id']]);
                    } else {
                        $stInsDet->execute([$id, $alatId, $u['id']]);
                    }
                }

                // Sinkronkan stok master peralatan
                sync_peralatan_stock($alatId);
            }

            if ($rfidTap !== '') {
                // Update status ke DIPINJAM dan simpan verifikasi RFID
                $stmt = $pdo->prepare('
                    UPDATE peminjaman 
                    SET status = "DIPINJAM", 
                        tanggal_pinjam = CURDATE(),
                        rfid_uid = ?,
                        rfid_verified_at = NOW(),
                        rfid_verified_by = ?
                    WHERE id = ?
                ');
                $stmt->execute([
                    $rfidTap,
                    (int)$_SESSION['user_id'],
                    $id
                ]);
            } else {
                // Serah terima manual tanpa kartu RFID
                $stmt = $pdo->prepare('
                    UPDATE peminjaman 
                    SET status = "DIPINJAM", 
                        tanggal_pinjam = CURDATE(),
                        rfid_verified_by = ?
                    WHERE id = ?
                ');
                $stmt->execute([
                    (int)$_SESSION['user_id'],
                    $id
                ]);
            }

            $pdo->commit();

            audit_log('BORROW', 'PEMINJAMAN', $id, "Menyerahkan alat untuk transaksi $kodePinjam (" . ($rfidTap !== '' ? "Verifikasi RFID: $rfidTap" : "Manual") . ")");
            send_notification($peminjamUserId, 'Alat Laboratorium Telah Diterima', "Alat untuk transaksi $kodePinjam telah diserahkan kepada Anda. Harap merawat dan mengembalikan sebelum " . date('d-m-Y', strtotime($p['tanggal_rencana_kembali'])), 'info', 'index.php');

            flash('success', 'Alat berhasil diserahkan dan unit fisik telah dialokasikan ke peminjam.');
            redirect('index.php');
        } else {
            throw new RuntimeException('Aksi tidak sesuai dengan status transaksi saat ini.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
        redirect('index.php');
    }
}

// Ambil data transaksi peminjaman
$rows = $pdo->query('
    SELECT p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_pinjam, p.tanggal_rencana_kembali, 
           p.status, p.rfid_uid, p.rfid_verified_at, p.keperluan,
           u.nama AS nama_peminjam, u.nis, u.kelas,
           COALESCE(SUM(d.jumlah), 0) AS total_item 
    FROM peminjaman p 
    LEFT JOIN users u ON u.id = p.user_id 
    LEFT JOIN detail_peminjaman d ON d.peminjaman_id = p.id 
    GROUP BY p.id, p.kode_peminjaman, p.tanggal_pengajuan, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.status, p.rfid_uid, p.rfid_verified_at, p.keperluan, u.nama, u.nis, u.kelas
    ORDER BY p.id DESC
')->fetchAll();

page_start('Peminjaman Alat Lab', 'Kelola transaksi peminjaman dan verifikasi fisik', 'peminjaman');
?>

<div class="head">
    <div>
        <h2>Data Transaksi Peminjaman</h2>
        <p>Pencatatan peminjaman alat laboratorium, persetujuan pengajuan, dan verifikasi fisik serah terima.</p>
    </div>
    <div class="actions">
        <a class="btn btn-primary" href="tambah.php">+ Peminjaman Baru (Scan RFID / Manual)</a>
    </div>
</div>

<div class="panel">
    <div class="panel-title">
        <span>Daftar Transaksi Peminjaman</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:40px;">No</th>
                    <th>Kode Transaksi</th>
                    <th>Peminjam (Siswa)</th>
                    <th>Tgl Pengajuan / Pinjam</th>
                    <th>Rencana Kembali</th>
                    <th>Total Item</th>
                    <th>Metode Verifikasi</th>
                    <th>Status</th>
                    <th style="text-align:right;">Tindakan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty">Belum ada catatan transaksi peminjaman.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <strong style="font-family:monospace;color:#1e40af;"><?= e($r['kode_peminjaman']) ?></strong>
                                <?php if (!empty($r['keperluan'])): ?>
                                    <div style="font-size:11px;color:#64748b;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($r['keperluan']) ?>">
                                        <?= e($r['keperluan']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><strong><?= e($r['nama_peminjam'] ?? '-') ?></strong></div>
                                <div style="font-size:12px;color:#64748b;">
                                    NIS: <?= e($r['nis'] ?: '-') ?> &bull; <?= e($r['kelas'] ?: '-') ?>
                                </div>
                            </td>
                            <td>
                                <div><?= e(date('d-m-Y', strtotime($r['tanggal_pinjam'] ?: ($r['tanggal_pengajuan'] ?: 'now')))) ?></div>
                            </td>
                            <td>
                                <div style="color:#d97706;font-weight:600;">
                                    <?= e(date('d-m-Y', strtotime($r['tanggal_rencana_kembali']))) ?>
                                </div>
                            </td>
                            <td>
                                <span style="font-weight:700;font-size:14px;"><?= $r['total_item'] ?></span> unit
                            </td>
                            <td>
                                <?php if (!empty($r['rfid_verified_at']) && !empty($r['rfid_uid'])): ?>
                                    <span class="badge aktif" title="Diverifikasi chip RFID: <?= e($r['rfid_uid']) ?>">
                                        RFID Terverifikasi
                                    </span>
                                <?php elseif (in_array($r['status'], ['DIPINJAM', 'SELESAI'], true)): ?>
                                    <span class="badge nonaktif" title="Serah terima fisik tanpa kartu RFID">
                                        Manual (Tanpa Kartu)
                                    </span>
                                <?php elseif ($r['status'] === 'DISETUJUI'): ?>
                                    <span class="badge DISETUJUI">
                                        Siap Serah Terima
                                    </span>
                                <?php else: ?>
                                    <span class="badge nonaktif">
                                        Belum Diserahkan
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= status_badge($r['status']) ?>
                            </td>
                            <td style="text-align:right;">
                                <div class="actions" style="justify-content:flex-end;">
                                    <a class="btn btn-sm" href="detail.php?id=<?= $r['id'] ?>">Detail</a>
                                    
                                    <?php if ($r['status'] === 'MENUNGGU'): ?>
                                        <form method="post" id="form_approve_<?= $r['id'] ?>" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="button" class="btn btn-sm btn-ok" onclick="confirmAction('form_approve_<?= $r['id'] ?>', 'Setujui Pengajuan?', 'Setujui pengajuan peminjaman untuk <?= e(addslashes($r['nama_peminjam'] ?? '')) ?>?', 'Ya, Setujui', '#10b981')">Setujui</button>
                                        </form>
                                        <form method="post" id="form_reject_<?= $r['id'] ?>" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="button" class="btn btn-sm btn-danger" onclick="confirmAction('form_reject_<?= $r['id'] ?>', 'Tolak Pengajuan?', 'Tolak pengajuan peminjaman untuk <?= e(addslashes($r['nama_peminjam'] ?? '')) ?>?', 'Ya, Tolak', '#ef4444')">Tolak</button>
                                        </form>
                                    <?php elseif (in_array($r['status'], ['DISETUJUI', 'SIAP_DIAMBIL'], true)): ?>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openHandoverModal(<?= $r['id'] ?>, '<?= e($r['kode_peminjaman']) ?>', '<?= e(addslashes($r['nama_peminjam'] ?? 'Siswa')) ?>')">
                                            Serahkan Alat
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
</div>

<!-- MODAL SERAH TERIMA ALAT (RFID ATAU MANUAL) -->
<div class="modal" id="handoverModal">
    <div class="modal-box" style="max-width:520px;">
        <h3>Konfirmasi Penyerahan Fisik Alat</h3>
        <p id="handoverDesc" style="margin-bottom:14px;color:#64748b;font-size:13px;"></p>
        
        <form method="post" id="handoverForm">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="handoverId">
            <input type="hidden" name="action" value="borrow">
            
            <!-- Pilihan Metode Serah Terima -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:16px;">
                <div style="font-weight:700;font-size:13px;color:#1e293b;margin-bottom:8px;">Opsi 1: Verifikasi Tap Kartu Smart RFID (Direkomendasikan)</div>
                <div class="field" style="margin-bottom:10px;">
                    <label>Tempelkan Kartu Siswa ke Reader RFID:</label>
                    <input type="password" name="rfid_tap" id="modalRfidInput" placeholder="Tempelkan kartu RFID di sini..." 
                           data-rfid-scanner="true" autocomplete="off" style="font-family:monospace;font-size:15px;background:#ffffff;">
                    <small style="color:#64748b;">Arahkan kursor ke kolom ini lalu tempelkan kartu RFID siswa.</small>
                </div>
            </div>

            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:16px;">
                <div style="font-weight:700;font-size:13px;color:#1e293b;margin-bottom:6px;">Opsi 2: Serah Terima Manual (Siswa Tanpa Kartu RFID)</div>
                <p style="font-size:12px;color:#64748b;margin:0 0 10px 0;">Gunakan opsi ini jika siswa tidak membawa kartu smart card, belum memiliki chip RFID, atau tamu lab.</p>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#334155;cursor:pointer;">
                    <input type="checkbox" name="is_manual" id="manualCheck" value="1" onchange="toggleManualHandover(this)">
                    <span>Konfirmasi siswa telah menerima barang secara fisik (Verifikasi Manual)</span>
                </label>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn" onclick="document.getElementById('handoverModal').classList.remove('show')">Batal</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitHandover">Konfirmasi Serah Terima</button>
            </div>
        </form>
    </div>
</div>

<script>
function openHandoverModal(id, kode, nama) {
    document.getElementById('handoverId').value = id;
    document.getElementById('handoverDesc').textContent = 'Konfirmasi serah terima fisik barang untuk transaksi ' + kode + ' atas nama ' + nama + '.';
    document.getElementById('modalRfidInput').value = '';
    document.getElementById('manualCheck').checked = false;
    document.getElementById('handoverModal').classList.add('show');
    setTimeout(() => {
        const inp = document.getElementById('modalRfidInput');
        if (inp) inp.focus();
    }, 200);
}

function toggleManualHandover(chk) {
    const rfidInput = document.getElementById('modalRfidInput');
    if (chk.checked) {
        rfidInput.value = '';
        rfidInput.placeholder = '(Mode manual aktif - tanpa kartu RFID)';
        rfidInput.disabled = true;
    } else {
        rfidInput.disabled = false;
        rfidInput.placeholder = 'Tempelkan kartu RFID di sini...';
        rfidInput.focus();
    }
}
</script>

<?php page_end(); ?>