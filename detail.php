<?php
declare(strict_types=1);
session_name('sipala_session');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/database.php';
$pdo = db();

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'peminjam') {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('
    SELECT p.*, u.nama, u.nis, u.kelas
    FROM peminjaman p
    JOIN users u ON u.id = p.user_id
    WHERE p.id = ? AND p.user_id = ?
');
$stmt->execute([$id, $userId]);
$loan = $stmt->fetch();

if (!$loan) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare('
    SELECT d.jumlah, d.kondisi_awal, d.kondisi_akhir, d.catatan_kondisi,
           pr.kode_barang, pr.nama_barang, k.nama_kategori
    FROM detail_peminjaman d
    JOIN peralatan pr ON pr.id = d.peralatan_id
    LEFT JOIN kategori k ON k.id = pr.kategori_id
    WHERE d.peminjaman_id = ?
    ORDER BY d.id
');
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$statusClass = (string)$loan['status'];
$statusText = [
    'MENUNGGU' => 'Menunggu persetujuan',
    'DISETUJUI' => 'Pengajuan disetujui',
    'DITOLAK' => 'Pengajuan ditolak',
    'DIPINJAM' => 'Sedang dipinjam',
    'MENUNGGU_PENGEMBALIAN' => 'Menunggu pengembalian',
    'SELESAI' => 'Selesai',
][$statusClass] ?? $statusClass;
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Detail Peminjaman - SIPALA</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,Roboto,sans-serif;background:#f8fafc;color:#0f172a}.navbar{background:#fff;border-bottom:1px solid #e2e8f0;padding:16px 32px;display:flex;justify-content:space-between;align-items:center}.brand{font-weight:800;font-size:20px;color:#1d4ed8}.wrap{max-width:900px;margin:30px auto;padding:0 20px}.panel{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;margin-bottom:18px}.btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 15px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;text-decoration:none;color:#334155;font-weight:600;font-size:13px}.primary{background:#2563eb;color:#fff;border-color:#2563eb}.status{display:inline-flex;padding:5px 10px;border-radius:20px;font-size:11px;font-weight:700;background:#fef3c7;color:#b45309}.status.DISETUJUI{background:#dbeafe;color:#1d4ed8}.status.DITOLAK{background:#fee2e2;color:#b91c1c}.status.DIPINJAM{background:#e0f2fe;color:#0369a1}.status.SELESAI{background:#dcfce7;color:#15803d}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{padding:12px;background:#f8fafc;border-radius:9px}.field small{display:block;color:#64748b;font-size:11px;margin-bottom:3px}.field strong{font-size:14px}.item{padding:14px 0;border-bottom:1px solid #f1f5f9}.item:last-child{border-bottom:0}@media(max-width:650px){.navbar{padding:12px 16px}.wrap{margin:20px auto}.panel{padding:18px}.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<nav class="navbar"><div class="brand">📦 SIPALA</div><a class="btn" href="index.php">← Kembali</a></nav>
<main class="wrap">
<div class="panel">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">
        <div><div style="font-size:12px;color:#64748b">Kode Peminjaman</div><h1 style="font-size:22px;margin:2px 0 5px"><?=e($loan['kode_peminjaman'])?></h1></div>
        <span class="status <?=$statusClass?>"><?=e($statusText)?></span>
    </div>
    <?php if ($statusClass === 'DITOLAK' && !empty($loan['alasan_penolakan'])): ?>
        <div style="margin-top:16px;padding:12px 14px;border-radius:9px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:13px"><strong>Alasan penolakan:</strong> <?=e($loan['alasan_penolakan'])?></div>
    <?php endif; ?>
</div>
<div class="panel">
    <h3 style="margin:0 0 16px">Jadwal & Keperluan</h3>
    <div class="grid">
        <div class="field"><small>Tanggal pengajuan</small><strong><?=e(date('d-m-Y',strtotime($loan['tanggal_pengajuan'])))?></strong></div>
        <div class="field"><small>Tanggal pinjam</small><strong><?=e(date('d-m-Y',strtotime($loan['tanggal_pinjam'])))?></strong></div>
        <div class="field"><small>Rencana kembali</small><strong><?=e(date('d-m-Y',strtotime($loan['tanggal_rencana_kembali'])))?></strong></div>
        <div class="field"><small>Realisasi kembali</small><strong><?=!empty($loan['tanggal_realisasi_kembali'])?e(date('d-m-Y',strtotime($loan['tanggal_realisasi_kembali']))):'-'?></strong></div>
    </div>
    <div style="margin-top:14px;padding:12px;background:#f8fafc;border-radius:9px;font-size:13px"><strong>Keperluan:</strong> <?=e($loan['keperluan'])?></div>
</div>
<div class="panel">
    <h3 style="margin:0 0 8px">Alat yang Dipinjam</h3>
    <?php foreach($items as $it): ?>
        <div class="item">
            <div style="display:flex;justify-content:space-between;gap:12px"><strong><?=e($it['nama_barang'])?></strong><strong><?=e((string)$it['jumlah'])?> unit</strong></div>
            <div style="font-size:12px;color:#64748b;margin-top:3px"><?=e($it['kode_barang'])?> · <?=e($it['nama_kategori']??'-')?></div>
            <div style="font-size:12px;color:#64748b;margin-top:7px">Kondisi awal: <?=e($it['kondisi_awal'])?><?php if($it['kondisi_akhir']): ?> · Kondisi akhir: <?=e($it['kondisi_akhir'])?><?php endif; ?></div>
            <?php if(!empty($it['catatan_kondisi'])): ?><div style="font-size:12px;color:#475569;margin-top:4px">Catatan: <?=e($it['catatan_kondisi'])?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php if (!empty($loan['catatan'])): ?><div class="panel"><strong>Catatan petugas</strong><p style="margin:8px 0 0;color:#475569;font-size:13px"><?=e($loan['catatan'])?></p></div><?php endif; ?>
<?php if (in_array($statusClass,['SELESAI','DITOLAK'],true)): ?><div style="text-align:right"><a class="btn primary" href="ajukan.php">📦 Ajukan Peminjaman Lagi</a></div><?php endif; ?>
</main>
</body>
</html>
