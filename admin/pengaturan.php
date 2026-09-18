<?php

/**
 * FILE       : admin/pengaturan.php
 * MODUL      : Konfigurasi Sistem Laboratorium
 * FUNGSI     : Mengelola parameter operasional laboratorium (tarif denda harian, batas maksimal hari pinjam, kuota unit, jam operasional)
 * AKSES      : Administrator
 *
 * KONFIGURASI KRITIS:
 * - `tarif_denda_harian`: Nilai denda per hari keterlambatan yang dipakai oleh modul pengembalian (rumus: hari terlambat × tarif).
 * - `maks_hari_pinjam`  : Membatasi rentang tanggal rencana pengembalian di portal siswa agar tidak melebihi batas kebijakan lab.
 * - `maks_unit_pinjam`  : Membatasi kuota jumlah barang yang dapat diajukan per siswa dalam satu transaksi.
 *
 * ALUR:
 * 1. Memeriksa otorisasi administrator.
 * 2. Mengambil seluruh nilai konfigurasi dari tabel `pengaturan`.
 * 3. Memproses input POST dengan validasi whitelist key dan sanitasi nilai.
 * 4. Menyimpan pembaruan konfigurasi dan mencatat ke audit log.
 */

require_once __DIR__ . '/includes/admin.php';

// Ambil semua pengaturan yang ada di database
$settingsRows = $pdo->query('SELECT nama, nilai, deskripsi FROM pengaturan')->fetchAll(PDO::FETCH_ASSOC);
$settings = [];
foreach ($settingsRows as $r) {
    $settings[$r['nama']] = (string)$r['nilai'];
}

// Tangani penyimpanan form pengaturan via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $allowedKeys = [
        'nama_aplikasi',
        'nama_instansi',
        'lokasi_ruang_lab',
        'jam_operasional_lab',
        'maks_hari_pinjam',
        'maks_unit_pinjam',
        'tarif_denda_harian',
        'kontak_lab_wa'
    ];

    try {
        $pdo->beginTransaction();
        $stmtUpd = $pdo->prepare('UPDATE pengaturan SET nilai = ? WHERE nama = ?');
        $stmtIns = $pdo->prepare('INSERT INTO pengaturan (nama, nilai, deskripsi) VALUES (?, ?, ?)');
        $stmtChk = $pdo->prepare('SELECT COUNT(*) FROM pengaturan WHERE nama = ?');

        foreach ($allowedKeys as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            
            // Validasi tipe data numerik
            if (in_array($k, ['maks_hari_pinjam', 'maks_unit_pinjam'], true)) {
                $v = (string)max(1, (int)$v);
            } elseif ($k === 'tarif_denda_harian') {
                $v = (string)max(0, (int)$v);
            }

            $stmtChk->execute([$k]);
            if ((int)$stmtChk->fetchColumn() > 0) {
                $stmtUpd->execute([$v, $k]);
            } else {
                $stmtIns->execute([$k, $v, 'Pengaturan ' . $k]);
            }
        }

        $pdo->commit();
        audit_log('UPDATE', 'PENGATURAN', null, "Memperbarui konfigurasi parameter operasional laboratorium");

        flash('success', 'Konfigurasi pengaturan laboratorium berhasil diperbarui.');
        redirect('pengaturan.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Gagal menyimpan pengaturan: ' . $e->getMessage());
        redirect('pengaturan.php');
    }
}

page_start('Pengaturan', 'Konfigurasi operasional dan kebijakan laboratorium', 'pengaturan'); 
?>

<div class="head">
    <div>
        <h2>Pengaturan Laboratorium</h2>
        <p>Kelola identitas sistem, kebijakan batas peminjaman, tarif denda harian, dan kontak helpdesk petugas.</p>
    </div>
</div>

<form method="post" action="pengaturan.php">
    <?= csrf_field() ?>
    <!-- PANEL 1: IDENTITAS APLIKASI & RUANG LAB -->
    <div class="panel">
        <div class="panel-title">
            <span>1. Identitas Sistem & Ruang Laboratorium</span>
        </div>
        <div style="padding:22px;">
            <div class="grid">
                <div class="field">
                    <label>Nama Sistem / Aplikasi *</label>
                    <input name="nama_aplikasi" required value="<?= e($settings['nama_aplikasi'] ?? 'SIPALA') ?>">
                    <small style="color:#64748b;font-size:11px;">Nama aplikasi yang tampil di header dan judul halaman.</small>
                </div>

                <div class="field">
                    <label>Nama Sekolah / Instansi *</label>
                    <input name="nama_instansi" required value="<?= e($settings['nama_instansi'] ?? 'Laboratorium Sekolah') ?>">
                    <small style="color:#64748b;font-size:11px;">Nama instansi pemilik inventaris laboratorium.</small>
                </div>

                <div class="field">
                    <label>Lokasi Fisik Ruang Lab</label>
                    <input name="lokasi_ruang_lab" value="<?= e($settings['lokasi_ruang_lab'] ?? 'Gedung Laboratorium Terpadu Lantai 2') ?>">
                    <small style="color:#64748b;font-size:11px;">Informasi gedung / ruangan tempat siswa mengambil dan mengembalikan alat.</small>
                </div>

                <div class="field">
                    <label>Jam Operasional Layanan Lab</label>
                    <input name="jam_operasional_lab" value="<?= e($settings['jam_operasional_lab'] ?? 'Senin - Jumat: 07.30 - 15.30 WIB') ?>">
                    <small style="color:#64748b;font-size:11px;">Waktu layanan serah terima dan pengembalian barang.</small>
                </div>
            </div>
        </div>
    </div>

    <!-- PANEL 2: ATURAN & KEBIJAKAN PEMINJAMAN ALAT -->
    <div class="panel">
        <div class="panel-title">
            <span>2. Batasan Peminjaman & Kebijakan Siswa</span>
        </div>
        <div style="padding:22px;">
            <div class="grid">
                <div class="field">
                    <label>Maksimal Durasi Pinjam (Hari) *</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" min="1" max="30" name="maks_hari_pinjam" required style="width:140px;"
                               value="<?= e($settings['maks_hari_pinjam'] ?? '3') ?>">
                        <span style="font-size:13px;color:#475569;font-weight:600;">Hari kalender</span>
                    </div>
                    <small style="color:#64748b;font-size:11px;margin-top:4px;">
                        Membatasi pilihan tanggal pengembalian di portal siswa agar alat laboratorium tidak dipinjam terlalu lama.
                    </small>
                </div>

                <div class="field">
                    <label>Maksimal Unit per Transaksi *</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" min="1" max="20" name="maks_unit_pinjam" required style="width:140px;"
                               value="<?= e($settings['maks_unit_pinjam'] ?? '2') ?>">
                        <span style="font-size:13px;color:#475569;font-weight:600;">Unit alat</span>
                    </div>
                    <small style="color:#64748b;font-size:11px;margin-top:4px;">
                        Mencegah seorang siswa meminjam stok dalam jumlah banyak sekaligus agar alat dapat dinikmati siswa lain.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- PANEL 3: TARIF DENDA KETERLAMBATAN -->
    <div class="panel">
        <div class="panel-title">
            <span>3. Kebijakan Denda Keterlambatan</span>
        </div>
        <div style="padding:22px;">
            <div class="field" style="max-width:400px;">
                <label>Tarif Denda per Hari Terlambat *</label>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:14px;font-weight:700;color:#334155;">Rp</span>
                    <input type="number" min="0" step="500" name="tarif_denda_harian" required style="flex:1;"
                           value="<?= e($settings['tarif_denda_harian'] ?? '1000') ?>">
                    <span style="font-size:13px;color:#475569;font-weight:600;">/ hari</span>
                </div>
                <small style="color:#64748b;font-size:11px;margin-top:4px;">
                    Denda akan dikalkulasikan secara otomatis pada modal pengembalian alat dengan rumus: 
                    <strong>[Hari Terlambat] &times; [Tarif Denda Harian]</strong>. 
                    (Petugas di lab tetap dapat menyesuaikan nilai denda saat penerimaan fisik barang).
                </small>
            </div>
        </div>
    </div>

    <!-- PANEL 4: HELPDESK & KONTAK PETUGAS LAB -->
    <div class="panel">
        <div class="panel-title">
            <span>4. Bantuan & Kontak Petugas Laboratorium</span>
        </div>
        <div style="padding:22px;">
            <div class="field" style="max-width:400px;">
                <label>Nomor WhatsApp Petugas Lab</label>
                <input type="text" name="kontak_lab_wa" placeholder="Contoh: 081234567890" 
                       value="<?= e($settings['kontak_lab_wa'] ?? '081234567890') ?>">
                <small style="color:#64748b;font-size:11px;margin-top:4px;">
                    Nomor kontak ini akan tampil pada formulir dan kode bukti pinjam siswa agar siswa dapat menghubungi petugas laboratorium.
                </small>
            </div>
        </div>
    </div>

    <div class="form-actions" style="margin-top:16px;">
        <button type="submit" class="btn btn-primary" style="padding:10px 24px;font-size:14px;">
            Simpan Seluruh Pengaturan
        </button>
    </div>
</form>

<?php page_end(); ?>