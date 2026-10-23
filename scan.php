<?php

/**
 * FILE       : scan.php
 * MODUL      : Halaman Resolusi & Verifikasi QR Code Alat Laboratorium
 * FUNGSI     : Menampilkan informasi detail, spesifikasi, dan status real-time alat/unit saat QR Code dipindai
 * AKSES      : Publik & Siswa / Petugas
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_name('sipala_session');
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/qrcode.php';
require_once __DIR__ . '/includes/whatsapp.php';

$pdo = db();

$code = trim((string)($_GET['barcode'] ?? $_GET['unit'] ?? $_GET['alat'] ?? $_GET['code'] ?? $_GET['q'] ?? ''));
$unitId = (int)($_GET['unit_id'] ?? 0);
$alatId = (int)($_GET['id'] ?? 0);

$unit = null;
$peralatan = null;
$activeLoan = null;

if ($code !== '' || $unitId > 0 || $alatId > 0) {
    // 1. Coba cari di unit_peralatan terlebih dahulu (Unit Fisik Individual)
    if ($unitId > 0) {
        $st = $pdo->prepare("
            SELECT u.*, p.nama_barang, p.kode_barang, p.foto, p.deskripsi, p.lokasi AS lokasi_master, 
                   p.jumlah, p.jumlah_tersedia, k.nama_kategori
            FROM unit_peralatan u
            JOIN peralatan p ON p.id = u.peralatan_id
            LEFT JOIN kategori k ON k.id = p.kategori_id
            WHERE u.id = ?
        ");
        $st->execute([$unitId]);
        $unit = $st->fetch();
    } elseif ($code !== '') {
        $st = $pdo->prepare("
            SELECT u.*, p.nama_barang, p.kode_barang, p.foto, p.deskripsi, p.lokasi AS lokasi_master, 
                   p.jumlah, p.jumlah_tersedia, k.nama_kategori
            FROM unit_peralatan u
            JOIN peralatan p ON p.id = u.peralatan_id
            LEFT JOIN kategori k ON k.id = p.kategori_id
            WHERE u.barcode = ? OR u.kode_unit = ? OR u.serial_number = ? OR u.rfid_uid = ?
            LIMIT 1
        ");
        $st->execute([$code, $code, $code, $code]);
        $unit = $st->fetch();
    }

    // 2. Jika tidak ditemukan sebagai unit fisik, cari sebagai master peralatan
    if (!$unit) {
        if ($alatId > 0) {
            $stP = $pdo->prepare("
                SELECT p.*, k.nama_kategori,
                       COUNT(u.id) AS total_unit,
                       COALESCE(SUM(CASE WHEN u.kondisi = 'Baik' THEN 1 ELSE 0 END), 0) AS unit_baik
                FROM peralatan p
                LEFT JOIN kategori k ON k.id = p.kategori_id
                LEFT JOIN unit_peralatan u ON u.peralatan_id = p.id
                WHERE p.id = ?
                GROUP BY p.id
            ");
            $stP->execute([$alatId]);
            $peralatan = $stP->fetch();
        } elseif ($code !== '') {
            $stP = $pdo->prepare("
                SELECT p.*, k.nama_kategori,
                       COUNT(u.id) AS total_unit,
                       COALESCE(SUM(CASE WHEN u.kondisi = 'Baik' THEN 1 ELSE 0 END), 0) AS unit_baik
                FROM peralatan p
                LEFT JOIN kategori k ON k.id = p.kategori_id
                LEFT JOIN unit_peralatan u ON u.peralatan_id = p.id
                WHERE p.kode_barang = ? OR p.nama_barang LIKE ?
                GROUP BY p.id
                LIMIT 1
            ");
            $stP->execute([$code, "%$code%"]);
            $peralatan = $stP->fetch();
        }
    } else {
        $peralatan = [
            'id' => $unit['peralatan_id'],
            'nama_barang' => $unit['nama_barang'],
            'kode_barang' => $unit['kode_barang'],
            'foto' => $unit['foto'],
            'deskripsi' => $unit['deskripsi'],
            'lokasi' => $unit['lokasi'] ?: $unit['lokasi_master'],
            'nama_kategori' => $unit['nama_kategori'],
            'jumlah' => $unit['jumlah'],
            'jumlah_tersedia' => $unit['jumlah_tersedia']
        ];

        // Jika unit sedang dipinjam, cari info transaksi aktif
        if ($unit['status'] === 'DIPINJAM') {
            $stLoan = $pdo->prepare("
                SELECT p.kode_peminjaman, p.tanggal_pinjam, p.tanggal_rencana_kembali, u.nama AS nama_peminjam, u.kelas
                FROM detail_peminjaman dp
                JOIN peminjaman p ON p.id = dp.peminjaman_id
                JOIN users u ON u.id = p.user_id
                WHERE dp.unit_peralatan_id = ? AND p.status = 'DIPINJAM'
                ORDER BY p.id DESC LIMIT 1
            ");
            $stLoan->execute([$unit['id']]);
            $activeLoan = $stLoan->fetch();
        }
    }
}

$isLoggedIn = isset($_SESSION['user_id']);
$userRole = (string)($_SESSION['role'] ?? '');
$isStaff = in_array($userRole, ['admin', 'petugas'], true);

$namaInstansi = (string)($pdo->query("SELECT nilai FROM pengaturan WHERE nama = 'nama_instansi'")->fetchColumn() ?: 'SMKN 1 PANYINGKIRAN');
$kontakWa = (string)($pdo->query("SELECT nilai FROM pengaturan WHERE nama = 'kontak_lab_wa'")->fetchColumn() ?: '081234567890');
$ruangLab = (string)($pdo->query("SELECT nilai FROM pengaturan WHERE nama = 'lokasi_ruang_lab'")->fetchColumn() ?: 'Lab Komputer RPL');

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$uri = $_SERVER['REQUEST_URI'] ?? '/scan.php';
$currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://{$host}{$uri}";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $peralatan ? e($peralatan['nama_barang']) : 'Verifikasi QR Code' ?> - SIPALA</title>
    <link rel="icon" type="image/jpeg" href="assets/img/logo.jpg?v=2">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --surface: #ffffff;
            --bg: #f8fafc;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --radius: 12px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            padding: 16px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .container {
            width: 100%;
            max-width: 540px;
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            overflow: hidden;
            border: 1px solid var(--border);
        }
        .header-bar {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            color: #ffffff;
            padding: 20px;
            text-align: center;
            position: relative;
        }
        .header-bar h1 {
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .header-bar p {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 4px;
        }
        .content {
            padding: 24px;
        }
        .item-photo-wrapper {
            position: relative;
            width: 100%;
            height: 220px;
            background: #f1f5f9;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
        }
        .item-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .badge-status {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .badge-tersedia { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .badge-dipinjam { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
        .badge-rusak { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .badge-maintenance { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }

        .item-title {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .item-code-tag {
            display: inline-block;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 3px 8px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 16px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }
        .info-box {
            background: #f8fafc;
            padding: 10px 12px;
            border-radius: 6px;
            border: 1px solid #edf2f7;
        }
        .info-box small {
            display: block;
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            font-weight: 600;
        }
        .info-box strong {
            font-size: 13.5px;
            color: #1e293b;
        }
        .qr-section {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .qr-preview {
            width: 80px;
            height: 80px;
            flex-shrink: 0;
            background: #ffffff;
            padding: 4px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease;
            border: none;
            width: 100%;
        }
        .btn-primary { background: var(--primary); color: #ffffff; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-wa { background: #25D366; color: #ffffff; }
        .btn-wa:hover { background: #20bd5a; }
        .btn-secondary { background: #e2e8f0; color: #334155; }
        .btn-secondary:hover { background: #cbd5e1; }
        
        .footer-note {
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            margin-top: 16px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-bar">
        <h1><?= e($namaInstansi) ?></h1>
        <p>Sistem Informasi Peminjaman Alat Laboratorium (SIPALA)</p>
    </div>

    <div class="content">
        <?php if (!$peralatan): ?>
            <div style="text-align:center;padding:40px 10px;">
                <div style="font-size:48px;margin-bottom:12px;">🔍</div>
                <h2 style="font-size:18px;margin-bottom:8px;">Kode Tidak Ditemukan</h2>
                <p style="font-size:13px;color:#64748b;margin-bottom:20px;">
                    Kode QR "<?= e($code) ?>" tidak terdaftar pada unit inventaris atau master peralatan laboratorium.
                </p>
                <a href="index.php" class="btn btn-secondary">Kembali ke Portal SIPALA</a>
            </div>
        <?php else: ?>
            <div class="item-photo-wrapper">
                <?php if (!empty($peralatan['foto']) && file_exists(__DIR__ . '/' . $peralatan['foto'])): ?>
                    <img src="<?= e($peralatan['foto']) ?>" alt="<?= e($peralatan['nama_barang']) ?>" class="item-photo">
                <?php else: ?>
                    <span style="font-size:12px;font-weight:700;color:#94a3b8;">FOTO PERALATAN</span>
                <?php endif; ?>

                <?php if ($unit): ?>
                    <?php
                        $stClass = match($unit['status']) {
                            'TERSEDIA' => 'badge-tersedia',
                            'DIPINJAM' => 'badge-dipinjam',
                            'RUSAK' => 'badge-rusak',
                            default => 'badge-maintenance'
                        };
                    ?>
                    <div class="badge-status <?= $stClass ?>">
                        <?= e($unit['status']) ?>
                    </div>
                <?php else: ?>
                    <div class="badge-status <?= (int)$peralatan['jumlah_tersedia'] > 0 ? 'badge-tersedia' : 'badge-rusak' ?>">
                        <?= (int)$peralatan['jumlah_tersedia'] > 0 ? (int)$peralatan['jumlah_tersedia'] . ' UNIT TERSEDIA' : 'STOK HABIS' ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="item-title"><?= e($peralatan['nama_barang']) ?></div>
            <div>
                <?php if ($unit): ?>
                    <span class="item-code-tag">UNIT: <?= e($unit['kode_unit']) ?></span>
                <?php endif; ?>
                <span class="item-code-tag" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af;">KODE: <?= e($peralatan['kode_barang']) ?></span>
                <span class="item-code-tag"><?= e($peralatan['nama_kategori'] ?? 'Laboratorium') ?></span>
            </div>

            <div class="info-grid">
                <?php if ($unit): ?>
                    <div class="info-box">
                        <small>Kondisi Fisik</small>
                        <strong><?= e($unit['kondisi']) ?></strong>
                    </div>
                    <div class="info-box">
                        <small>Nomor Seri (SN)</small>
                        <strong><?= e($unit['serial_number'] ?: 'Belum Ada') ?></strong>
                    </div>
                <?php else: ?>
                    <div class="info-box">
                        <small>Total Unit Terdaftar</small>
                        <strong><?= (int)$peralatan['jumlah'] ?> Unit</strong>
                    </div>
                    <div class="info-box">
                        <small>Stok Siap Dipinjam</small>
                        <strong style="color:#16a34a;"><?= (int)$peralatan['jumlah_tersedia'] ?> Unit</strong>
                    </div>
                <?php endif; ?>

                <div class="info-box">
                    <small>Lokasi Ruang / Rak</small>
                    <strong><?= e($peralatan['lokasi'] ?: $ruangLab) ?></strong>
                </div>
                <div class="info-box">
                    <small>RFID Unit</small>
                    <strong><?= ($unit && $unit['rfid_uid']) ? e($unit['rfid_uid']) : 'Terpasang' ?></strong>
                </div>
            </div>

            <?php if (!empty($peralatan['deskripsi'])): ?>
                <div style="font-size:12.5px;color:#475569;background:#f8fafc;padding:12px;border-radius:6px;margin-bottom:20px;border-left:3px solid var(--primary);">
                    <?= nl2br(e($peralatan['deskripsi'])) ?>
                </div>
            <?php endif; ?>

            <?php if ($activeLoan && $isStaff): ?>
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px;margin-bottom:20px;">
                    <div style="font-size:12px;font-weight:700;color:#1e40af;text-transform:uppercase;margin-bottom:4px;">Sedang Dipinjam Oleh:</div>
                    <div style="font-size:14px;font-weight:700;color:#0f172a;"><?= e($activeLoan['nama_peminjam']) ?> (<?= e($activeLoan['kelas']) ?>)</div>
                    <div style="font-size:12px;color:#475569;margin-top:2px;">
                        Kode: <?= e($activeLoan['kode_peminjaman']) ?> &bull; Kembali: <?= format_tanggal_indonesia($activeLoan['tanggal_rencana_kembali']) ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- QR Code Section -->
            <div class="qr-section">
                <div class="qr-preview">
                    <?= render_qr_svg($currentUrl, 72, 1) ?>
                </div>
                <div style="text-align:left;flex:1;">
                    <div style="font-size:12.5px;font-weight:700;color:#1e293b;">Kode QR Terverifikasi</div>
                    <div style="font-size:11px;color:#64748b;margin-top:2px;">
                        Label resmi laboratorium SMKN 1 Panyingkiran. Pindai kapan saja untuk mengecek status fisik alat.
                    </div>
                </div>
            </div>

            <!-- Tombol Interaksi -->
            <div class="btn-group">
                <?php if ($isLoggedIn): ?>
                    <?php if ($isStaff): ?>
                        <a href="admin/unit/cetak_label.php?<?= $unit ? 'id=' . $unit['id'] : 'peralatan_id=' . $peralatan['id'] ?>" target="_blank" class="btn btn-secondary">
                            🖨️ Cetak Stiker Label QR
                        </a>
                        <a href="admin/dashboard.php" class="btn btn-primary">
                            Buka Dashboard Back-Office
                        </a>
                    <?php else: ?>
                        <a href="index.php?pinjam=<?= (int)$peralatan['id'] ?>#katalog-section" class="btn btn-primary">
                            📝 Ajukan Peminjaman Alat Ini
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="login.php?redirect=scan.php?<?= $unit ? 'unit=' . urlencode($unit['kode_unit']) : 'alat=' . urlencode($peralatan['kode_barang']) ?>" class="btn btn-primary">
                        🔑 Masuk untuk Meminjam
                    </a>
                <?php endif; ?>

                <?php
                    $waHelpText = "Halo Petugas Lab SIPALA, saya sedang melihat informasi alat *" . $peralatan['nama_barang'] . "* (" . ($unit ? $unit['kode_unit'] : $peralatan['kode_barang']) . "). Mohon info terkait ketersediaannya.";
                ?>
                <a href="<?= build_wa_click_url($kontakWa, $waHelpText) ?>" target="_blank" class="btn btn-wa">
                    💬 Hubungi Petugas Lab via WhatsApp
                </a>
            </div>

        <?php endif; ?>

        <div class="footer-note">
            &copy; <?= date('Y') ?> <?= e($namaInstansi) ?> &bull; Sistem Terintegrasi QR Code & WhatsApp Bot
        </div>
    </div>
</div>

</body>
</html>
