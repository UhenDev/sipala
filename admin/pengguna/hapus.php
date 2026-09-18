<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('error', 'Metode permintaan tidak diizinkan. Penghapusan pengguna harus menggunakan POST.');
    redirect('index.php');
}

require_csrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    redirect('index.php');
}

if ($id === (int)$_SESSION['user_id']) {
    flash('error', 'Anda tidak dapat menghapus akun Anda sendiri.');
    redirect('index.php');
}

// Cek apakah target pengguna adalah admin, dan pastikan penghapus adalah admin
$stmtTarget = $pdo->prepare('SELECT id, nama, role FROM users WHERE id = ? LIMIT 1');
$stmtTarget->execute([$id]);
$targetUser = $stmtTarget->fetch();
if (!$targetUser) {
    flash('error', 'Pengguna tidak ditemukan.');
    redirect('index.php');
}

if ($targetUser['role'] === 'admin' && ($_SESSION['role'] ?? '') !== 'admin') {
    flash('error', 'Anda tidak memiliki wewenang untuk menghapus akun Administrator.');
    redirect('index.php');
}

try {
    $pdo->beginTransaction();

    // 1. Ambil semua peminjaman milik user ini
    $stmtP = $pdo->prepare('SELECT id, status FROM peminjaman WHERE user_id = ?');
    $stmtP->execute([$id]);
    $loans = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    foreach ($loans as $loan) {
        $loanId = (int)$loan['id'];

        if ($loan['status'] === 'DIPINJAM') {
            $stmtDetails = $pdo->prepare('SELECT peralatan_id, jumlah FROM detail_peminjaman WHERE peminjaman_id = ?');
            $stmtDetails->execute([$loanId]);
            $items = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

            $stmtRestore = $pdo->prepare('UPDATE peralatan SET jumlah_tersedia = jumlah_tersedia + ? WHERE id = ?');
            foreach ($items as $it) {
                $stmtRestore->execute([(int)$it['jumlah'], (int)$it['peralatan_id']]);
            }
        }

        $pdo->prepare('DELETE FROM pengembalian WHERE peminjaman_id = ?')->execute([$loanId]);
        $pdo->prepare('DELETE FROM detail_peminjaman WHERE peminjaman_id = ?')->execute([$loanId]);
        $pdo->prepare('DELETE FROM peminjaman WHERE id = ?')->execute([$loanId]);
    }

    $pdo->prepare('UPDATE peminjaman SET disetujui_oleh = NULL WHERE disetujui_oleh = ?')->execute([$id]);
    $adminId = (int)($_SESSION['user_id'] ?? 1);
    $pdo->prepare('UPDATE pengembalian SET diproses_oleh = ? WHERE diproses_oleh = ?')->execute([$adminId, $id]);

    // Hapus pengguna
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);

    $pdo->commit();
    audit_log('DELETE', 'PENGGUNA', $id, "Menghapus pengguna {$targetUser['nama']} (ID: $id)");
    flash('success', 'Pengguna dan data terkait berhasil dihapus.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Gagal menghapus pengguna: ' . $e->getMessage());
    flash('error', 'Gagal menghapus pengguna karena terdapat kendala sistem.');
}

redirect('index.php');
