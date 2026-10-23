<?php

/**
 * FILE       : database/upgrade_wa_qr.php
 * MODUL      : Migrasi & Pembaruan Basis Data untuk Fitur WhatsApp Gateway & QR Code
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
$pdo = db();

echo "=== SIPALA UPGRADE: WHATSAPP BOT & QR CODE ===" . PHP_EOL;

$defaults = [
    'wa_gateway_status' => ['nilai' => 'aktif', 'deskripsi' => 'Status aktifasi Gateway WhatsApp (aktif/nonaktif)'],
    'wa_gateway_provider' => ['nilai' => 'fonnte', 'deskripsi' => 'Provider WhatsApp (fonnte/wablas/starsender/custom)'],
    'wa_api_token' => ['nilai' => '', 'deskripsi' => 'API Token / Secret Key dari penyedia Bot WhatsApp'],
    'wa_api_endpoint' => ['nilai' => 'https://api.fonnte.com/send', 'deskripsi' => 'URL Endpoint pengiriman pesan WhatsApp'],
    'wa_admin_phone' => ['nilai' => '081234567890', 'deskripsi' => 'Nomor WhatsApp Admin Lab untuk notifikasi masuk'],
    'wa_template_pengajuan' => [
        'nilai' => "🔔 *NOTIFIKASI SIPALA - PENGAJUAN PINJAMAN BARU*\n\nHalo Admin Lab, terdapat pengajuan peminjaman baru:\n• *Peminjam:* {nama} ({kelas})\n• *NIS:* {nis}\n• *Kode:* {kode_peminjaman}\n• *Alat:* {alat} ({jumlah} unit)\n• *Tenggat Kembali:* {tgl_kembali}\n• *Keperluan:* {keperluan}\n\nSilakan tinjau dan proses persetujuan di sistem SIPALA.",
        'deskripsi' => 'Template WA untuk Admin saat ada pengajuan baru'
    ],
    'wa_template_pengajuan_siswa' => [
        'nilai' => "📋 *SIPALA - BUKTI PENGAJUAN PINJAMAN*\n\nHalo *{nama}*,\nPengajuan peminjaman alat lab Anda telah berhasil dikirim:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat} ({jumlah} unit)\n• *Rencana Kembali:* {tgl_kembali}\n• *Status:* Menunggu Persetujuan Petugas Lab\n\nNotifikasi akan dikirimkan kembali setelah permohonan disetujui.",
        'deskripsi' => 'Template WA ke siswa saat pengajuan terkirim'
    ],
    'wa_template_disetujui' => [
        'nilai' => "✅ *SIPALA - PEMINJAMAN DISETUJUI*\n\nHalo *{nama}*,\nPengajuan peminjaman alat laboratorium Anda telah *DISETUJUI*:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat}\n• *Lokasi Pengambilan:* {lokasi_ruang_lab}\n• *Batas Pengembalian:* {tgl_kembali}\n\nSilakan datang ke ruang laboratorium untuk serah terima barang fisik dengan membawa kartu siswa/RFID Anda.",
        'deskripsi' => 'Template WA ke siswa saat pinjaman disetujui'
    ],
    'wa_template_ditolak' => [
        'nilai' => "❌ *SIPALA - PENGAJUAN PINJAMAN DITOLAK*\n\nHalo *{nama}*,\nMohon maaf, pengajuan peminjaman alat *{alat}* (Kode: {kode_peminjaman}) *DITOLAK* oleh petugas laboratorium.\n• *Alasan:* {alasan}\n\nSilakan hubungi petugas lab atau ajukan kembali sesuai jadwal praktikum.",
        'deskripsi' => 'Template WA ke siswa saat pinjaman ditolak'
    ],
    'wa_template_serah_terima' => [
        'nilai' => "📦 *SIPALA - SERAH TERIMA BARANG BERHASIL*\n\nHalo *{nama}*,\nBarang laboratorium telah diserahterimakan kepada Anda:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat}\n• *Unit Fisik:* {kode_unit}\n• *Tanggal Pinjam:* {tgl_pinjam}\n• *Batas Jatuh Tempo:* {tgl_kembali}\n\n⚠️ *Perhatian:* Harap menjaga kondisi alat dengan baik. Keterlambatan pengembalian akan dikenakan denda sesuai peraturan lab.",
        'deskripsi' => 'Template WA ke siswa saat serah terima barang'
    ],
    'wa_template_pengembalian' => [
        'nilai' => "🎉 *SIPALA - PENGEMBALIAN ALAT SELESAI*\n\nHalo *{nama}*,\nTerima kasih telah mengembalikan alat laboratorium:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat}\n• *Kondisi Akhir:* {kondisi}\n• *Keterlambatan:* {hari_terlambat} hari\n• *Denda:* {denda} ({status_denda})\n\nCatatan: {catatan}\nTerima kasih telah mematuhi tata tertib Laboratorium {nama_instansi}!",
        'deskripsi' => 'Template WA ke siswa saat alat dikembalikan'
    ],
    'wa_template_pengingat' => [
        'nilai' => "⏰ *SIPALA - PENGINGAT PENGEMBALIAN ALAT LAB*\n\nHalo *{nama}*,\nMengingatkan bahwa peminjaman alat laboratorium Anda memiliki jadwal pengembalian:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat}\n• *Jatuh Tempo:* {tgl_kembali}\n• *Status Saat Ini:* {status_jatuh_tempo}\n\nHarap segera mengembalikan alat tersebut ke {lokasi_ruang_lab}. Terima kasih atas kerja samanya.",
        'deskripsi' => 'Template WA pengingat batas pengembalian'
    ]
];

$stmtChk = $pdo->prepare('SELECT COUNT(*) FROM pengaturan WHERE nama = ?');
$stmtIns = $pdo->prepare('INSERT INTO pengaturan (nama, nilai, deskripsi) VALUES (?, ?, ?)');

foreach ($defaults as $k => $item) {
    $stmtChk->execute([$k]);
    if ((int)$stmtChk->fetchColumn() === 0) {
        $stmtIns->execute([$k, $item['nilai'], $item['deskripsi']]);
        echo " [+] Menambahkan pengaturan: $k" . PHP_EOL;
    } else {
        echo " [=] Pengaturan sudah ada: $k" . PHP_EOL;
    }
}

// Pastikan panjang kolom nilai di pengaturan cukup untuk template teks
try {
    $pdo->exec("ALTER TABLE `pengaturan` MODIFY COLUMN `nilai` TEXT NOT NULL");
    echo " [+] Kolom pengaturan.nilai dimodifikasi menjadi TEXT." . PHP_EOL;
} catch (Throwable $e) {
    echo " [!] Catatan tipe kolom: " . $e->getMessage() . PHP_EOL;
}

echo "=== UPGRADE SELESAI ===" . PHP_EOL;
