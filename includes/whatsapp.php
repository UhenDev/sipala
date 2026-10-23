<?php

/**
 * FILE       : includes/whatsapp.php
 * MODUL      : Layanan Gateway & Bot WhatsApp Laboratorium SIPALA
 * FUNGSI     : Mengirim pesan notifikasi otomatis dan interaktif ke siswa dan laboran via Bot WhatsApp
 * PROVIDER   : Mendukung Fonnte, Wablas, Starsender, Node.js Baileys/Custom Webhook, serta Click-to-Chat Fallback
 * AKSES      : Seluruh Modul Transaksi & Notifikasi SIPALA
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Mengambil seluruh parameter konfigurasi WhatsApp dari tabel pengaturan
 */
function get_wa_config(): array
{
    static $configCache = null;
    if ($configCache !== null) {
        return $configCache;
    }

    try {
        $pdo = db();
        $rows = $pdo->query("SELECT nama, nilai FROM pengaturan WHERE nama LIKE 'wa_%' OR nama IN ('nama_aplikasi', 'nama_instansi', 'lokasi_ruang_lab', 'kontak_lab_wa')")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        $rows = [];
    }

    $defaultTemplates = [
        'wa_template_pengajuan' => "🔔 *NOTIFIKASI SIPALA - PENGAJUAN PINJAMAN BARU*\n\nHalo Admin Lab, terdapat pengajuan peminjaman baru:\n• *Peminjam:* {nama} ({kelas})\n• *NIS:* {nis}\n• *Kode:* {kode_peminjaman}\n• *Alat:* {alat} ({jumlah} unit)\n• *Tenggat Kembali:* {tgl_kembali}\n• *Keperluan:* {keperluan}\n\nSilakan tinjau dan proses persetujuan di sistem SIPALA.",
        'wa_template_pengajuan_siswa' => "📋 *SIPALA - BUKTI PENGAJUAN PINJAMAN*\n\nHalo *{nama}*,\nPengajuan peminjaman alat lab Anda telah berhasil dikirim:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat} ({jumlah} unit)\n• *Rencana Kembali:* {tgl_kembali}\n• *Status:* Menunggu Persetujuan Petugas Lab\n\nNotifikasi akan dikirimkan kembali setelah permohonan disetujui.",
        'wa_template_disetujui' => "✅ *SIPALA - PEMINJAMAN DISETUJUI*\n\nHalo *{nama}*,\nPengajuan peminjaman alat laboratorium Anda telah *DISETUJUI*:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat}\n• *Lokasi Pengambilan:* {lokasi_ruang_lab}\n• *Batas Pengembalian:* {tgl_kembali}\n\nSilakan datang ke ruang laboratorium untuk serah terima barang fisik dengan membawa kartu siswa/RFID Anda.",
        'wa_template_ditolak' => "❌ *SIPALA - PENGAJUAN PINJAMAN DITOLAK*\n\nHalo *{nama}*,\nMohon maaf, pengajuan peminjaman alat *{alat}* (Kode: {kode_peminjaman}) *DITOLAK* oleh petugas laboratorium.\n• *Alasan:* {alasan}\n\nSilakan hubungi petugas lab atau ajukan kembali sesuai jadwal praktikum.",
        'wa_template_serah_terima' => "📦 *SIPALA - SERAH TERIMA BARANG BERHASIL*\n\nHalo *{nama}*,\nBarang laboratorium telah diserahterimakan kepada Anda:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat}\n• *Unit Fisik:* {kode_unit}\n• *Tanggal Pinjam:* {tgl_pinjam}\n• *Batas Jatuh Tempo:* {tgl_kembali}\n\n⚠️ *Perhatian:* Harap menjaga kondisi alat dengan baik. Keterlambatan pengembalian akan dikenakan denda sesuai peraturan lab.",
        'wa_template_pengembalian' => "🎉 *SIPALA - PENGEMBALIAN ALAT SELESAI*\n\nHalo *{nama}*,\nTerima kasih telah mengembalikan alat laboratorium:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat}\n• *Kondisi Akhir:* {kondisi}\n• *Keterlambatan:* {hari_terlambat} hari\n• *Denda:* {denda} ({status_denda})\n\nCatatan: {catatan}\nTerima kasih telah mematuhi tata tertib Laboratorium {nama_instansi}!",
        'wa_template_pengingat' => "⏰ *SIPALA - PENGINGAT PENGEMBALIAN ALAT LAB*\n\nHalo *{nama}*,\nMengingatkan bahwa peminjaman alat laboratorium Anda memiliki jadwal pengembalian:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat}\n• *Jatuh Tempo:* {tgl_kembali}\n• *Status Saat Ini:* {status_jatuh_tempo}\n\nHarap segera mengembalikan alat tersebut ke {lokasi_ruang_lab}. Terima kasih atas kerja samanya."
    ];

    $configCache = [
        'status' => (string)($rows['wa_gateway_status'] ?? 'aktif'),
        'provider' => (string)($rows['wa_gateway_provider'] ?? 'fonnte'),
        'api_token' => trim((string)($rows['wa_api_token'] ?? '')),
        'api_endpoint' => trim((string)($rows['wa_api_endpoint'] ?? 'https://api.fonnte.com/send')),
        'admin_phone' => trim((string)($rows['wa_admin_phone'] ?? ($rows['kontak_lab_wa'] ?? '081234567890'))),
        'nama_aplikasi' => (string)($rows['nama_aplikasi'] ?? 'SIPALA'),
        'nama_instansi' => (string)($rows['nama_instansi'] ?? 'SMKN 1 PANYINGKIRAN'),
        'lokasi_ruang_lab' => (string)($rows['lokasi_ruang_lab'] ?? 'Ruang Lab Komputer RPL'),
        'templates' => [
            'pengajuan' => $rows['wa_template_pengajuan'] ?? $defaultTemplates['wa_template_pengajuan'],
            'pengajuan_siswa' => $rows['wa_template_pengajuan_siswa'] ?? $defaultTemplates['wa_template_pengajuan_siswa'],
            'disetujui' => $rows['wa_template_disetujui'] ?? $defaultTemplates['wa_template_disetujui'],
            'ditolak' => $rows['wa_template_ditolak'] ?? $defaultTemplates['wa_template_ditolak'],
            'serah_terima' => $rows['wa_template_serah_terima'] ?? $defaultTemplates['wa_template_serah_terima'],
            'pengembalian' => $rows['wa_template_pengembalian'] ?? $defaultTemplates['wa_template_pengembalian'],
            'pengingat' => $rows['wa_template_pengingat'] ?? $defaultTemplates['wa_template_pengingat']
        ]
    ];

    return $configCache;
}

/**
 * Membersihkan dan mengubah nomor telepon ke format internasional standar WhatsApp (628xxxxxxxxxx)
 */
function format_wa_phone(string $phone): string
{
    // Hapus spasi, strip, kurung, dan karakter non-digit
    $clean = preg_replace('/[^\d]/', '', $phone);
    if ($clean === '') return '';

    // Jika diawali 08, ganti 0 dengan 62
    if (str_starts_with($clean, '0')) {
        $clean = '62' . substr($clean, 1);
    }
    // Jika diawali 8 tanpa 62/0, tambahkan 62
    elseif (str_starts_with($clean, '8')) {
        $clean = '62' . $clean;
    }

    return $clean;
}

/**
 * Menghasilkan tautan langsung WhatsApp Click-to-Chat (wa.me)
 */
function build_wa_click_url(string $phone, string $message): string
{
    $formattedPhone = format_wa_phone($phone);
    return 'https://wa.me/' . $formattedPhone . '?text=' . rawurlencode($message);
}

/**
 * Mengirim pesan WhatsApp melalui Bot / Gateway API
 *
 * @param string $phone   Nomor tujuan (mendukung format 0812..., 62812..., +62812...)
 * @param string $message Teks pesan
 * @return array          ['success' => bool, 'message' => string, 'url' => string, 'provider' => string, 'raw' => mixed]
 */
function send_wa_message(string $phone, string $message): array
{
    $cfg = get_wa_config();
    $targetPhone = format_wa_phone($phone);
    $directUrl = build_wa_click_url($targetPhone, $message);

    if ($targetPhone === '') {
        return [
            'success' => false,
            'message' => 'Nomor WhatsApp tidak valid atau kosong.',
            'url' => '#',
            'provider' => 'none'
        ];
    }

    // Jika gateway nonaktif atau token belum diisi, sediakan tautan wa.me
    if ($cfg['status'] !== 'aktif' || empty($cfg['api_token'])) {
        return [
            'success' => false,
            'message' => 'Gateway Bot WhatsApp belum aktif atau API Token belum dikonfigurasi.',
            'url' => $directUrl,
            'provider' => 'direct_link',
            'is_fallback' => true
        ];
    }

    $provider = strtolower($cfg['provider']);
    $token = $cfg['api_token'];
    $endpoint = !empty($cfg['api_endpoint']) ? $cfg['api_endpoint'] : 'https://api.fonnte.com/send';

    $payload = [];
    $headers = [];

    switch ($provider) {
        case 'fonnte':
            $endpoint = !empty($cfg['api_endpoint']) ? $cfg['api_endpoint'] : 'https://api.fonnte.com/send';
            $payload = [
                'target' => $targetPhone,
                'message' => $message,
                'countryCode' => '62'
            ];
            $headers = [
                'Authorization: ' . $token
            ];
            break;

        case 'wablas':
            $endpoint = !empty($cfg['api_endpoint']) ? $cfg['api_endpoint'] : 'https://api.wablas.com/api/send-message';
            $payload = [
                'phone' => $targetPhone,
                'message' => $message
            ];
            $headers = [
                'Authorization: ' . $token
            ];
            break;

        case 'starsender':
            $endpoint = !empty($cfg['api_endpoint']) ? $cfg['api_endpoint'] : 'https://api.starsender.online/api/send';
            $payload = [
                'message' => $message,
                'tujuan' => $targetPhone
            ];
            $headers = [
                'apikey: ' . $token
            ];
            break;

        case 'custom':
        default:
            $endpoint = !empty($cfg['api_endpoint']) ? $cfg['api_endpoint'] : 'https://api.fonnte.com/send';
            $payload = [
                'target' => $targetPhone,
                'phone' => $targetPhone,
                'message' => $message,
                'text' => $message
            ];
            $headers = [
                'Authorization: ' . $token,
                'Content-Type: application/json'
            ];
            break;
    }

    // Eksekusi HTTP Request via cURL dengan proteksi timeout ketat (maks 5 detik)
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        if ($provider === 'custom') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            error_log("SIPALA WhatsApp cURL Error: $err");
            return [
                'success' => false,
                'message' => "Gagal terhubung ke API WhatsApp: $err",
                'url' => $directUrl,
                'provider' => $provider,
                'is_fallback' => true
            ];
        }

        $resDecoded = json_decode((string)$response, true);
        $isOk = ($httpCode >= 200 && $httpCode < 300);

        // Validasi spesifik Fonnte/Wablas JSON status
        if (is_array($resDecoded)) {
            if (isset($resDecoded['status']) && ($resDecoded['status'] === false || $resDecoded['status'] === 'error')) {
                $isOk = false;
            }
        }

        return [
            'success' => $isOk,
            'message' => $isOk ? 'Pesan WhatsApp berhasil dikirim.' : "Gateway merespons kode HTTP $httpCode",
            'url' => $directUrl,
            'provider' => $provider,
            'response' => $resDecoded ?: $response
        ];
    } catch (Throwable $ex) {
        error_log("SIPALA WhatsApp Exception: " . $ex->getMessage());
        return [
            'success' => false,
            'message' => 'Terjadi kesalahan sistem saat mengirim pesan WA: ' . $ex->getMessage(),
            'url' => $directUrl,
            'provider' => $provider,
            'is_fallback' => true
        ];
    }
}

/**
 * Menyusun dan mengirim notifikasi WhatsApp berbasis event transaksi
 *
 * @param string $eventType pengajuan | pengajuan_siswa | disetujui | ditolak | serah_terima | pengembalian | pengingat
 * @param array  $data      Data kontekstual ({nama}, {alat}, {tgl_kembali}, dsb.)
 * @return array            Hasil eksekusi pengiriman
 */
function send_wa_notification(string $eventType, array $data): array
{
    $cfg = get_wa_config();
    $templates = $cfg['templates'];
    $rawTemplate = $templates[$eventType] ?? '';

    if ($rawTemplate === '') {
        return ['success' => false, 'message' => "Template notifikasi '$eventType' tidak ditemukan."];
    }

    // Default placeholders
    $data['nama_aplikasi'] = $cfg['nama_aplikasi'];
    $data['nama_instansi'] = $cfg['nama_instansi'];
    $data['lokasi_ruang_lab'] = $cfg['lokasi_ruang_lab'];

    // Format tanggal jika ada
    if (!empty($data['tgl_pinjam'])) {
        $data['tgl_pinjam'] = format_tanggal_indonesia($data['tgl_pinjam']);
    }
    if (!empty($data['tgl_kembali'])) {
        $data['tgl_kembali'] = format_tanggal_indonesia($data['tgl_kembali']);
    }
    if (isset($data['denda'])) {
        $data['denda'] = is_numeric($data['denda']) ? format_rupiah((float)$data['denda']) : $data['denda'];
    }

    // Substitusi seluruh placeholder {variabel}
    $message = $rawTemplate;
    foreach ($data as $key => $val) {
        if (is_scalar($val)) {
            $message = str_replace('{' . $key . '}', (string)$val, $message);
        }
    }

    // Tentukan nomor tujuan (Admin Lab atau Siswa)
    $targetPhone = '';
    if ($eventType === 'pengajuan') {
        $targetPhone = $cfg['admin_phone'];
    } else {
        $targetPhone = (string)($data['no_hp'] ?? '');
        // Jika tidak ada di parameter data, coba cari dari database via user_id
        if (empty($targetPhone) && !empty($data['user_id'])) {
            try {
                $st = db()->prepare("SELECT no_hp FROM users WHERE id = ?");
                $st->execute([(int)$data['user_id']]);
                $targetPhone = (string)($st->fetchColumn() ?: '');
            } catch (Throwable $e) {}
        }
    }

    if (empty($targetPhone)) {
        return [
            'success' => false,
            'message' => 'Nomor WhatsApp tujuan tidak ditemukan.',
            'url' => '#',
            'text' => $message
        ];
    }

    $res = send_wa_message($targetPhone, $message);
    $res['text'] = $message;
    $res['phone'] = $targetPhone;

    // Catat ke audit log untuk transparansi sistem
    try {
        $statusStr = $res['success'] ? 'TERKIRIM' : 'FALLBACK/GAGAL';
        audit_log('NOTIFIKASI_WA', 'WHATSAPP', $data['peminjaman_id'] ?? null, "Mengirim WA ($eventType) ke $targetPhone [$statusStr]");
    } catch (Throwable $e) {}

    return $res;
}
