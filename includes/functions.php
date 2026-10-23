<?php

/**
 * FILE       : includes/functions.php
 * MODUL      : Pustaka Fungsi Utama Sistem (Global Helpers)
 * FUNGSI     : Menyediakan utilitas global untuk sinkronisasi stok, audit trail, notifikasi, formatting, dan kategori
 * AKSES      : Seluruh Modul Aplikasi (Admin & Portal Siswa)
 *
 * TANGGUNG JAWAB:
 * 1. Pencatatan jejak audit (audit_log) untuk menjamin akuntabilitas seluruh aktivitas.
 * 2. Distribusi notifikasi sistem ke pengguna spesifik maupun seluruh administrator.
 * 3. Sinkronisasi stok master peralatan berdasarkan data fisik nyata di unit_peralatan.
 * 4. Pemformatan representasi data (mata uang Rupiah, tanggal Indonesia, badge status).
 * 5. Resolusi otomatis nama kategori dinamis langsung dari master peralatan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/barcode.php';

if (!function_exists('e')) {
    /**
     * Sanitasi output HTML untuk mencegah serangan Cross-Site Scripting (XSS).
     */
    function e(mixed $v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Mencatat aktivitas ke tabel audit log (log_aktivitas).
 *
 * @param string   $aksi      Jenis tindakan (CREATE, UPDATE, DELETE, BORROW, RETURN, APPROVE)
 * @param string   $modul     Nama modul sistem terkait (PEMINJAMAN, PERALATAN, UNIT, PENGGUNA)
 * @param int|null $refId     ID rekaman yang menjadi target tindakan
 * @param string   $deskripsi Rincian keterangan perubahan yang dilakukan
 * @param int|null $userId    ID pengguna pelaku aksi (default: session saat ini)
 */
function audit_log(string $aksi, string $modul, ?int $refId, string $deskripsi, ?int $userId = null): void
{
    try {
        $pdo = db();
        if ($userId === null && isset($_SESSION['user_id'])) {
            $userId = (int)$_SESSION['user_id'];
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $pdo->prepare("
            INSERT INTO log_aktivitas (user_id, aksi, modul, reference_id, aktivitas, deskripsi, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, strtoupper($aksi), strtoupper($modul), $refId, strtoupper($aksi), $deskripsi, $ip]);
    } catch (Throwable $e) {
        // Jangan biarkan kegagalan logging menggagalkan transaksi utama
        error_log('Audit log error: ' . $e->getMessage());
    }
}

/**
 * Mengirim notifikasi internal ke pengguna tertentu
 */
function send_notification(int $userId, string $judul, string $pesan, string $tipe = 'info', ?string $link = null): void
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            INSERT INTO notifikasi (user_id, judul, pesan, tipe, link, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$userId, $judul, $pesan, $tipe, $link]);
    } catch (Throwable $e) {
        error_log('Notification send error: ' . $e->getMessage());
    }
}

/**
 * Mengirim notifikasi internal ke semua laboran & admin
 */
function notify_all_admins(string $judul, string $pesan, string $tipe = 'info', ?string $link = null): void
{
    try {
        $pdo = db();
        $admins = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'petugas') AND status = 'aktif'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            send_notification((int)$adminId, $judul, $pesan, $tipe, $link);
        }
    } catch (Throwable $e) {
        error_log('Admin notification error: ' . $e->getMessage());
    }
}

/**
 * Sinkronisasi stok master peralatan dengan data unit_peralatan fisik
 */
function sync_peralatan_stock(int $peralatanId): array
{
    $pdo = db();
    
    // Hitung total unit dan unit yang berstatus TERSEDIA
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_unit,
            SUM(CASE WHEN status = 'TERSEDIA' AND kondisi != 'Rusak Berat' THEN 1 ELSE 0 END) AS tersedia,
            SUM(CASE WHEN status = 'DIPINJAM' THEN 1 ELSE 0 END) AS dipinjam,
            SUM(CASE WHEN status = 'RUSAK' OR kondisi = 'Rusak Berat' THEN 1 ELSE 0 END) AS rusak,
            SUM(CASE WHEN status = 'HILANG' THEN 1 ELSE 0 END) AS hilang
        FROM unit_peralatan
        WHERE peralatan_id = ?
    ");
    $stmt->execute([$peralatanId]);
    $stats = $stmt->fetch() ?: [
        'total_unit' => 0,
        'tersedia' => 0,
        'dipinjam' => 0,
        'rusak' => 0,
        'hilang' => 0
    ];

    $total = (int)($stats['total_unit'] ?? 0);
    $tersedia = (int)($stats['tersedia'] ?? 0);

    // Update data di tabel master peralatan sesuai data unit fisik aktual
    $upd = $pdo->prepare("UPDATE peralatan SET jumlah = ?, jumlah_tersedia = ? WHERE id = ?");
    $upd->execute([$total, $tersedia, $peralatanId]);

    return [
        'total' => $total,
        'tersedia' => $tersedia,
        'dipinjam' => (int)($stats['dipinjam'] ?? 0),
        'rusak' => (int)($stats['rusak'] ?? 0),
        'hilang' => (int)($stats['hilang'] ?? 0),
    ];
}

/**
 * Format mata uang Rupiah
 */
function format_rupiah(float|int $nominal): string
{
    return 'Rp ' . number_format($nominal, 0, ',', '.');
}

/**
 * Format tanggal Indonesia
 */
function format_tanggal_indonesia(?string $date, bool $withTime = false): string
{
    if (!$date) return '-';
    $time = strtotime($date);
    if (!$time) return $date;

    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];

    $tgl = date('j', $time);
    $bln = $bulan[(int)date('n', $time)] ?? date('m', $time);
    $thn = date('Y', $time);

    $formatted = "$tgl $bln $thn";
    if ($withTime) {
        $formatted .= ' pukul ' . date('H:i', $time) . ' WIB';
    }
    return $formatted;
}

/**
 * Format badge HTML untuk status transaksi peminjaman
 */
function status_badge(string $status): string
{
    $statusClass = [
        'MENUNGGU' => 'background:#fef3c7;color:#b45309;border:1px solid #fde68a;',
        'DISETUJUI' => 'background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;',
        'SIAP_DIAMBIL' => 'background:#e0e7ff;color:#4338ca;border:1px solid #c7d2fe;',
        'DIPINJAM' => 'background:#dbeafe;color:#1d4ed8;border:1px solid #bfdbfe;',
        'MENUNGGU_PENGEMBALIAN' => 'background:#ffedd5;color:#c2410c;border:1px solid #fed7aa;',
        'SELESAI' => 'background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;',
        'DITOLAK' => 'background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;',
        'TERLAMBAT' => 'background:#ffe4e6;color:#e11d48;border:1px solid #fecdd3;',
        'DIBATALKAN' => 'background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;',
    ];

    $style = $statusClass[$status] ?? 'background:#f1f5f9;color:#64748b;';
    $label = str_replace('_', ' ', $status);

    return '<span style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;' . $style . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Format badge HTML untuk status unit alat
 */
function unit_status_badge(string $status): string
{
    $classes = [
        'TERSEDIA' => 'background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;',
        'DIPINJAM' => 'background:#dbeafe;color:#1d4ed8;border:1px solid #bfdbfe;',
        'RUSAK' => 'background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;',
        'HILANG' => 'background:#374151;color:#f9fafb;border:1px solid #1f2937;',
        'MAINTENANCE' => 'background:#fef3c7;color:#b45309;border:1px solid #fde68a;',
    ];
    $style = $classes[$status] ?? 'background:#f1f5f9;color:#64748b;';
    return '<span style="display:inline-flex;align-items:center;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700;' . $style . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Mencari id kategori berdasarkan nama, atau otomatis membuatnya jika belum ada
 */
function resolve_kategori_id(?string $namaKategori): int
{
    $pdo = db();
    $name = trim((string)$namaKategori);
    if ($name === '') {
        $name = 'Umum';
    }
    $st = $pdo->prepare("SELECT id FROM kategori WHERE LOWER(nama_kategori) = LOWER(?) LIMIT 1");
    $st->execute([$name]);
    $catId = $st->fetchColumn();
    if ($catId) {
        return (int)$catId;
    }
    $ins = $pdo->prepare("INSERT INTO kategori (nama_kategori, deskripsi) VALUES (?, 'Dibuat otomatis dari Master Peralatan')");
    $ins->execute([$name]);
    return (int)$pdo->lastInsertId();
}


/**
 * Memvalidasi dan menyimpan berkas unggahan gambar dengan verifikasi Magic Bytes & MIME Type
 *
 * @param array  $fileInfo    Array $_FILES['...']
 * @param string $subDir      Subdirektori tujuan di dalam assets/uploads/
 * @param string $prefix      Awalan nama berkas acak
 * @return string             Path relatif berkas tersimpan
 * @throws RuntimeException   Jika berkas tidak valid atau berbahaya
 */
function secure_upload_image(array $fileInfo, string $subDir, string $prefix = 'img'): string
{
    if (!isset($fileInfo['error']) || $fileInfo['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Gagal mengunggah berkas: kode error ' . ($fileInfo['error'] ?? 'tidak diketahui'));
    }

    $fileTmp = (string)$fileInfo['tmp_name'];
    $fileName = (string)$fileInfo['name'];
    $fileSize = (int)$fileInfo['size'];

    // Batas maksimal 2 MB
    if ($fileSize <= 0 || $fileSize > 2 * 1024 * 1024) {
        throw new RuntimeException('Ukuran berkas gambar maksimal 2 MB.');
    }

    // 1. Validasi ekstensi
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExts, true)) {
        throw new RuntimeException('Format berkas harus berupa JPG, PNG, atau WEBP.');
    }

    // 2. Validasi MIME Type asli dengan finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? finfo_file($finfo, $fileTmp) : '';
    if ($finfo) finfo_close($finfo);

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new RuntimeException('Berkas yang diunggah bukan gambar valid (MIME: ' . htmlspecialchars((string)$mimeType) . ').');
    }

    // 3. Validasi integritas gambar dengan getimagesize
    $imgSize = @getimagesize($fileTmp);
    if ($imgSize === false) {
        throw new RuntimeException('Berkas terkorupsi atau bukan representasi gambar yang valid.');
    }

    // 4. Siapkan direktori tujuan
    $uploadBase = __DIR__ . '/../assets/uploads/' . trim($subDir, '/') . '/';
    if (!is_dir($uploadBase)) {
        mkdir($uploadBase, 0755, true);
    }

    // Normalisasi ekstensi
    if ($ext === 'jpeg') $ext = 'jpg';
    $safeName = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadBase . $safeName;

    if (!move_uploaded_file($fileTmp, $targetPath)) {
        throw new RuntimeException('Gagal memindahkan berkas unggahan ke direktori penyimpanan.');
    }

    return 'assets/uploads/' . trim($subDir, '/') . '/' . $safeName;
}

/**
 * Memvalidasi dan menyimpan data gambar Base64 hasil potong (Cropped Image)
 */
function secure_save_base64_image(string $base64Data, string $subDir, string $prefix = 'crop'): ?string
{
    if (empty($base64Data) || !str_starts_with($base64Data, 'data:image/')) {
        return null;
    }

    if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $base64Data, $matches)) {
        return null;
    }

    $raw = substr($base64Data, strpos($base64Data, ',') + 1);
    $decoded = base64_decode($raw, true);
    if ($decoded === false || strlen($decoded) > 2 * 1024 * 1024) {
        return null;
    }

    // Verifikasi MIME buffer
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? finfo_buffer($finfo, $decoded) : '';
    if ($finfo) finfo_close($finfo);

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        return null;
    }

    $uploadBase = __DIR__ . '/../assets/uploads/' . trim($subDir, '/') . '/';
    if (!is_dir($uploadBase)) {
        mkdir($uploadBase, 0755, true);
    }

    $ext = strtolower($matches[1]);
    if ($ext === 'jpeg') $ext = 'jpg';
    $safeName = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadBase . $safeName;

    if (file_put_contents($targetPath, $decoded) === false) {
        return null;
    }

    return 'assets/uploads/' . trim($subDir, '/') . '/' . $safeName;
}


/**
 * Menentukan prefix kode barang berdasarkan nama kategori peralatan
 */
function get_prefix_from_kategori(string $kategori): string
{
    $kat = strtolower(trim($kategori));
    if ($kat === '') return 'ALT';
    if (str_contains($kat, 'iot') || str_contains($kat, 'sensor') || str_contains($kat, 'arduino') || str_contains($kat, 'embedded') || str_contains($kat, 'esp')) {
        return 'IOT';
    }
    if (str_contains($kat, 'jaringan') || str_contains($kat, 'network') || str_contains($kat, 'router') || str_contains($kat, 'cisco') || str_contains($kat, 'switch') || str_contains($kat, 'lan')) {
        return 'NET';
    }
    if (str_contains($kat, 'hardware') || str_contains($kat, 'servis') || str_contains($kat, 'pc') || str_contains($kat, 'komputer') || str_contains($kat, 'obeng') || str_contains($kat, 'solder')) {
        return 'HW';
    }
    if (str_contains($kat, 'multimedia') || str_contains($kat, 'desain') || str_contains($kat, 'kamera') || str_contains($kat, 'audio') || str_contains($kat, 'video') || str_contains($kat, 'tablet')) {
        return 'MM';
    }
    if (str_contains($kat, 'peripheral') || str_contains($kat, 'testing') || str_contains($kat, 'scanner') || str_contains($kat, 'rfid') || str_contains($kat, 'reader')) {
        return 'DEV';
    }
    
    // Ambil 3 huruf pertama dari nama kategori jika memungkinkan
    $clean = strtoupper(preg_replace('/[^A-Za-z]/', '', $kategori));
    if (strlen($clean) >= 3) {
        return substr($clean, 0, 3);
    }
    return 'ALT';
}

/**
 * Menghasilkan kode barang inventaris unik berikutnya secara otomatis (contoh: ALT-001, IOT-002, NET-003)
 */
function get_next_kode_barang(?PDO $pdo = null, string $prefix = 'ALT'): string
{
    if ($pdo === null) {
        $pdo = db();
    }
    $cleanPrefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix));
    if ($cleanPrefix === '') {
        $cleanPrefix = 'ALT';
    }

    $stmt = $pdo->prepare("
        SELECT kode_barang 
        FROM peralatan 
        WHERE kode_barang LIKE ? 
        ORDER BY id DESC
    ");
    $stmt->execute([$cleanPrefix . '-%']);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $maxNum = 0;
    foreach ($existing as $c) {
        if (preg_match('/^' . preg_quote($cleanPrefix, '/') . '-(\d+)$/i', (string)$c, $m)) {
            $num = (int)$m[1];
            if ($num > $maxNum) {
                $maxNum = $num;
            }
        }
    }

    $nextNum = $maxNum + 1;
    do {
        $code = sprintf("%s-%03d", $cleanPrefix, $nextNum);
        $chk = $pdo->prepare("SELECT COUNT(*) FROM peralatan WHERE kode_barang = ?");
        $chk->execute([$code]);
        $exists = (int)$chk->fetchColumn() > 0;
        if ($exists) {
            $nextNum++;
        }
    } while ($exists);

    return $code;
}
