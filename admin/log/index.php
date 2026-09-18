<?php

/**
 * FILE       : admin/log/index.php
 * MODUL      : Audit Trail & Log Aktivitas
 * FUNGSI     : Menampilkan riwayat jejak audit (audit log) seluruh tindakan pengguna di dalam sistem SIPALA
 * AKSES      : Administrator / Petugas Laboratorium
 *
 * ALUR:
 * 1. Memeriksa otorisasi admin/petugas.
 * 2. Mengambil filter modul (PEMINJAMAN, PENGEMBALIAN, USER, PERALATAN, UNIT) dan aksi (CREATE, UPDATE, DELETE, RETURN, LOGIN).
 * 3. Mengambil data dari tabel `log_aktivitas` yang berelasi dengan tabel `users`.
 * 4. Menyajikan riwayat jejak audit lengkap dengan IP address, detail perubahan, dan stempel waktu akurat.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

// Filter
$filterModul = trim((string)($_GET['modul'] ?? ''));
$filterAksi = trim((string)($_GET['aksi'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$sql = "
    SELECT l.*, u.nama AS nama_user, u.username, u.role AS role_user
    FROM log_aktivitas l
    LEFT JOIN users u ON u.id = l.user_id
    WHERE 1=1
";
$params = [];

if ($filterModul !== '') {
    $sql .= " AND l.modul = ?";
    $params[] = $filterModul;
}

if ($filterAksi !== '') {
    $sql .= " AND l.aksi = ?";
    $params[] = $filterAksi;
}

if ($search !== '') {
    $sql .= " AND (l.deskripsi LIKE ? OR l.ip_address LIKE ? OR u.nama LIKE ? OR u.username LIKE ?)";
    $t = '%' . $search . '%';
    $params = array_merge($params, [$t, $t, $t, $t]);
}

$sql .= " ORDER BY l.id DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Modules list for filter dropdown
$modulesList = $pdo->query("SELECT DISTINCT modul FROM log_aktivitas WHERE modul IS NOT NULL AND modul != '' ORDER BY modul ASC")->fetchAll(PDO::FETCH_COLUMN);

page_start('Audit Log', 'Jejak rekam aktivitas sistem laboratorium', 'log');
?>

<div class="head">
    <div>
        <h2>Jejak Rekam Aktivitas (Audit Trail)</h2>
        <p>Log audit lengkap mencatat setiap perubahan data, persetujuan sirkulasi, otentikasi pengguna, dan inventaris.</p>
    </div>
</div>

<div class="panel" style="margin-bottom:20px;">
    <form method="GET" action="index.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="field" style="flex:1;min-width:220px;">
            <label>Cari Aktivitas / Pengguna / IP</label>
            <input type="text" name="q" placeholder="Cari deskripsi aksi, operator, IP..." value="<?= e($search) ?>">
        </div>

        <div class="field" style="min-width:160px;">
            <label>Filter Modul</label>
            <select name="modul">
                <option value="">-- Semua Modul --</option>
                <?php foreach ($modulesList as $m): ?>
                    <option value="<?= e($m) ?>" <?= $filterModul === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex;gap:6px;">
            <button type="submit" class="btn btn-primary" style="height:38px;">Filter</button>
            <?php if ($filterModul || $filterAksi || $search): ?>
                <a href="index.php" class="btn" style="height:38px;">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-title">Riwayat Log Aktivitas (Menampilkan <?= count($logs) ?> rekaman terbaru)</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:50px;">ID</th>
                    <th>Waktu (WIB)</th>
                    <th>Pengguna / Pelaku</th>
                    <th>Modul</th>
                    <th>Aksi</th>
                    <th>Deskripsi Aktivitas</th>
                    <th>Alamat IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="7"><div class="empty">Belum ada rekaman audit log yang sesuai.</div></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td style="font-family:monospace;font-size:12px;color:#94a3b8;">#<?= $l['id'] ?></td>
                            <td style="font-size:12px;white-space:nowrap;color:#475569;">
                                <?= e(date('d-m-Y H:i:s', strtotime($l['created_at']))) ?>
                            </td>
                            <td>
                                <?php if (!empty($l['nama_user'])): ?>
                                    <strong><?= e($l['nama_user']) ?></strong>
                                    <span style="display:block;font-size:11px;color:#64748b;">@<?= e($l['username']) ?> (<?= e($l['role_user']) ?>)</span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-style:italic;">Sistem / Tamu</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;font-family:monospace;">
                                    <?= e($l['modul'] ?: 'SYSTEM') ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight:700;font-size:11px;color:#1e40af;font-family:monospace;">
                                    <?= e($l['aksi'] ?: ($l['aktivitas'] ?? 'INFO')) ?>
                                </span>
                            </td>
                            <td style="font-size:13px;line-height:1.4;color:#1e293b;">
                                <?= e($l['deskripsi']) ?>
                                <?php if (!empty($l['reference_id'])): ?>
                                    <span style="font-size:10px;color:#64748b;font-family:monospace;">[Ref #<?= (int)$l['reference_id'] ?>]</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-family:monospace;font-size:11px;color:#64748b;">
                                <?= e($l['ip_address'] ?: '127.0.0.1') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php page_end(); ?>
