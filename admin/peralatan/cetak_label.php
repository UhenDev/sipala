<?php
/**
 * FILE       : admin/peralatan/cetak_label.php
 * MODUL      : Cetak Label Stiker Master Peralatan Laboratorium
 * FUNGSI     : Menghasilkan stiker QR Code untuk ditempel di rak/etalase master alat
 * AKSES      : Administrator / Petugas Laboratorium
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/qrcode.php';

$alatId = (int)($_GET['id'] ?? 0);
$namaInstansi = (string)($pdo->query("SELECT nilai FROM pengaturan WHERE nama = 'nama_instansi'")->fetchColumn() ?: 'SMK NEGERI 1 PANYINGKIRAN');
$namaLab = (string)($pdo->query("SELECT nilai FROM pengaturan WHERE nama = 'lokasi_ruang_lab'")->fetchColumn() ?: 'Lab Komputer RPL');

$sql = "
    SELECT p.*, k.nama_kategori,
           COUNT(u.id) AS total_unit,
           COALESCE(SUM(CASE WHEN u.kondisi = 'Baik' THEN 1 ELSE 0 END), 0) AS unit_baik
    FROM peralatan p
    LEFT JOIN kategori k ON k.id = p.kategori_id
    LEFT JOIN unit_peralatan u ON u.peralatan_id = p.id
    WHERE 1=1
";
$params = [];
if ($alatId > 0) {
    $sql .= " AND p.id = ?";
    $params[] = $alatId;
}
$sql .= " GROUP BY p.id ORDER BY p.nama_barang ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Label QR Master Alat - SIPALA</title>
    <link rel="icon" type="image/jpeg" href="../../assets/img/logo.jpg?v=2">
    <style>
        * { box-sizing: border-box; }
        body {
            background-color: #f1f5f9;
            color: #0f172a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 0;
        }
        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: #1e293b;
            color: #ffffff;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            border: none;
        }
        .btn-print { background: #2563eb; color: #ffffff; }
        .btn-back { background: #475569; color: #ffffff; }

        .sheet-container {
            max-width: 1080px;
            margin: 24px auto;
            background: #ffffff;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border-radius: 8px;
        }
        .labels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 14px;
        }
        .label-card {
            border: 1.5px dashed #cbd5e1;
            border-radius: 8px;
            padding: 14px;
            background: #ffffff;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .label-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }
        .label-school {
            font-size: 10.5px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
        }
        .label-badge {
            font-size: 9px;
            font-weight: 700;
            background: #0f172a;
            color: #ffffff;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .label-body {
            display: flex;
            gap: 12px;
            align-items: center;
            margin: 8px 0;
        }
        .label-qr-box {
            width: 86px;
            height: 86px;
            flex-shrink: 0;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 4px;
        }
        .label-item-name {
            font-size: 13.5px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.3;
        }
        .label-code {
            font-family: monospace;
            font-size: 13px;
            font-weight: 700;
            color: #2563eb;
            margin: 2px 0;
        }
        .label-meta {
            font-size: 10px;
            color: #64748b;
        }
        .label-footer {
            border-top: 1px solid #f1f5f9;
            padding-top: 6px;
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            color: #94a3b8;
        }

        @media print {
            body { background: #ffffff !important; color: #000000 !important; }
            .print-toolbar { display: none !important; }
            .sheet-container { box-shadow: none !important; border-radius: 0 !important; padding: 0 !important; margin: 0 !important; }
            .label-card { border: 1px dashed #94a3b8 !important; padding: 10px !important; }
            @page { size: A4 portrait; margin: 8mm 8mm; }
        }
    </style>
</head>
<body>

<div class="print-toolbar">
    <div style="display:flex;align-items:center;gap:12px;">
        <a href="index.php" class="btn-action btn-back">← Kembali ke Master Alat</a>
        <div style="font-weight:700;font-size:14.5px;">Cetak Stiker QR Master Peralatan (<?= count($items) ?> alat)</div>
    </div>
    <button type="button" class="btn-action btn-print" onclick="window.print()">
        🖨️ Cetak Stiker Sekarang
    </button>
</div>

<div class="sheet-container">
    <div class="labels-grid">
        <?php foreach ($items as $it): ?>
            <?php
                $scanUrl = get_alat_scan_url((string)$it['kode_barang']);
            ?>
            <div class="label-card">
                <div class="label-header">
                    <span class="label-school"><?= e($namaInstansi) ?></span>
                    <span class="label-badge"><?= e($namaLab) ?></span>
                </div>
                <div class="label-body">
                    <div class="label-qr-box">
                        <?= render_qr_svg($scanUrl, 78, 1) ?>
                    </div>
                    <div>
                        <div class="label-item-name"><?= e($it['nama_barang']) ?></div>
                        <div class="label-code"><?= e($it['kode_barang']) ?></div>
                        <div class="label-meta">Kategori: <?= e($it['nama_kategori'] ?? 'Umum') ?></div>
                        <div class="label-meta">Total Unit: <?= (int)$it['total_unit'] ?> &bull; Lokasi: <?= e($it['lokasi'] ?: '-') ?></div>
                    </div>
                </div>
                <div class="label-footer">
                    <span>LABEL MASTER KATALOG LAB</span>
                    <span>Tahun <?= date('Y') ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>
