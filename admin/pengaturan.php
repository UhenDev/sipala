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
require_admin();

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
        'kontak_lab_wa',
        'wa_gateway_status',
        'wa_gateway_provider',
        'wa_api_token',
        'wa_api_endpoint',
        'wa_admin_phone',
        'wa_template_pengajuan',
        'wa_template_pengajuan_siswa',
        'wa_template_disetujui',
        'wa_template_ditolak',
        'wa_template_serah_terima',
        'wa_template_pengembalian',
        'wa_template_pengingat'
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

    <!-- PANEL 5: INTEGRASI BOT WHATSAPP GATEWAY -->
    <div class="panel">
        <div class="panel-title" style="display:flex;align-items:center;justify-content:space-between;">
            <span>5. Integrasi WhatsApp Bot & Gateway Laboratorium</span>
            <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;<?= ($settings['wa_gateway_status'] ?? 'aktif') === 'aktif' ? 'background:#dcfce7;color:#166534;' : 'background:#fee2e2;color:#991b1b;' ?>">
                <?= ($settings['wa_gateway_status'] ?? 'aktif') === 'aktif' ? '● GATEWAY AKTIF' : '○ NONAKTIF' ?>
            </span>
        </div>
        <div style="padding:22px;">
            <p style="font-size:12.5px;color:#475569;margin-bottom:18px;">
                Aktifkan pengiriman pesan otomatis melalui Bot WhatsApp untuk notifikasi persetujuan peminjaman, serah terima alat fisik, tanda terima pengembalian, dan pengingat jatuh tempo.
            </p>

            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));gap:16px;">
                <div class="field">
                    <label>Status Gateway WhatsApp *</label>
                    <select name="wa_gateway_status" style="width:100%;">
                        <option value="aktif" <?= ($settings['wa_gateway_status'] ?? 'aktif') === 'aktif' ? 'selected' : '' ?>>Aktif (Kirim Otomatis via Bot API)</option>
                        <option value="nonaktif" <?= ($settings['wa_gateway_status'] ?? '') === 'nonaktif' ? 'selected' : '' ?>>Nonaktif (Hanya Tautan Manual wa.me)</option>
                    </select>
                    <small style="color:#64748b;font-size:11px;">Jika nonaktif, sistem akan menyediakan tombol 1-klik buka WhatsApp Web/App.</small>
                </div>

                <div class="field">
                    <label>Provider Gateway WhatsApp *</label>
                    <select name="wa_gateway_provider" id="waProviderSelect" style="width:100%;" onchange="adjustProviderEndpoint(this.value)">
                        <option value="fonnte" <?= ($settings['wa_gateway_provider'] ?? 'fonnte') === 'fonnte' ? 'selected' : '' ?>>Fonnte (Populer & Stabil di Indonesia)</option>
                        <option value="wablas" <?= ($settings['wa_gateway_provider'] ?? '') === 'wablas' ? 'selected' : '' ?>>Wablas</option>
                        <option value="starsender" <?= ($settings['wa_gateway_provider'] ?? '') === 'starsender' ? 'selected' : '' ?>>Starsender</option>
                        <option value="custom" <?= ($settings['wa_gateway_provider'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom Webhook / Node.js Baileys API</option>
                    </select>
                </div>

                <div class="field">
                    <label>API Token / Secret Key Bot *</label>
                    <input type="password" name="wa_api_token" id="waTokenInput" placeholder="Masukkan token API..." 
                           value="<?= e($settings['wa_api_token'] ?? '') ?>" autocomplete="new-password">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
                        <small style="color:#64748b;font-size:11px;">Didapat dari dashboard penyedia WhatsApp gateway.</small>
                        <button type="button" onclick="togglePasswordVisibility('waTokenInput')" style="background:none;border:none;color:#2563eb;font-size:11px;cursor:pointer;font-weight:600;">Lihat Token</button>
                    </div>
                </div>

                <div class="field">
                    <label>API URL Endpoint</label>
                    <input type="text" name="wa_api_endpoint" id="waEndpointInput" placeholder="https://api.fonnte.com/send" 
                           value="<?= e($settings['wa_api_endpoint'] ?? 'https://api.fonnte.com/send') ?>">
                    <small style="color:#64748b;font-size:11px;">Biarkan default kecuali Anda menggunakan server bot sendiri.</small>
                </div>

                <div class="field">
                    <label>Nomor WhatsApp Admin Lab (Penerima Alert)</label>
                    <input type="text" name="wa_admin_phone" placeholder="Contoh: 081234567890" 
                           value="<?= e($settings['wa_admin_phone'] ?? '081234567890') ?>">
                    <small style="color:#64748b;font-size:11px;">Nomor ini akan menerima pesan notifikasi instan saat siswa mengajukan pinjaman baru.</small>
                </div>
            </div>

            <!-- UJI COBA KONEKSI BOT WHATSAPP -->
            <div style="margin-top:20px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:16px;">
                <h4 style="font-size:13.5px;font-weight:700;color:#0f172a;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 2 11 13"></polyline><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                    Uji Coba Pengiriman Pesan Bot WhatsApp
                </h4>
                <p style="font-size:11.5px;color:#64748b;margin-bottom:12px;">Kirim pesan uji coba untuk memverifikasi apakah API Token dan nomor bot sudah terhubung dengan benar.</p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    <div style="flex:1;min-width:220px;">
                        <input type="text" id="waTestPhone" placeholder="Masukkan nomor tujuan tes (misal 081234567890)" style="width:100%;height:38px;" value="<?= e($settings['wa_admin_phone'] ?? '') ?>">
                    </div>
                    <button type="button" id="btnTestWa" onclick="runWaTest()" class="btn" style="background:#16a34a;color:#ffffff;height:38px;padding:0 18px;font-weight:700;display:inline-flex;align-items:center;gap:6px;">
                        Kirim Pesan Uji Coba
                    </button>
                </div>
                <div id="waTestResult" style="margin-top:10px;font-size:12px;display:none;"></div>
            </div>

            <!-- TEMPLATE PESAN OTOMATIS -->
            <div style="margin-top:24px;">
                <h4 style="font-size:13.5px;font-weight:700;color:#0f172a;margin-bottom:8px;">
                    Template Pesan WhatsApp Otomatis
                </h4>
                <p style="font-size:11.5px;color:#64748b;margin-bottom:14px;">
                    Variabel placeholder dinamis yang didukung: <code>{nama}</code>, <code>{nis}</code>, <code>{kelas}</code>, <code>{kode_peminjaman}</code>, <code>{alat}</code>, <code>{kode_unit}</code>, <code>{jumlah}</code>, <code>{tgl_pinjam}</code>, <code>{tgl_kembali}</code>, <code>{denda}</code>, <code>{lokasi_ruang_lab}</code>, <code>{alasan}</code>.
                </p>

                <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));gap:16px;">
                    <div class="field">
                        <label>Template: Notifikasi Pengajuan ke Admin Lab</label>
                        <textarea name="wa_template_pengajuan" rows="4" style="font-size:12px;font-family:monospace;"><?= e($settings['wa_template_pengajuan'] ?? '') ?></textarea>
                    </div>

                    <div class="field">
                        <label>Template: Bukti Pengajuan ke Siswa</label>
                        <textarea name="wa_template_pengajuan_siswa" rows="4" style="font-size:12px;font-family:monospace;"><?= e($settings['wa_template_pengajuan_siswa'] ?? '') ?></textarea>
                    </div>

                    <div class="field">
                        <label>Template: Pengajuan Disetujui (Approval)</label>
                        <textarea name="wa_template_disetujui" rows="4" style="font-size:12px;font-family:monospace;"><?= e($settings['wa_template_disetujui'] ?? '') ?></textarea>
                    </div>

                    <div class="field">
                        <label>Template: Pengajuan Ditolak (Rejection)</label>
                        <textarea name="wa_template_ditolak" rows="4" style="font-size:12px;font-family:monospace;"><?= e($settings['wa_template_ditolak'] ?? '') ?></textarea>
                    </div>

                    <div class="field">
                        <label>Template: Konfirmasi Serah Terima Barang</label>
                        <textarea name="wa_template_serah_terima" rows="4" style="font-size:12px;font-family:monospace;"><?= e($settings['wa_template_serah_terima'] ?? '') ?></textarea>
                    </div>

                    <div class="field">
                        <label>Template: Bukti Pengembalian Selesai</label>
                        <textarea name="wa_template_pengembalian" rows="4" style="font-size:12px;font-family:monospace;"><?= e($settings['wa_template_pengembalian'] ?? '') ?></textarea>
                    </div>

                    <div class="field" style="grid-column: 1 / -1;">
                        <label>Template: Pengingat Jatuh Tempo / Keterlambatan</label>
                        <textarea name="wa_template_pengingat" rows="3" style="font-size:12px;font-family:monospace;"><?= e($settings['wa_template_pengingat'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="form-actions" style="margin-top:16px;">
        <button type="submit" class="btn btn-primary" style="padding:10px 24px;font-size:14px;">
            Simpan Seluruh Pengaturan
        </button>
    </div>
</form>

<script>
function togglePasswordVisibility(id) {
    const input = document.getElementById(id);
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
}

function adjustProviderEndpoint(provider) {
    const epInput = document.getElementById('waEndpointInput');
    if (!epInput) return;
    if (provider === 'fonnte') {
        epInput.value = 'https://api.fonnte.com/send';
    } else if (provider === 'wablas') {
        epInput.value = 'https://api.wablas.com/api/send-message';
    } else if (provider === 'starsender') {
        epInput.value = 'https://api.starsender.online/api/send';
    }
}

function runWaTest() {
    const phone = document.getElementById('waTestPhone').value.trim();
    const resultDiv = document.getElementById('waTestResult');
    const btn = document.getElementById('btnTestWa');

    if (!phone) {
        alert('Masukkan nomor WhatsApp tujuan tes terlebih dahulu.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = 'Sedang Mengirim...';
    resultDiv.style.display = 'block';
    resultDiv.className = '';
    resultDiv.innerHTML = '<span style="color:#2563eb;">Menghubungkan ke Gateway WhatsApp...</span>';

    const formData = new FormData();
    formData.append('test_phone', phone);

    fetch('api_test_wa.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = 'Kirim Pesan Uji Coba';
        if (data.success) {
            resultDiv.innerHTML = '<div style="color:#16a34a;background:#dcfce7;padding:8px 12px;border-radius:6px;border:1px solid #86efac;"><strong>✅ Berhasil:</strong> ' + data.message + '</div>';
        } else {
            let extra = '';
            if (data.is_fallback) {
                extra = ' <a href="' + data.url + '" target="_blank" style="text-decoration:underline;color:#0369a1;font-weight:700;">Buka WhatsApp Web Langsung</a>';
            }
            resultDiv.innerHTML = '<div style="color:#991b1b;background:#fee2e2;padding:8px 12px;border-radius:6px;border:1px solid #fca5a5;"><strong>⚠️ Perhatian:</strong> ' + data.message + extra + '</div>';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = 'Kirim Pesan Uji Coba';
        resultDiv.innerHTML = '<div style="color:#991b1b;background:#fee2e2;padding:8px 12px;border-radius:6px;border:1px solid #fca5a5;"><strong>❌ Kesalahan:</strong> ' + err.message + '</div>';
    });
}
</script>

<?php page_end(); ?>