<?php
/**
 * FILE       : admin/unit/cetak_label.php
 * MODUL      : Cetak Label Stiker Barcode & Kode QR Unit Laboratorium
 * FUNGSI     : Menghasilkan lembar label stiker fisik siap cetak berstandar Code 128 dan QR Code
 *              untuk printer biasa (A4 Lembar Stiker) maupun printer label thermal (Stiker Satuan/Roll)
 * AKSES      : Administrator / Petugas Laboratorium
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/qrcode.php';
require_once __DIR__ . '/../../includes/barcode.php';

// Parameter Filter & Mode Tampilan
$unitId = (int)($_GET['id'] ?? 0);
$peralatanId = (int)($_GET['peralatan_id'] ?? 0);
$idsParam = trim((string)($_GET['ids'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));
$mode = trim((string)($_GET['mode'] ?? 'barcode')); // 'barcode', 'qr', 'both'
$layout = trim((string)($_GET['layout'] ?? 'cols-3')); // 'cols-3', 'cols-2', 'thermal'

$namaInstansi = (string)($pdo->query("SELECT nilai FROM pengaturan WHERE nama = 'nama_instansi'")->fetchColumn() ?: 'SMK NEGERI 1 PANYINGKIRAN');
$namaLab = (string)($pdo->query("SELECT nilai FROM pengaturan WHERE nama = 'lokasi_ruang_lab'")->fetchColumn() ?: 'Lab Komputer RPL');

$sql = "
    SELECT u.id, u.kode_unit, u.barcode, u.serial_number, u.rfid_uid, u.kondisi, u.lokasi, u.status,
           u.status_label, u.label_printed_at,
           p.id AS peralatan_id, p.nama_barang, p.kode_barang, k.nama_kategori
    FROM unit_peralatan u
    JOIN peralatan p ON p.id = u.peralatan_id
    LEFT JOIN kategori k ON k.id = p.kategori_id
    WHERE 1=1
";
$params = [];

if ($unitId > 0) {
    $sql .= " AND u.id = ?";
    $params[] = $unitId;
} elseif ($idsParam !== '') {
    $idList = array_filter(array_map('intval', explode(',', $idsParam)), fn($n) => $n > 0);
    if (!empty($idList)) {
        $inPlaceholders = implode(',', array_fill(0, count($idList), '?'));
        $sql .= " AND u.id IN ($inPlaceholders)";
        $params = array_merge($params, $idList);
    }
} elseif ($peralatanId > 0) {
    $sql .= " AND u.peralatan_id = ?";
    $params[] = $peralatanId;
}

if ($search !== '') {
    $sql .= " AND (u.barcode LIKE ? OR u.kode_unit LIKE ? OR p.nama_barang LIKE ? OR u.serial_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY p.nama_barang ASC, u.kode_unit ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tandai otomatis unit yang dicetak sebagai SUDAH_DILABELI jika sebelumnya BELUM_DILABELI
if (!empty($units)) {
    $printedIds = array_column($units, 'id');
    if (!empty($printedIds)) {
        $updPlaceholders = implode(',', array_fill(0, count($printedIds), '?'));
        $stMark = $pdo->prepare("
            UPDATE unit_peralatan 
            SET label_printed_at = NOW(),
                status_label = CASE WHEN status_label = 'BELUM_DILABELI' THEN 'SUDAH_DILABELI' ELSE status_label END
            WHERE id IN ($updPlaceholders)
        ");
        $stMark->execute($printedIds);
    }
}

// Daftar semua peralatan untuk filter toolbar
$equipmentList = $pdo->query("SELECT id, nama_barang, kode_barang FROM peralatan ORDER BY nama_barang ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Label Stiker Barcode Unit Alat - SIPALA</title>
    <link rel="icon" type="image/jpeg" href="../../assets/img/logo.jpg?v=2">
    
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            background-color: #f1f5f9;
            color: #0f172a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 0;
        }

        /* TOOLBAR KONTROL DI ATAS (HANYA DITAMPILKAN DI MONITOR) */
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
            flex-wrap: wrap;
        }
        .toolbar-left, .toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .toolbar-title {
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
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
            transition: all 0.15s ease;
            border: none;
        }
        .btn-print {
            background: #2563eb;
            color: #ffffff;
        }
        .btn-print:hover {
            background: #1d4ed8;
        }
        .btn-back {
            background: #475569;
            color: #ffffff;
        }
        .btn-back:hover {
            background: #334155;
        }
        .btn-verify {
            background: #059669;
            color: #ffffff;
        }
        .btn-verify:hover {
            background: #047857;
        }

        /* CONTAINER LEMBAR KERTAS */
        .sheet-container {
            max-width: 1080px;
            margin: 20px auto 40px auto;
            background: #ffffff;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border-radius: 8px;
        }

        /* GRID LABEL STIKER */
        .labels-grid {
            display: grid;
            gap: 12px;
        }
        .labels-grid.cols-3 {
            grid-template-columns: repeat(3, 1fr);
        }
        .labels-grid.cols-2 {
            grid-template-columns: repeat(2, 1fr);
        }
        .labels-grid.thermal {
            grid-template-columns: 1fr;
            max-width: 320px;
            margin: 0 auto;
        }

        /* KARTU STIKER FISIK */
        .label-card {
            border: 1.5px dashed #cbd5e1;
            border-radius: 6px;
            padding: 10px 12px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            page-break-inside: avoid;
            break-inside: avoid;
            position: relative;
            min-height: 148px;
        }
        .label-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
            margin-bottom: 6px;
        }
        .label-brand-title {
            font-size: 10px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }
        .label-lab-badge {
            font-size: 8.5px;
            font-weight: 700;
            background: #0f172a;
            color: #ffffff;
            padding: 2px 5px;
            border-radius: 3px;
            letter-spacing: 0.3px;
        }
        .label-tool-name {
            font-size: 12.5px;
            font-weight: 800;
            color: #1e293b;
            line-height: 1.25;
            margin-bottom: 4px;
            word-break: break-word;
        }
        .label-barcode-svg {
            background: #ffffff;
            padding: 4px 0;
            margin: 2px 0 4px 0;
            text-align: center;
        }
        .label-barcode-text {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Courier New", monospace;
            font-size: 13.5px;
            font-weight: 800;
            letter-spacing: 1px;
            color: #000000;
            text-align: center;
            margin-top: 1px;
        }
        .label-sub-code {
            font-size: 9.5px;
            color: #475569;
            text-align: center;
            font-family: monospace;
            margin-top: 2px;
        }
        .label-footer-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 8.5px;
            color: #64748b;
            border-top: 1px solid #f1f5f9;
            padding-top: 4px;
            margin-top: 4px;
        }

        /* FORMAT KOMBINASI (QR + BARCODE) */
        .label-combo-body {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 4px;
        }
        .label-combo-qr {
            width: 60px;
            height: 60px;
            flex-shrink: 0;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* POPUP FLOATING SCANNER TEST */
        .scan-test-box {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 340px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            padding: 16px;
            z-index: 9999;
            display: none;
        }

        /* PENGATURAN CETAK FISIK (@media print) */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .print-toolbar, .scan-test-box {
                display: none !important;
            }
            .sheet-container {
                box-shadow: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
            .label-card {
                border: 1px dashed #94a3b8 !important;
                border-radius: 3px !important;
                padding: 8px 10px !important;
                min-height: auto !important;
            }
            @page {
                size: A4 portrait;
                margin: 6mm 6mm;
            }
        }
    </style>
</head>
<body>

<!-- TOOLBAR KONTROL DI ATAS -->
<div class="print-toolbar">
    <div class="toolbar-left">
        <a href="index.php" class="btn-action btn-back">← Kembali ke Inventaris</a>
        <div class="toolbar-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Cetak Label Fisik (<?= count($units) ?> Unit)
        </div>
    </div>

    <div class="toolbar-right">
        <!-- Pilihan Format Stiker -->
        <label style="font-size:12px;display:flex;align-items:center;gap:6px;">
            Format:
            <select id="modeSelect" onchange="updateView()" style="padding:6px 10px;border-radius:6px;border:1px solid #475569;background:#334155;color:#ffffff;font-size:12px;font-weight:600;">
                <option value="barcode" <?= $mode === 'barcode' ? 'selected' : '' ?>>||| Barcode 1D (Code 128)</option>
                <option value="both" <?= $mode === 'both' ? 'selected' : '' ?>>🔖 Kombinasi (Barcode + QR)</option>
                <option value="qr" <?= $mode === 'qr' ? 'selected' : '' ?>>📱 Kode QR 2D (Mobile Scan)</option>
            </select>
        </label>

        <!-- Layout Grid / Kertas -->
        <label style="font-size:12px;display:flex;align-items:center;gap:6px;">
            Layout:
            <select id="layoutSelect" onchange="changeLayout(this.value)" style="padding:6px 10px;border-radius:6px;border:1px solid #475569;background:#334155;color:#ffffff;font-size:12px;">
                <option value="cols-3" <?= $layout === 'cols-3' ? 'selected' : '' ?>>3 Kolom (Lembar Stiker A4)</option>
                <option value="cols-2" <?= $layout === 'cols-2' ? 'selected' : '' ?>>2 Kolom (Stiker Sedang)</option>
                <option value="thermal" <?= $layout === 'thermal' ? 'selected' : '' ?>>1 Kolom (Printer Stiker Thermal)</option>
            </select>
        </label>

        <!-- Filter Alat -->
        <form method="GET" action="cetak_label.php" style="display:flex;gap:6px;align-items:center;margin:0;">
            <input type="hidden" name="mode" value="<?= e($mode) ?>">
            <input type="hidden" name="layout" value="<?= e($layout) ?>">
            <select name="peralatan_id" onchange="this.form.submit()" style="padding:6px 10px;border-radius:6px;border:1px solid #475569;background:#334155;color:#ffffff;font-size:12px;max-width:200px;">
                <option value="">-- Semua Peralatan --</option>
                <?php foreach ($equipmentList as $eq): ?>
                    <option value="<?= $eq['id'] ?>" <?= $peralatanId === (int)$eq['id'] ? 'selected' : '' ?>>
                        <?= e($eq['nama_barang']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <button type="button" class="btn-action btn-verify" onclick="toggleScanVerifier()">
            🔍 Uji Scanner
        </button>

        <button type="button" class="btn-action btn-print" onclick="window.print()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Cetak Label Sekarang
        </button>
    </div>
</div>

<div class="sheet-container">
    <?php if (empty($units)): ?>
        <div style="text-align:center;padding:48px 20px;color:#64748b;">
            <div style="font-size:36px;margin-bottom:12px;">🏷️</div>
            <h3 style="color:#0f172a;margin-bottom:6px;">Tidak ada unit fisik yang dipilih untuk dicetak.</h3>
            <p style="font-size:13px;">Silakan pilih peralatan atau centang unit fisik dari tabel inventaris terlebih dahulu.</p>
            <a href="index.php" class="btn-action btn-back" style="margin-top:14px;">Kembali ke Daftar Unit</a>
        </div>
    <?php else: ?>
        <div class="labels-grid <?= e($layout) ?>" id="labelsGrid">
            <?php foreach ($units as $u): ?>
                <?php
                    $barcodeText = (string)($u['barcode'] ?: $u['kode_unit']);
                    $scanUrl = get_unit_scan_url($barcodeText);
                ?>
                <div class="label-card" id="card_unit_<?= $u['id'] ?>">
                    <!-- HEADER LABEL -->
                    <div class="label-card-header">
                        <div class="label-brand-title">SIPALA &bull; <?= e($namaInstansi) ?></div>
                        <div class="label-lab-badge"><?= e($namaLab) ?></div>
                    </div>

                    <!-- NAMA ALAT -->
                    <div class="label-tool-name"><?= e($u['nama_barang']) ?></div>

                    <?php if ($mode === 'barcode'): ?>
                        <!-- FORMAT UTAMA: BARCODE 1D CODE 128 (SESUAI SPESIFIKASI PROMPT SECTION 6) -->
                        <div class="label-barcode-svg">
                            <?= render_code128_svg($barcodeText, 38, 2, false) ?>
                        </div>
                        <div class="label-barcode-text"><?= e($barcodeText) ?></div>
                        <?php if ($u['kode_unit'] !== $barcodeText): ?>
                            <div class="label-sub-code">Unit: <?= e($u['kode_unit']) ?></div>
                        <?php endif; ?>

                    <?php elseif ($mode === 'both'): ?>
                        <!-- FORMAT KOMBINASI: QR CODE + BARCODE 1D -->
                        <div class="label-combo-body">
                            <div class="label-combo-qr">
                                <?= render_qr_svg($scanUrl, 54, 1) ?>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:10px;color:#64748b;"><?= e($u['nama_kategori'] ?? 'Inventaris Lab') ?></div>
                                <div style="font-size:10.5px;font-family:monospace;font-weight:700;color:#0f172a;"><?= e($u['kode_unit']) ?></div>
                                <div style="font-size:9px;color:#64748b;">SN: <?= e($u['serial_number'] ?: '-') ?></div>
                            </div>
                        </div>
                        <div class="label-barcode-svg" style="margin:2px 0;">
                            <?= render_code128_svg($barcodeText, 26, 2, false) ?>
                        </div>
                        <div class="label-barcode-text" style="font-size:12px;"><?= e($barcodeText) ?></div>

                    <?php else: ?>
                        <!-- FORMAT QR CODE 2D -->
                        <div style="display:flex;gap:10px;align-items:center;margin:4px 0;">
                            <div style="width:72px;height:72px;border:1px solid #e2e8f0;padding:2px;border-radius:4px;">
                                <?= render_qr_svg($scanUrl, 68, 1) ?>
                            </div>
                            <div>
                                <div class="label-barcode-text" style="text-align:left;font-size:13px;"><?= e($barcodeText) ?></div>
                                <div class="label-sub-code" style="text-align:left;">Unit: <?= e($u['kode_unit']) ?></div>
                                <div style="font-size:9px;color:#64748b;margin-top:2px;">Kondisi: <?= e($u['kondisi']) ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- FOOTER LABEL -->
                    <div class="label-footer-info">
                        <span>Inventaris Resmi Lab RPL</span>
                        <span style="font-family:monospace;font-weight:700;"><?= date('m/Y') ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- KOTAK PENGUJIAN PEMINDAIAN BARCODE DENGAN SCANNER FISIK -->
<div class="scan-test-box" id="scanVerifierBox">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <strong style="font-size:13px;color:#0f172a;display:flex;align-items:center;gap:6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"></path><path d="M17 3h2a2 2 0 0 1 2 2v2"></path><path d="M21 17v2a2 2 0 0 1-2 2h-2"></path><path d="M7 21H5a2 2 0 0 1-2-2v-2"></path></svg>
            Uji & Verifikasi Barcode Scanner
        </strong>
        <button type="button" onclick="toggleScanVerifier()" style="background:none;border:none;cursor:pointer;font-size:18px;color:#64748b;">&times;</button>
    </div>
    <p style="font-size:11.5px;color:#64748b;margin:0 0 10px 0;">
        Pindai label fisik yang baru dicetak menggunakan barcode scanner USB. Sistem akan mencocokkan kode secara real-time.
    </p>
    <div style="position:relative;margin-bottom:10px;">
        <input type="text" id="testScannerInput" placeholder="Pindai barcode stiker di sini..." 
               autocomplete="off" style="width:100%;padding:8px 10px;border:1.5px solid #2563eb;border-radius:6px;font-family:monospace;font-size:13px;">
    </div>
    <div id="testScanResult" style="font-size:12px;padding:8px;border-radius:6px;display:none;"></div>
</div>

<script>
function changeLayout(val) {
    const grid = document.getElementById('labelsGrid');
    if (!grid) return;
    grid.classList.remove('cols-2', 'cols-3', 'thermal');
    grid.classList.add(val);
    
    // Simpan ke URL tanpa reload penuh jika memungkinkan
    const url = new URL(window.location.href);
    url.searchParams.set('layout', val);
    window.history.replaceState({}, '', url.toString());
}

function updateView() {
    const mode = document.getElementById('modeSelect').value;
    const url = new URL(window.location.href);
    url.searchParams.set('mode', mode);
    window.location.href = url.toString();
}

function toggleScanVerifier() {
    const box = document.getElementById('scanVerifierBox');
    if (!box) return;
    if (box.style.display === 'block') {
        box.style.display = 'none';
    } else {
        box.style.display = 'block';
        setTimeout(() => {
            const inp = document.getElementById('testScannerInput');
            if (inp) {
                inp.value = '';
                inp.focus();
            }
        }, 150);
    }
}

// Handler pemindaian barcode dengan keyboard HID scanner
document.addEventListener('DOMContentLoaded', function() {
    const testInput = document.getElementById('testScannerInput');
    const resultBox = document.getElementById('testScanResult');
    if (!testInput) return;

    let scanTimer = null;
    testInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            performTestScan(this.value.trim());
        }
    });

    testInput.addEventListener('input', function() {
        clearTimeout(scanTimer);
        const val = this.value.trim();
        if (val.length >= 6) {
            scanTimer = setTimeout(() => {
                performTestScan(val);
            }, 300);
        }
    });

    function performTestScan(code) {
        if (!code) return;
        resultBox.style.display = 'block';
        resultBox.style.background = '#f8fafc';
        resultBox.style.color = '#334155';
        resultBox.innerHTML = 'Memverifikasi barcode: <strong>' + escapeHtml(code) + '</strong>...';

        fetch('../api_barcode_lookup.php?action=lookup_unit&barcode=' + encodeURIComponent(code))
            .then(res => res.json())
            .then(data => {
                if (data.success && data.unit) {
                    resultBox.style.background = '#dcfce7';
                    resultBox.style.color = '#15803d';
                    resultBox.innerHTML = '<strong>Scan Berhasil!</strong><br>' + 
                        escapeHtml(data.unit.nama_barang) + '<br>' +
                        'Barcode: <code>' + escapeHtml(data.unit.barcode) + '</code> &bull; Unit: <code>' + escapeHtml(data.unit.kode_unit) + '</code><br>' +
                        'Status: ' + escapeHtml(data.unit.status);

                    // Sorot kartu di layar
                    const card = document.getElementById('card_unit_' + data.unit.id);
                    if (card) {
                        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        card.style.borderColor = '#16a34a';
                        card.style.boxShadow = '0 0 0 3px rgba(22, 163, 74, 0.3)';
                    }
                } else {
                    resultBox.style.background = '#fee2e2';
                    resultBox.style.color = '#b91c1c';
                    resultBox.innerHTML = '<strong>Pemindaian Gagal:</strong><br>' + escapeHtml(data.message || 'Barcode tidak dikenali.');
                }
                testInput.value = '';
                testInput.focus();
            })
            .catch(err => {
                resultBox.style.background = '#fee2e2';
                resultBox.style.color = '#b91c1c';
                resultBox.innerHTML = 'Terjadi kesalahan jaringan/server saat memverifikasi barcode.';
            });
    }

    function escapeHtml(str) {
        return (str || '').replace(/[&<>"']/g, function(m) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m];
        });
    }
});
</script>

</body>
</html>
