<?php
declare(strict_types=1);

/**
 * FILE       : admin/laporan/index.php
 * MODUL      : Laporan & Rekapitulasi Sirkulasi Peminjaman Lab RPL
 * FUNGSI     : Menyajikan data sirkulasi peminjaman dengan filter komprehensif, format cetak fisik resmi, dan ekspor spreadsheet CSV
 * AKSES      : Administrator / Petugas Laboratorium
 */

require_once __DIR__ . '/../includes/admin.php';

// Ambil konfigurasi instansi dari database
$namaInstansi = (string)($pdo->query("SELECT nilai FROM pengaturan WHERE nama = 'nama_instansi'")->fetchColumn() ?: 'SMKN 1 PANYINGKIRAN');
$namaLab = (string)($pdo->query("SELECT nilai FROM pengaturan WHERE nama = 'lokasi_ruang_lab'")->fetchColumn() ?: 'Lab Komputer RPL');

// Filter parameter
$tglMulai = trim((string)($_GET['tgl_mulai'] ?? date('Y-m-01')));
$tglSelesai = trim((string)($_GET['tgl_selesai'] ?? date('Y-m-d')));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterKelas = trim((string)($_GET['kelas'] ?? ''));
$filterPeralatan = (int)($_GET['peralatan_id'] ?? 0);
$export = trim((string)($_GET['export'] ?? ''));

$sql = "
    SELECT p.id, p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali, p.tanggal_realisasi_kembali,
           p.status, p.keperluan,
           u.nama AS nama_siswa, u.nis, u.kelas,
           COALESCE(pr.nama_barang, d.nama_barang_snapshot, 'Alat Terhapus') AS nama_barang,
           COALESCE(pr.kode_barang, d.kode_barang_snapshot, '-') AS kode_barang,
           COALESCE(up.kode_unit, d.kode_unit_snapshot, '-') AS kode_unit,
           COALESCE(up.barcode, d.barcode_snapshot, '-') AS barcode,
           COALESCE(up.serial_number, '-') AS serial_number,
           d.jumlah, d.kondisi_awal, d.kondisi_akhir,
           COALESCE(pg.denda, 0) AS denda,
           COALESCE(pg.status_denda, 'LUNAS') AS status_denda,
           pg.tanggal_pengembalian
    FROM peminjaman p
    JOIN users u ON u.id = p.user_id
    JOIN detail_peminjaman d ON d.peminjaman_id = p.id
    LEFT JOIN peralatan pr ON pr.id = d.peralatan_id
    LEFT JOIN unit_peralatan up ON up.id = d.unit_peralatan_id
    LEFT JOIN pengembalian pg ON pg.peminjaman_id = p.id
    WHERE 1=1
";
$params = [];

if ($tglMulai !== '') {
    $sql .= " AND p.tanggal_pinjam >= ?";
    $params[] = $tglMulai;
}
if ($tglSelesai !== '') {
    $sql .= " AND p.tanggal_pinjam <= ?";
    $params[] = $tglSelesai;
}
if ($filterStatus !== '') {
    $sql .= " AND p.status = ?";
    $params[] = $filterStatus;
}
if ($filterKelas !== '') {
    $sql .= " AND u.kelas = ?";
    $params[] = $filterKelas;
}
if ($filterPeralatan > 0) {
    $sql .= " AND pr.id = ?";
    $params[] = $filterPeralatan;
}

$sql .= " ORDER BY p.tanggal_pinjam DESC, p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// =========================================================================
// EKSPOR CSV UNTUK MICROSOFT EXCEL
// =========================================================================
if ($export === 'csv') {
    $fileName = 'Laporan_Peminjaman_SIPALA_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');

    // Output UTF-8 BOM agar terbaca sempurna di Microsoft Excel
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'No',
        'Kode Peminjaman',
        'Nama Siswa',
        'NIS',
        'Kelas',
        'Nama Peralatan',
        'Kode Unit Fisik',
        'Barcode Unit',
        'Nomor Seri',
        'Jumlah Unit',
        'Tgl Pinjam',
        'Tgl Rencana Kembali',
        'Tgl Realisasi Kembali',
        'Status Sirkulasi',
        'Denda (Rp)',
        'Status Denda',
        'Keperluan'
    ]);

    $no = 1;
    $escCsv = static function(mixed $val): string {
        $str = (string)($val ?? '');
        if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', '\t', '\r'], true)) {
            return "'" . $str;
        }
        return $str;
    };

    foreach ($rows as $r) {
        fputcsv($out, [
            $no++,
            $escCsv($r['kode_peminjaman']),
            $escCsv($r['nama_siswa']),
            $escCsv($r['nis'] ?: '-'),
            $escCsv($r['kelas'] ?: '-'),
            $escCsv($r['nama_barang']),
            $escCsv($r['kode_unit']),
            $escCsv($r['barcode']),
            $escCsv($r['serial_number']),
            (int)$r['jumlah'],
            $escCsv($r['tanggal_pinjam']),
            $escCsv($r['tanggal_rencana_kembali']),
            $escCsv($r['tanggal_realisasi_kembali'] ?: ($r['tanggal_pengembalian'] ?: '-')),
            $escCsv($r['status']),
            (float)$r['denda'],
            $escCsv(((float)$r['denda'] > 0) ? $r['status_denda'] : 'TANPA DENDA'),
            $escCsv($r['keperluan'])
        ]);
    }
    fclose($out);
    exit;
}

// Ambil daftar alat & kelas untuk opsi dropdown filter
$equipmentList = $pdo->query("SELECT id, nama_barang FROM peralatan ORDER BY nama_barang ASC")->fetchAll();
$classList = $pdo->query("SELECT DISTINCT kelas FROM users WHERE kelas IS NOT NULL AND kelas != '' ORDER BY kelas ASC")->fetchAll(PDO::FETCH_COLUMN);

$totalTransaksi = count($rows);
$totalUnitDipinjam = array_sum(array_map(fn($r) => (int)$r['jumlah'], $rows));
$totalDendaTercatat = array_sum(array_map(fn($r) => (float)$r['denda'], $rows));

page_start('Laporan Sirkulasi', 'Rekapitulasi sirkulasi inventaris Lab Komputer RPL', 'laporan');
?>

<style>
@media print {
    body { background: #ffffff !important; color: #000000 !important; }
    .sidebar, .topbar, .filter-panel, .head .actions, .sidebar-overlay { display: none !important; }
    .main { margin: 0 !important; width: 100% !important; }
    .content { padding: 0 !important; }
    .panel { border: none !important; box-shadow: none !important; }
    .print-header { display: block !important; margin-bottom: 24px; text-align: center; border-bottom: 2px solid #000; padding-bottom: 12px; }
    .print-signature { display: flex !important; justify-content: space-between; margin-top: 50px; page-break-inside: avoid; }
    table { width: 100% !important; border-collapse: collapse !important; font-size: 11px !important; }
    th, td { border: 1px solid #333 !important; padding: 6px 8px !important; color: #000 !important; }
    th { background: #f0f0f0 !important; }
    .badge { border: none !important; background: transparent !important; color: #000 !important; font-weight: bold !important; padding: 0 !important; }
}
.print-header { display: none; }
.print-signature { display: none; }
</style>

<!-- HEADER CETAK RESMI (HANYA MUNCUL SAAT DI-PRINT) -->
<div class="print-header">
    <h3 style="margin:0;font-size:18px;text-transform:uppercase;"><?= e($namaInstansi) ?></h3>
    <h2 style="margin:4px 0;font-size:20px;"><?= e($namaLab) ?></h2>
    <p style="margin:2px 0 0 0;font-size:13px;">Laporan Rekapitulasi Sirkulasi Peminjaman Peralatan Laboratorium</p>
    <p style="margin:2px 0 0 0;font-size:11px;color:#555;">Periode: <?= e(format_tanggal_indonesia($tglMulai)) ?> s/d <?= e(format_tanggal_indonesia($tglSelesai)) ?></p>
</div>

<div class="head">
    <div>
        <h2>Laporan Sirkulasi Peminjaman</h2>
        <p>Rekapitulasi transaksi peminjaman, tracking unit fisik, dan status denda keterlambatan.</p>
    </div>
    <div class="actions">
        <a href="index.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn" title="Download Spreadsheet CSV">
            📥 Ekspor CSV / Excel
        </a>
        <button type="button" class="btn btn-primary" onclick="window.print()" title="Cetak Format Fisik">
            🖨️ Cetak Laporan
        </button>
    </div>
</div>

<!-- STATS RINGKASAN LAPORAN -->
<div class="stats">
    <div class="stat">
        <small>Total Transaksi</small>
        <strong><?= number_format($totalTransaksi) ?></strong>
    </div>
    <div class="stat">
        <small>Total Unit Alat Beredar</small>
        <strong><?= number_format($totalUnitDipinjam) ?> unit</strong>
    </div>
    <div class="stat">
        <small>Total Denda Tercatat</small>
        <strong style="color:<?= $totalDendaTercatat > 0 ? '#dc2626' : '#15803d' ?>;"><?= format_rupiah($totalDendaTercatat) ?></strong>
    </div>
</div>

<!-- FORM FILTER LAPORAN -->
<div class="panel filter-panel" style="margin-bottom:20px;">
    <div class="panel-title">
        <span>Filter Parameter Laporan</span>
        <a href="index.php" style="font-size:12px;color:#1769AA;text-decoration:none;">Reset Filter</a>
    </div>
    <div style="padding:18px 20px;">
        <form method="GET" action="index.php" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;align-items:flex-end;">
            <div class="field">
                <label>Dari Tanggal</label>
                <input type="date" name="tgl_mulai" value="<?= e($tglMulai) ?>">
            </div>
            <div class="field">
                <label>Sampai Tanggal</label>
                <input type="date" name="tgl_selesai" value="<?= e($tglSelesai) ?>">
            </div>
            <div class="field">
                <label>Status Sirkulasi</label>
                <select name="status">
                    <option value="">-- Semua Status --</option>
                    <option value="MENUNGGU" <?= $filterStatus === 'MENUNGGU' ? 'selected' : '' ?>>MENUNGGU</option>
                    <option value="DISETUJUI" <?= $filterStatus === 'DISETUJUI' ? 'selected' : '' ?>>DISETUJUI</option>
                    <option value="DIPINJAM" <?= $filterStatus === 'DIPINJAM' ? 'selected' : '' ?>>SEDANG DIPINJAM</option>
                    <option value="SELESAI" <?= $filterStatus === 'SELESAI' ? 'selected' : '' ?>>SELESAI</option>
                    <option value="DITOLAK" <?= $filterStatus === 'DITOLAK' ? 'selected' : '' ?>>DITOLAK</option>
                    <option value="DIBATALKAN" <?= $filterStatus === 'DIBATALKAN' ? 'selected' : '' ?>>DIBATALKAN</option>
                </select>
            </div>
            <div class="field">
                <label>Kelas Siswa</label>
                <select name="kelas">
                    <option value="">-- Semua Kelas --</option>
                    <?php foreach ($classList as $k): ?>
                        <option value="<?= e($k) ?>" <?= $filterKelas === $k ? 'selected' : '' ?>><?= e($k) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Peralatan Laboratorium</label>
                <select name="peralatan_id">
                    <option value="">-- Semua Alat --</option>
                    <?php foreach ($equipmentList as $eq): ?>
                        <option value="<?= (int)$eq['id'] ?>" <?= $filterPeralatan === (int)$eq['id'] ? 'selected' : '' ?>>
                            <?= e($eq['nama_barang']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary" style="flex:1;min-height:40px;">Terapkan Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- TABEL REKAPITULASI LAPORAN -->
<div class="panel">
    <div class="panel-title">
        <span>Data Rekapitulasi Sirkulasi (<?= $totalTransaksi ?> data ditemukan)</span>
        <span style="font-size:12px;color:#64748b;font-weight:normal;">Periode: <?= e(date('d/m/Y', strtotime($tglMulai))) ?> - <?= e(date('d/m/Y', strtotime($tglSelesai))) ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:40px;">No</th>
                    <th>Kode Transaksi</th>
                    <th>Siswa / Peminjam</th>
                    <th>Peralatan Lab</th>
                    <th>Unit Fisik</th>
                    <th>Tgl Pinjam</th>
                    <th>Batas Kembali</th>
                    <th>Realisasi Kembali</th>
                    <th>Status</th>
                    <th>Denda</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="10" class="empty">Tidak ada data peminjaman yang sesuai dengan filter yang dipilih.</td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; foreach ($rows as $r): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <strong><?= e($r['kode_peminjaman']) ?></strong>
                            </td>
                            <td>
                                <div><strong><?= e($r['nama_siswa']) ?></strong></div>
                                <div style="font-size:11.5px;color:#64748b;">
                                    NIS: <?= e($r['nis'] ?: '-') ?> | <?= e($r['kelas'] ?: '-') ?>
                                </div>
                            </td>
                            <td>
                                <div><strong><?= e($r['nama_barang']) ?></strong></div>
                                <div style="font-size:11.5px;color:#64748b;">Jumlah: <?= (int)$r['jumlah'] ?> unit</div>
                            </td>
                            <td>
                                <div><code style="font-size:11.5px;color:#1e40af;"><?= e($r['kode_unit']) ?></code></div>
                                <?php if (!empty($r['barcode']) && $r['barcode'] !== '-'): ?>
                                    <div style="font-size:10.5px;color:#047857;font-family:monospace;font-weight:600;"><?= e($r['barcode']) ?></div>
                                <?php endif; ?>
                                <div style="font-size:10.5px;color:#64748b;">SN: <?= e($r['serial_number']) ?></div>
                            </td>
                            <td><?= e(date('d/m/Y', strtotime($r['tanggal_pinjam']))) ?></td>
                            <td><?= e(date('d/m/Y', strtotime($r['tanggal_rencana_kembali']))) ?></td>
                            <td>
                                <?php if (!empty($r['tanggal_realisasi_kembali']) || !empty($r['tanggal_pengembalian'])): ?>
                                    <span style="color:#059669;font-weight:600;">
                                        <?= e(date('d/m/Y', strtotime($r['tanggal_realisasi_kembali'] ?: $r['tanggal_pengembalian']))) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= status_badge($r['status']) ?>
                            </td>
                            <td>
                                <?php if ((float)$r['denda'] > 0): ?>
                                    <div style="color:#dc2626;font-weight:700;font-size:12px;">
                                        <?= format_rupiah((float)$r['denda']) ?>
                                    </div>
                                    <span class="badge <?= $r['status_denda'] === 'LUNAS' ? 'SELESAI' : 'DITOLAK' ?>" style="font-size:9.5px;padding:2px 5px;">
                                        <?= e($r['status_denda']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#64748b;font-size:12px;">Rp 0</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- TANDA TANGAN CETAK FISIK -->
<div class="print-signature">
    <div style="width:250px;text-align:center;">
        <p style="margin-bottom:60px;">Mengetahui,<br><strong>Kepala Program Keahlian RPL</strong></p>
        <p style="margin:0;text-decoration:underline;font-weight:bold;">(....................................................)</p>
        <p style="margin:2px 0 0 0;font-size:11px;">NIP. -</p>
    </div>
    <div style="width:250px;text-align:center;">
        <p style="margin-bottom:60px;">Dicetak pada: <?= date('d F Y') ?><br><strong>Petugas Laboran Lab RPL</strong></p>
        <p style="margin:0;text-decoration:underline;font-weight:bold;"><?= e($_SESSION['nama'] ?? 'Petugas Laboratorium') ?></p>
        <p style="margin:2px 0 0 0;font-size:11px;">Laboran Komputer RPL</p>
    </div>
</div>

<?php page_end(); ?>
