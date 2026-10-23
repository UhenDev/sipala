<?php

/**
 * FILE       : database/seed_peralatan.php
 * MODUL      : Seeder Koleksi Peralatan Laboratorium Komputer RPL
 * FUNGSI     : Menambahkan kumpulan master peralatan dan unit fisik nyata dalam jumlah banyak untuk pengujian sistem
 * AKSES      : CLI Terminal (php database/seed_peralatan.php)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();

echo "========================================================\n";
echo "   SIPALA - SEEDER DATA PERALATAN LABORATORIUM RPL       \n";
echo "========================================================\n\n";

// Pastikan folder uploads/peralatan tersedia
$uploadDir = __DIR__ . '/../assets/uploads/peralatan';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// 1. DAFTAR KATEGORI
$categories = [
    'IoT & Embedded Systems' => 'Mikrokontroler, modul sensor, kit robotika, dan development board IoT untuk praktikum perangkat pintar.',
    'Jaringan Komputer' => 'Router, switch managed/unmanaged, access point, tang crimping, tester kabel LAN, dan perangkat transmisi jaringan.',
    'Hardware & Servis Komputer' => 'Obeng presisi, multimeter digital, solder station temperatur, PSU tester, dan alat diagnostik reparasi PC/laptop.',
    'Multimedia & Desain UI/UX' => 'Pen tablet grafis, kamera dokumentasi sistem, tripod studio, wireless microphone, dan pencahayaan digital.',
    'Peripheral & Testing Tools' => 'Barcode/QR scanner handheld, smart card reader RFID, flashdisk bootable multi-OS, portable SSD, dan monitor eksternal.'
];

$categoryMap = [];
$stCatCheck = $pdo->prepare("SELECT id FROM kategori WHERE nama_kategori = ?");
$stCatIns = $pdo->prepare("INSERT INTO kategori (nama_kategori, deskripsi) VALUES (?, ?)");

foreach ($categories as $catName => $catDesc) {
    $stCatCheck->execute([$catName]);
    $catId = $stCatCheck->fetchColumn();
    if (!$catId) {
        $stCatIns->execute([$catName, $catDesc]);
        $catId = (int)$pdo->lastInsertId();
        echo "[+] Kategori baru dibuat: $catName (ID: $catId)\n";
    } else {
        $catId = (int)$catId;
        echo "[i] Kategori sudah ada: $catName (ID: $catId)\n";
    }
    $categoryMap[$catName] = $catId;
}
echo "\n";

// 2. FUNGSI GENERATOR GAMBAR ALAT LAB (PHP GD)
function generateEquipmentImage(string $filePath, string $name, string $code, string $category, string $accentHex): void {
    if (file_exists($filePath)) {
        return; // Jangan generate ulang jika sudah ada
    }

    $w = 640;
    $h = 480;
    $im = imagecreatetruecolor($w, $h);

    // Parse accent color
    $hex = ltrim($accentHex, '#');
    $ar = hexdec(substr($hex, 0, 2));
    $ag = hexdec(substr($hex, 2, 2));
    $ab = hexdec(substr($hex, 4, 2));

    // Dark sleek gradient background
    for ($y = 0; $y < $h; $y++) {
        $ratio = $y / $h;
        $r = (int)(15 + (26 - 15) * $ratio);
        $g = (int)(23 + (36 - 23) * $ratio);
        $b = (int)(42 + (58 - 42) * $ratio);
        $c = imagecolorallocate($im, $r, $g, $b);
        imageline($im, 0, $y, $w, $y, $c);
    }

    // Grid lines for high-tech aesthetic
    $gridColor = imagecolorallocate($im, 30, 41, 59);
    for ($x = 0; $x < $w; $x += 40) {
        imageline($im, $x, 0, $x, $h, $gridColor);
    }
    for ($y = 0; $y < $h; $y += 40) {
        imageline($im, 0, $y, $w, $y, $gridColor);
    }

    // Outer subtle card frame
    $frameColor = imagecolorallocate($im, 51, 65, 85);
    imagerectangle($im, 20, 20, $w - 21, $h - 21, $frameColor);

    // Accent header bar
    $accentColor = imagecolorallocate($im, $ar, $ag, $ab);
    imagefilledrectangle($im, 20, 20, $w - 21, 28, $accentColor);

    // Category pill badge
    $badgeBg = imagecolorallocate($im, (int)($ar * 0.25), (int)($ag * 0.25), (int)($ab * 0.25));
    imagefilledrectangle($im, 40, 50, 40 + (strlen($category) * 8) + 24, 76, $badgeBg);
    imagerectangle($im, 40, 50, 40 + (strlen($category) * 8) + 24, 76, $accentColor);
    imagestring($im, 3, 52, 56, strtoupper($category), $accentColor);

    // Code pill
    $codeBg = imagecolorallocate($im, 30, 41, 59);
    $white = imagecolorallocate($im, 255, 255, 255);
    $textMuted = imagecolorallocate($im, 148, 163, 184);
    imagefilledrectangle($im, $w - 180, 50, $w - 40, 76, $codeBg);
    imagerectangle($im, $w - 180, 50, $w - 40, 76, $frameColor);
    imagestring($im, 3, $w - 165, 56, $code, $white);

    // Central Device Graphic Box
    $boxX1 = 120;
    $boxY1 = 100;
    $boxX2 = $w - 120;
    $boxY2 = 320;
    $boxBg = imagecolorallocate($im, 15, 23, 42);
    imagefilledrectangle($im, $boxX1, $boxY1, $boxX2, $boxY2, $boxBg);
    imagerectangle($im, $boxX1, $boxY1, $boxX2, $boxY2, $accentColor);

    // Abstract graphic icons based on category
    $symbolColor = imagecolorallocate($im, $ar, $ag, $ab);
    $symbolDim = imagecolorallocate($im, (int)($ar * 0.5), (int)($ag * 0.5), (int)($ab * 0.5));

    // Decorative inner elements
    imagefilledellipse($im, (int)(($boxX1 + $boxX2) / 2), (int)(($boxY1 + $boxY2) / 2) - 15, 90, 90, $symbolDim);
    imageellipse($im, (int)(($boxX1 + $boxX2) / 2), (int)(($boxY1 + $boxY2) / 2) - 15, 110, 110, $symbolColor);
    imageellipse($im, (int)(($boxX1 + $boxX2) / 2), (int)(($boxY1 + $boxY2) / 2) - 15, 130, 130, $frameColor);

    // Circuit / Interface crosshairs
    $cx = (int)(($boxX1 + $boxX2) / 2);
    $cy = (int)(($boxY1 + $boxY2) / 2) - 15;
    imageline($im, $cx - 80, $cy, $cx + 80, $cy, $symbolDim);
    imageline($im, $cx, $cy - 80, $cx, $cy + 80, $symbolDim);

    // Corner decorative markers
    $cm = 12;
    imageline($im, $boxX1 + 8, $boxY1 + 8, $boxX1 + 8 + $cm, $boxY1 + 8, $accentColor);
    imageline($im, $boxX1 + 8, $boxY1 + 8, $boxX1 + 8, $boxY1 + 8 + $cm, $accentColor);
    imageline($im, $boxX2 - 8, $boxY1 + 8, $boxX2 - 8 - $cm, $boxY1 + 8, $accentColor);
    imageline($im, $boxX2 - 8, $boxY1 + 8, $boxX2 - 8, $boxY1 + 8 + $cm, $accentColor);
    imageline($im, $boxX1 + 8, $boxY2 - 8, $boxX1 + 8 + $cm, $boxY2 - 8, $accentColor);
    imageline($im, $boxX1 + 8, $boxY2 - 8, $boxX1 + 8, $boxY2 - 8 - $cm, $accentColor);
    imageline($im, $boxX2 - 8, $boxY2 - 8, $boxX2 - 8 - $cm, $boxY2 - 8, $accentColor);
    imageline($im, $boxX2 - 8, $boxY2 - 8, $boxX2 - 8, $boxY2 - 8 - $cm, $accentColor);

    // Device Badge Label inside box
    $shortName = strlen($name) > 34 ? substr($name, 0, 31) . '...' : $name;
    $textX = $cx - (int)(strlen($shortName) * 4.5);
    imagestring($im, 4, max(130, $textX), $boxY2 - 38, $shortName, $white);

    // Bottom info area
    $lblLab = 'LABORATORIUM KOMPUTER & RPL - SMKN 1 PANYINGKIRAN';
    imagestring($im, 2, (int)($w / 2 - (strlen($lblLab) * 3)), 345, $lblLab, $textMuted);

    // Name banner at bottom
    imagefilledrectangle($im, 40, 375, $w - 40, 445, imagecolorallocate($im, 15, 23, 42));
    imagerectangle($im, 40, 375, $w - 40, 445, $frameColor);

    // Title text wrapped nicely
    $line1 = strlen($name) > 42 ? substr($name, 0, 40) . '...' : $name;
    imagestring($im, 5, 55, 390, $line1, $white);
    imagestring($im, 2, 55, 418, "Master Inventaris & Unit Fisik Terverifikasi SIPALA", $accentColor);

    imagejpeg($im, $filePath, 90);
    imagedestroy($im);
}

// 3. DAFTAR MASTER PERALATAN DAN UNIT LENGKAP
$catalogData = [
    // Kategori 1: IoT & Embedded Systems (Accent: #10b981 - Emerald)
    [
        'kode' => 'IOT-ARD-001',
        'nama' => 'Arduino Uno R3 DIP Starter Kit',
        'kategori' => 'IoT & Embedded Systems',
        'lokasi' => 'Rak IoT Lab RPL - Box A1',
        'kondisi' => 'Baik',
        'accent' => '#10b981',
        'deskripsi' => 'Kit mikrokontroler ATmega328P lengkap dengan kabel USB tipe A-B, breadboard 830 titik, resistor pack, LED, dan jumper wire untuk modul praktikum embedded system tingkat dasar hingga menengah.',
        'units' => [
            ['kode' => 'IOTARD01-001', 'sn' => 'SN-ARD328-2026-001', 'rfid' => 'E20000190101', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTARD01-002', 'sn' => 'SN-ARD328-2026-002', 'rfid' => 'E20000190102', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTARD01-003', 'sn' => 'SN-ARD328-2026-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTARD01-004', 'sn' => 'SN-ARD328-2026-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTARD01-005', 'sn' => 'SN-ARD328-2026-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTARD01-006', 'sn' => 'SN-ARD328-2026-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTARD01-007', 'sn' => 'SN-ARD328-2026-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTARD01-008', 'sn' => 'SN-ARD328-2026-008', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTARD01-009', 'sn' => 'SN-ARD328-2026-009', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTARD01-010', 'sn' => 'SN-ARD328-2026-010', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTARD01-011', 'sn' => 'SN-ARD328-2026-011', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTARD01-012', 'sn' => 'SN-ARD328-2026-012', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'IOT-ESP-002',
        'nama' => 'ESP32 NodeMCU Wi-Fi & Bluetooth Dev Board',
        'kategori' => 'IoT & Embedded Systems',
        'lokasi' => 'Rak IoT Lab RPL - Box A2',
        'kondisi' => 'Baik',
        'accent' => '#10b981',
        'deskripsi' => 'Board mikrokontroler dual-core Tensilica Xtensa 32-bit LX6 dengan konektivitas Wi-Fi 802.11 b/g/n dan Bluetooth 4.2 BLE untuk proyek IoT web service, REST API, dan MQTT subscriber/publisher.',
        'units' => [
            ['kode' => 'IOTESP02-001', 'sn' => 'SN-ESP32-WROOM-001', 'rfid' => 'E20000190201', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-002', 'sn' => 'SN-ESP32-WROOM-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-003', 'sn' => 'SN-ESP32-WROOM-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-004', 'sn' => 'SN-ESP32-WROOM-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-005', 'sn' => 'SN-ESP32-WROOM-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-006', 'sn' => 'SN-ESP32-WROOM-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-007', 'sn' => 'SN-ESP32-WROOM-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-008', 'sn' => 'SN-ESP32-WROOM-008', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-009', 'sn' => 'SN-ESP32-WROOM-009', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-010', 'sn' => 'SN-ESP32-WROOM-010', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-011', 'sn' => 'SN-ESP32-WROOM-011', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-012', 'sn' => 'SN-ESP32-WROOM-012', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-013', 'sn' => 'SN-ESP32-WROOM-013', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-014', 'sn' => 'SN-ESP32-WROOM-014', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTESP02-015', 'sn' => 'SN-ESP32-WROOM-015', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'IOT-RPI-003',
        'nama' => 'Raspberry Pi 4 Model B (4GB RAM) Single Board Computer',
        'kategori' => 'IoT & Embedded Systems',
        'lokasi' => 'Lemari IoT Khusus - Rak A3',
        'kondisi' => 'Baik',
        'accent' => '#10b981',
        'deskripsi' => 'Komputer mini Quad Core Cortex-A72 1.5GHz, 4GB LPDDR4, dual display micro-HDMI 4K, Gigabit Ethernet, USB 3.0 untuk deployment server web lokal, computer vision, dan AI edge computing.',
        'units' => [
            ['kode' => 'IOTRPI03-001', 'sn' => 'SN-RPI4B-4GB-001', 'rfid' => 'E20000190301', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTRPI03-002', 'sn' => 'SN-RPI4B-4GB-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTRPI03-003', 'sn' => 'SN-RPI4B-4GB-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTRPI03-004', 'sn' => 'SN-RPI4B-4GB-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTRPI03-005', 'sn' => 'SN-RPI4B-4GB-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTRPI03-006', 'sn' => 'SN-RPI4B-4GB-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTRPI03-007', 'sn' => 'SN-RPI4B-4GB-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTRPI03-008', 'sn' => 'SN-RPI4B-4GB-008', 'rfid' => null, 'status' => 'MAINTENANCE', 'kondisi' => 'Rusak Ringan'],
        ]
    ],
    [
        'kode' => 'IOT-SNS-004',
        'nama' => 'Sensor Kit 37-in-1 Lengkap Praktikum IoT',
        'kategori' => 'IoT & Embedded Systems',
        'lokasi' => 'Rak IoT Lab RPL - Box B1',
        'kondisi' => 'Baik',
        'accent' => '#10b981',
        'deskripsi' => 'Set sensor terpadu 37 jenis komponen mencakup sensor suhu kelembaban DHT11, ultrasonik HC-SR04, PIR motion, LDR, soil moisture, relay 1-channel, gas MQ-2, sound sensor, dan joystick modul.',
        'units' => [
            ['kode' => 'IOTSNS04-001', 'sn' => 'SN-SNS37-2026-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTSNS04-002', 'sn' => 'SN-SNS37-2026-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTSNS04-003', 'sn' => 'SN-SNS37-2026-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTSNS04-004', 'sn' => 'SN-SNS37-2026-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTSNS04-005', 'sn' => 'SN-SNS37-2026-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTSNS04-006', 'sn' => 'SN-SNS37-2026-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTSNS04-007', 'sn' => 'SN-SNS37-2026-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTSNS04-008', 'sn' => 'SN-SNS37-2026-008', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTSNS04-009', 'sn' => 'SN-SNS37-2026-009', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTSNS04-010', 'sn' => 'SN-SNS37-2026-010', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'IOT-CAM-005',
        'nama' => 'Modul Kamera ESP32-CAM OV2640 + Downloader FTDI',
        'kategori' => 'IoT & Embedded Systems',
        'lokasi' => 'Rak IoT Lab RPL - Box B2',
        'kondisi' => 'Baik',
        'accent' => '#10b981',
        'deskripsi' => 'Modul ESP32 dengan sensor kamera OV2640 2MP dan slot MicroSD untuk praktikum streaming video CCTV IoT, face recognition, OCR, dan QR code decoding nirkabel.',
        'units' => [
            ['kode' => 'IOTCAM05-001', 'sn' => 'SN-CAM2640-001', 'rfid' => 'E20000190501', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTCAM05-002', 'sn' => 'SN-CAM2640-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTCAM05-003', 'sn' => 'SN-CAM2640-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTCAM05-004', 'sn' => 'SN-CAM2640-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTCAM05-005', 'sn' => 'SN-CAM2640-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTCAM05-006', 'sn' => 'SN-CAM2640-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTCAM05-007', 'sn' => 'SN-CAM2640-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'IOTCAM05-008', 'sn' => 'SN-CAM2640-008', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],

    // Kategori 2: Jaringan Komputer & Telekomunikasi (Accent: #0284c7 - Sky Blue)
    [
        'kode' => 'NET-CRM-001',
        'nama' => 'Tang Crimping RJ45 & RJ11 Cat5e/Cat6 Pro Heavy Duty',
        'kategori' => 'Jaringan Komputer',
        'lokasi' => 'Rak Jaringan - Tool Bag N1',
        'kondisi' => 'Baik',
        'accent' => '#0284c7',
        'deskripsi' => 'Tang crimping profesional baja karbon tinggi dengan pemotong dan pengupas kabel UTP/STP untuk konektor RJ-45 8P8C dan RJ-11 6P4C/6P2C dilengkapi mekanisme ratchet presisi.',
        'units' => [
            ['kode' => 'NETCRM01-001', 'sn' => 'SN-CRM-HD-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETCRM01-002', 'sn' => 'SN-CRM-HD-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETCRM01-003', 'sn' => 'SN-CRM-HD-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETCRM01-004', 'sn' => 'SN-CRM-HD-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETCRM01-005', 'sn' => 'SN-CRM-HD-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETCRM01-006', 'sn' => 'SN-CRM-HD-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETCRM01-007', 'sn' => 'SN-CRM-HD-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETCRM01-008', 'sn' => 'SN-CRM-HD-008', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETCRM01-009', 'sn' => 'SN-CRM-HD-009', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETCRM01-010', 'sn' => 'SN-CRM-HD-010', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETCRM01-011', 'sn' => 'SN-CRM-HD-011', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETCRM01-012', 'sn' => 'SN-CRM-HD-012', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETCRM01-013', 'sn' => 'SN-CRM-HD-013', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETCRM01-014', 'sn' => 'SN-CRM-HD-014', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'NET-TST-002',
        'nama' => 'Digital Network LAN Cable Tester RJ45 & RJ11 LCD',
        'kategori' => 'Jaringan Komputer',
        'lokasi' => 'Rak Jaringan - Tool Bag N2',
        'kondisi' => 'Baik',
        'accent' => '#0284c7',
        'deskripsi' => 'Tester kabel LAN dengan master dan remote unit indikator LED multi-wire untuk mendeteksi kabel straight-through, crossover, open pin, short circuit, dan miswire secara cepat.',
        'units' => [
            ['kode' => 'NETTST02-001', 'sn' => 'SN-LANTST-001', 'rfid' => 'E20000290201', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETTST02-002', 'sn' => 'SN-LANTST-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETTST02-003', 'sn' => 'SN-LANTST-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETTST02-004', 'sn' => 'SN-LANTST-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETTST02-005', 'sn' => 'SN-LANTST-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETTST02-006', 'sn' => 'SN-LANTST-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETTST02-007', 'sn' => 'SN-LANTST-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETTST02-008', 'sn' => 'SN-LANTST-008', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETTST02-009', 'sn' => 'SN-LANTST-009', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETTST02-010', 'sn' => 'SN-LANTST-010', 'rfid' => null, 'status' => 'RUSAK', 'kondisi' => 'Rusak Berat'],
        ]
    ],
    [
        'kode' => 'NET-MTK-003',
        'nama' => 'Router Mikrotik RouterBOARD RB750Gr3 (hEX) Gigabit',
        'kategori' => 'Jaringan Komputer',
        'lokasi' => 'Lemari Rack Server - Rak J1',
        'kondisi' => 'Baik',
        'accent' => '#0284c7',
        'deskripsi' => 'Router 5 port Gigabit Ethernet berarsitektur MediaTek Dual Core 880MHz, 256MB RAM dengan RouterOS Level 4 untuk praktikum routing statis/dinamis, VLAN, bandwidth queue, firewall NAT, dan VPN.',
        'units' => [
            ['kode' => 'NETMTK03-001', 'sn' => 'SN-HEX750-001', 'rfid' => 'E20000290301', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETMTK03-002', 'sn' => 'SN-HEX750-002', 'rfid' => 'E20000290302', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETMTK03-003', 'sn' => 'SN-HEX750-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETMTK03-004', 'sn' => 'SN-HEX750-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETMTK03-005', 'sn' => 'SN-HEX750-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETMTK03-006', 'sn' => 'SN-HEX750-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETMTK03-007', 'sn' => 'SN-HEX750-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETMTK03-008', 'sn' => 'SN-HEX750-008', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETMTK03-009', 'sn' => 'SN-HEX750-009', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
            ['kode' => 'NETMTK03-010', 'sn' => 'SN-HEX750-010', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'NET-WFI-004',
        'nama' => 'Access Point Dual-Band Gigabit TP-Link Archer C6 MU-MIMO',
        'kategori' => 'Jaringan Komputer',
        'lokasi' => 'Lemari Rack Server - Rak J2',
        'kondisi' => 'Baik',
        'accent' => '#0284c7',
        'deskripsi' => 'Wireless router/AP AC1200 Dual-Band 2.4GHz (300Mbps) dan 5GHz (867Mbps) dengan 4 antena eksternal 5dBi dan teknologi MU-MIMO untuk pengujian hotspot siswa, captive portal, dan isolasi klien.',
        'units' => [
            ['kode' => 'NETWFI04-001', 'sn' => 'SN-ARC6-2026-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETWFI04-002', 'sn' => 'SN-ARC6-2026-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETWFI04-003', 'sn' => 'SN-ARC6-2026-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETWFI04-004', 'sn' => 'SN-ARC6-2026-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETWFI04-005', 'sn' => 'SN-ARC6-2026-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETWFI04-006', 'sn' => 'SN-ARC6-2026-006', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'NET-SWT-005',
        'nama' => 'Switch Hub 16-Port Gigabit TP-Link TL-SG1016D Rackmount',
        'kategori' => 'Jaringan Komputer',
        'lokasi' => 'Lemari Rack Server - Rak J3',
        'kondisi' => 'Baik',
        'accent' => '#0284c7',
        'deskripsi' => 'Switch unmanaged 16 port Gigabit 10/100/1000 Mbps casing besi dengan kapasitas switching 32 Gbps, arsitektur non-blocking, dan fitur hemat daya Green Technology untuk simulasi topologi LAN kelas.',
        'units' => [
            ['kode' => 'NETSWT05-001', 'sn' => 'SN-SG1016D-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETSWT05-002', 'sn' => 'SN-SG1016D-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETSWT05-003', 'sn' => 'SN-SG1016D-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETSWT05-004', 'sn' => 'SN-SG1016D-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETSWT05-005', 'sn' => 'SN-SG1016D-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'NET-PNC-006',
        'nama' => 'Impact Punch Down Tool Krone untuk Patch Panel & Jack',
        'kategori' => 'Jaringan Komputer',
        'lokasi' => 'Rak Jaringan - Tool Bag N3',
        'kondisi' => 'Baik',
        'accent' => '#0284c7',
        'deskripsi' => 'Alat penekan kabel (punch down) dengan sensor potong otomatis pada blok terminal patch panel Cat5e/Cat6 dan faceplate modular keystone jack RJ-45.',
        'units' => [
            ['kode' => 'NETPNC06-001', 'sn' => 'SN-PUNCH-KR-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETPNC06-002', 'sn' => 'SN-PUNCH-KR-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETPNC06-003', 'sn' => 'SN-PUNCH-KR-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETPNC06-004', 'sn' => 'SN-PUNCH-KR-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETPNC06-005', 'sn' => 'SN-PUNCH-KR-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETPNC06-006', 'sn' => 'SN-PUNCH-KR-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETPNC06-007', 'sn' => 'SN-PUNCH-KR-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'NETPNC06-008', 'sn' => 'SN-PUNCH-KR-008', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
        ]
    ],

    // Kategori 3: Hardware & Servis Komputer (Accent: #d97706 - Amber/Orange)
    [
        'kode' => 'HW-OBG-001',
        'nama' => 'Obeng Set Presisi 60-in-1 Magnetic Repair Kit Laptop & PC',
        'kategori' => 'Hardware & Servis Komputer',
        'lokasi' => 'Meja Servis Hardware - Rak S1',
        'kondisi' => 'Baik',
        'accent' => '#d97706',
        'deskripsi' => 'Set mata obeng presisi baja CR-V magnetik lengkap dengan poros fleksibel, pinset ESD anti-statis, pry opening tool, dan suction cup untuk servis reparasi laptop, PC desktop, dan smartphone.',
        'units' => [
            ['kode' => 'HWOBG01-001', 'sn' => 'SN-OBG60-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWOBG01-002', 'sn' => 'SN-OBG60-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWOBG01-003', 'sn' => 'SN-OBG60-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWOBG01-004', 'sn' => 'SN-OBG60-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWOBG01-005', 'sn' => 'SN-OBG60-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWOBG01-006', 'sn' => 'SN-OBG60-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWOBG01-007', 'sn' => 'SN-OBG60-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWOBG01-008', 'sn' => 'SN-OBG60-008', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWOBG01-009', 'sn' => 'SN-OBG60-009', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWOBG01-010', 'sn' => 'SN-OBG60-010', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWOBG01-011', 'sn' => 'SN-OBG60-011', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWOBG01-012', 'sn' => 'SN-OBG60-012', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'HW-MLT-002',
        'nama' => 'Digital Multimeter Auto-Ranging Sanwa CD800a Pro',
        'kategori' => 'Hardware & Servis Komputer',
        'lokasi' => 'Meja Servis Hardware - Rak S2',
        'kondisi' => 'Baik',
        'accent' => '#d97706',
        'deskripsi' => 'Alat ukur multitester digital presisi tinggi dengan proteksi overload, pengujian voltase AC/DC, resistansi, frekuensi, duty cycle, kapasitansi, dan kontinuitas buzzer untuk analisa rangkaian elektronik.',
        'units' => [
            ['kode' => 'HWMLT02-001', 'sn' => 'SN-SANWA-800-001', 'rfid' => 'E20000390201', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWMLT02-002', 'sn' => 'SN-SANWA-800-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWMLT02-003', 'sn' => 'SN-SANWA-800-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWMLT02-004', 'sn' => 'SN-SANWA-800-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWMLT02-005', 'sn' => 'SN-SANWA-800-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWMLT02-006', 'sn' => 'SN-SANWA-800-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWMLT02-007', 'sn' => 'SN-SANWA-800-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWMLT02-008', 'sn' => 'SN-SANWA-800-008', 'rfid' => null, 'status' => 'MAINTENANCE', 'kondisi' => 'Rusak Ringan'],
        ]
    ],
    [
        'kode' => 'HW-SLD-003',
        'nama' => 'Solder Listrik 60W Adjustable Temperature + Stand',
        'kategori' => 'Hardware & Servis Komputer',
        'lokasi' => 'Meja Servis Hardware - Rak S3',
        'kondisi' => 'Baik',
        'accent' => '#d97706',
        'deskripsi' => 'Solder elemen keramik cepat panas dengan kenop pengatur temperatur 200°C - 450°C, pegangan silikon insulasi termal, kabel grounding ESD-safe, dan stand holder logam kokoh.',
        'units' => [
            ['kode' => 'HWSLD03-001', 'sn' => 'SN-SLD60W-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWSLD03-002', 'sn' => 'SN-SLD60W-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWSLD03-003', 'sn' => 'SN-SLD60W-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWSLD03-004', 'sn' => 'SN-SLD60W-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWSLD03-005', 'sn' => 'SN-SLD60W-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWSLD03-006', 'sn' => 'SN-SLD60W-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWSLD03-007', 'sn' => 'SN-SLD60W-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWSLD03-008', 'sn' => 'SN-SLD60W-008', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWSLD03-009', 'sn' => 'SN-SLD60W-009', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWSLD03-010', 'sn' => 'SN-SLD60W-010', 'rfid' => null, 'status' => 'RUSAK', 'kondisi' => 'Rusak Berat'],
        ]
    ],
    [
        'kode' => 'HW-DSB-004',
        'nama' => 'Pompa Sedotan Timah Solder Vacuum Alumunium Desoldering',
        'kategori' => 'Hardware & Servis Komputer',
        'lokasi' => 'Meja Servis Hardware - Rak S3',
        'kondisi' => 'Baik',
        'accent' => '#d97706',
        'deskripsi' => 'Pompa hisap timah mekanik badan aluminium anti-korosi dengan pegas ganda daya hisap tinggi dan ujung nozzle teflon tahan panas untuk pembersihan timah saat melepas komponen.',
        'units' => [
            ['kode' => 'HWDSB04-001', 'sn' => 'SN-DSB-ALU-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDSB04-002', 'sn' => 'SN-DSB-ALU-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDSB04-003', 'sn' => 'SN-DSB-ALU-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDSB04-004', 'sn' => 'SN-DSB-ALU-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDSB04-005', 'sn' => 'SN-DSB-ALU-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDSB04-006', 'sn' => 'SN-DSB-ALU-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDSB04-007', 'sn' => 'SN-DSB-ALU-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDSB04-008', 'sn' => 'SN-DSB-ALU-008', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDSB04-009', 'sn' => 'SN-DSB-ALU-009', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDSB04-010', 'sn' => 'SN-DSB-ALU-010', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDSB04-011', 'sn' => 'SN-DSB-ALU-011', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDSB04-012', 'sn' => 'SN-DSB-ALU-012', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'HW-PST-005',
        'nama' => 'Digital ATX Power Supply Tester LCD 20/24 Pin',
        'kategori' => 'Hardware & Servis Komputer',
        'lokasi' => 'Meja Servis Hardware - Rak S4',
        'kondisi' => 'Baik',
        'accent' => '#d97706',
        'deskripsi' => 'Alat diagnostik PSU komputer dengan layar LCD backlit visual untuk mengukur voltase rel +12V, -12V, +5V, +3.3V, +5VSB, serta nilai Power Good (PG) millisecond demi deteksi PSU drop/short.',
        'units' => [
            ['kode' => 'HWPST05-001', 'sn' => 'SN-PSUTST-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWPST05-002', 'sn' => 'SN-PSUTST-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWPST05-003', 'sn' => 'SN-PSUTST-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWPST05-004', 'sn' => 'SN-PSUTST-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWPST05-005', 'sn' => 'SN-PSUTST-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWPST05-006', 'sn' => 'SN-PSUTST-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'HW-DCK-006',
        'nama' => 'HDD & SSD Docking Station 2.5/3.5 Inch Dual-Bay USB 3.0',
        'kategori' => 'Hardware & Servis Komputer',
        'lokasi' => 'Meja Servis Hardware - Rak S4',
        'kondisi' => 'Baik',
        'accent' => '#d97706',
        'deskripsi' => 'Docking harddisk eksternal ganda SATA I/II/III ke antarmuka USB 3.0 UASP dengan fitur offline standalone disk cloning 1-tombol untuk backup data siswa dan instalasi disk image cepat.',
        'units' => [
            ['kode' => 'HWDCK06-001', 'sn' => 'SN-DOCK-SATA-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDCK06-002', 'sn' => 'SN-DOCK-SATA-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDCK06-003', 'sn' => 'SN-DOCK-SATA-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDCK06-004', 'sn' => 'SN-DOCK-SATA-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'HWDCK06-005', 'sn' => 'SN-DOCK-SATA-005', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],

    // Kategori 4: Multimedia & Desain UI/UX (Accent: #7c3aed - Violet)
    [
        'kode' => 'MM-TAB-001',
        'nama' => 'Drawing Pen Tablet Huion Inspiroy H640P untuk Desain UI/UX',
        'kategori' => 'Multimedia & Desain UI/UX',
        'lokasi' => 'Lemari Multimedia - Rak M1',
        'kondisi' => 'Baik',
        'accent' => '#7c3aed',
        'deskripsi' => 'Graphic tablet 6.3 x 3.9 inch dengan stylus pasif tanpa baterai 8192 levels pressure sensitivity, resolusi 5080 LPI, dan 6 tombol shortcut yang dapat dikustomisasi untuk wireframing & mockup UI/UX.',
        'units' => [
            ['kode' => 'MMTAB01-001', 'sn' => 'SN-HUION-640P-001', 'rfid' => 'E20000490101', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTAB01-002', 'sn' => 'SN-HUION-640P-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTAB01-003', 'sn' => 'SN-HUION-640P-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTAB01-004', 'sn' => 'SN-HUION-640P-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTAB01-005', 'sn' => 'SN-HUION-640P-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTAB01-006', 'sn' => 'SN-HUION-640P-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTAB01-007', 'sn' => 'SN-HUION-640P-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTAB01-008', 'sn' => 'SN-HUION-640P-008', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTAB01-009', 'sn' => 'SN-HUION-640P-009', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
            ['kode' => 'MMTAB01-010', 'sn' => 'SN-HUION-640P-010', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'MM-CAM-002',
        'nama' => 'Kamera Mirrorless Sony Alpha A6000 Kit 16-50mm OSS',
        'kategori' => 'Multimedia & Desain UI/UX',
        'lokasi' => 'Lemari Multimedia Khusus - Safe Box M2',
        'kondisi' => 'Baik',
        'accent' => '#7c3aed',
        'deskripsi' => 'Kamera digital APS-C 24.3 Megapixel, sistem Fast Hybrid Autofocus 179 titik fokus fase, 11 fps continuous shooting, perekaman video Full HD 1080p 60fps untuk dokumentasi proyek dan presentasi produk.',
        'units' => [
            ['kode' => 'MMCAM02-001', 'sn' => 'SN-SONYA6000-001', 'rfid' => 'E20000490201', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMCAM02-002', 'sn' => 'SN-SONYA6000-002', 'rfid' => 'E20000490202', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMCAM02-003', 'sn' => 'SN-SONYA6000-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMCAM02-004', 'sn' => 'SN-SONYA6000-004', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'MM-TRP-003',
        'nama' => 'Tripod Kamera Aluminium Kokoh 1.4m + Phone Mount',
        'kategori' => 'Multimedia & Desain UI/UX',
        'lokasi' => 'Lemari Multimedia - Rak M3',
        'kondisi' => 'Baik',
        'accent' => '#7c3aed',
        'deskripsi' => 'Tripod 3 section kaki aluminium dengan panhead 3-way, level gelembung waterpass terintegrasi, quick release plate standar 1/4 inci, dan penjepit smartphone universal untuk perekaman demonstrasi sistem.',
        'units' => [
            ['kode' => 'MMTRP03-001', 'sn' => 'SN-TRP3110-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTRP03-002', 'sn' => 'SN-TRP3110-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTRP03-003', 'sn' => 'SN-TRP3110-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTRP03-004', 'sn' => 'SN-TRP3110-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTRP03-005', 'sn' => 'SN-TRP3110-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTRP03-006', 'sn' => 'SN-TRP3110-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTRP03-007', 'sn' => 'SN-TRP3110-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMTRP03-008', 'sn' => 'SN-TRP3110-008', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'MM-MIC-004',
        'nama' => 'Wireless Lavalier Microphone Dual Channel 2.4GHz Clip-On',
        'kategori' => 'Multimedia & Desain UI/UX',
        'lokasi' => 'Lemari Multimedia - Rak M1',
        'kondisi' => 'Baik',
        'accent' => '#7c3aed',
        'deskripsi' => 'Set mikrofon nirkabel clip-on dual transmitter dengan peredam kebisingan aktif chip DSP cerdas, jangkauan 20 meter bebas hambatan, kompatibel dengan Type-C, Lightning, dan 3.5mm jack.',
        'units' => [
            ['kode' => 'MMMIC04-001', 'sn' => 'SN-MIC24G-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMMIC04-002', 'sn' => 'SN-MIC24G-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMMIC04-003', 'sn' => 'SN-MIC24G-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMMIC04-004', 'sn' => 'SN-MIC24G-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMMIC04-005', 'sn' => 'SN-MIC24G-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMMIC04-006', 'sn' => 'SN-MIC24G-006', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'MM-RNG-005',
        'nama' => 'Ring Light LED 26cm Dimmable + Tripod Stand 2 Meter',
        'kategori' => 'Multimedia & Desain UI/UX',
        'lokasi' => 'Lemari Multimedia - Rak M3',
        'kondisi' => 'Baik',
        'accent' => '#7c3aed',
        'deskripsi' => 'Lampu pencahayaan studio ring LED diameter 26cm dengan remote kontrol kabel USB, 3 mode temperatur cahaya (White, Warm, Natural), 10 tingkat intensitas reduksi dan light stand 2.1 meter.',
        'units' => [
            ['kode' => 'MMRNG05-001', 'sn' => 'SN-RING26-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMRNG05-002', 'sn' => 'SN-RING26-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMRNG05-003', 'sn' => 'SN-RING26-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMRNG05-004', 'sn' => 'SN-RING26-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMRNG05-005', 'sn' => 'SN-RING26-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'MMRNG05-006', 'sn' => 'SN-RING26-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
        ]
    ],

    // Kategori 5: Peripheral & Testing Tools (Accent: #4f46e5 - Indigo)
    [
        'kode' => 'DEV-SCN-001',
        'nama' => 'Barcode & 2D QR Code Scanner USB Laser Handheld',
        'kategori' => 'Peripheral & Testing Tools',
        'lokasi' => 'Meja Uji Coba Software - Rak D1',
        'kondisi' => 'Baik',
        'accent' => '#4f46e5',
        'deskripsi' => 'Pemindai barcode 1D dan QR Code 2D CMOS sensor dengan mode auto-sense induksi mandiri, decoding cepat langsung dari layar smartphone dan lembar kertas cetak untuk pengujian modul POS dan absensi.',
        'units' => [
            ['kode' => 'DEVSCN01-001', 'sn' => 'SN-QRFST-001', 'rfid' => 'E20000590101', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVSCN01-002', 'sn' => 'SN-QRFST-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVSCN01-003', 'sn' => 'SN-QRFST-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVSCN01-004', 'sn' => 'SN-QRFST-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVSCN01-005', 'sn' => 'SN-QRFST-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVSCN01-006', 'sn' => 'SN-QRFST-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVSCN01-007', 'sn' => 'SN-QRFST-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVSCN01-008', 'sn' => 'SN-QRFST-008', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'DEV-ACR-002',
        'nama' => 'Smart Card Reader & Writer RFID NFC ACR122U USB Contactless',
        'kategori' => 'Peripheral & Testing Tools',
        'lokasi' => 'Meja Uji Coba Software - Rak D1',
        'kondisi' => 'Baik',
        'accent' => '#4f46e5',
        'deskripsi' => 'Reader/writer kartu pintar frekuensi 13.56 MHz standar CCID ISO/IEC 18092 NFC, mendukung kartu Mifare Classic 1K/4K, Ultralight, NTAG213/215/216 untuk integrasi otentikasi RFID SIPALA.',
        'units' => [
            ['kode' => 'DEVACR02-001', 'sn' => 'SN-ACR122U-001', 'rfid' => 'E20000590201', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVACR02-002', 'sn' => 'SN-ACR122U-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVACR02-003', 'sn' => 'SN-ACR122U-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVACR02-004', 'sn' => 'SN-ACR122U-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVACR02-005', 'sn' => 'SN-ACR122U-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVACR02-006', 'sn' => 'SN-ACR122U-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVACR02-007', 'sn' => 'SN-ACR122U-007', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'DEV-FDD-003',
        'nama' => 'Flashdisk Bootable USB 3.2 Gen 1 (64GB) Multi-OS Installer',
        'kategori' => 'Peripheral & Testing Tools',
        'lokasi' => 'Meja Uji Coba Software - Rak D2',
        'kondisi' => 'Baik',
        'accent' => '#4f46e5',
        'deskripsi' => 'Flashdisk logam USB 3.2 tahan banting dengan kecepatan transfer hingga 130 MB/s, terisi bootloader multiboot Ventoy lengkap dengan ISO Windows 10/11 Pro, Ubuntu 24.04 Desktop, dan Debian Server.',
        'units' => [
            ['kode' => 'DEVFDD03-001', 'sn' => 'SN-VENTOY64-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-002', 'sn' => 'SN-VENTOY64-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-003', 'sn' => 'SN-VENTOY64-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-004', 'sn' => 'SN-VENTOY64-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-005', 'sn' => 'SN-VENTOY64-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-006', 'sn' => 'SN-VENTOY64-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-007', 'sn' => 'SN-VENTOY64-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-008', 'sn' => 'SN-VENTOY64-008', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-009', 'sn' => 'SN-VENTOY64-009', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-010', 'sn' => 'SN-VENTOY64-010', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-011', 'sn' => 'SN-VENTOY64-011', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-012', 'sn' => 'SN-VENTOY64-012', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-013', 'sn' => 'SN-VENTOY64-013', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-014', 'sn' => 'SN-VENTOY64-014', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
            ['kode' => 'DEVFDD03-015', 'sn' => 'SN-VENTOY64-015', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'DEV-SSD-004',
        'nama' => 'External Portable NVMe SSD 500GB USB 3.2 Gen 2 Type-C',
        'kategori' => 'Peripheral & Testing Tools',
        'lokasi' => 'Meja Uji Coba Software - Rak D2',
        'kondisi' => 'Baik',
        'accent' => '#4f46e5',
        'deskripsi' => 'Solid State Drive eksternal kecepatan super transfer hingga 1050 MB/s dengan casing aluminium kokoh pendingin pasif untuk migrasi database besar, virtual machine image OVA, dan instalasi Android Studio.',
        'units' => [
            ['kode' => 'DEVSSD04-001', 'sn' => 'SN-SSD500G-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVSSD04-002', 'sn' => 'SN-SSD500G-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVSSD04-003', 'sn' => 'SN-SSD500G-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVSSD04-004', 'sn' => 'SN-SSD500G-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVSSD04-005', 'sn' => 'SN-SSD500G-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVSSD04-006', 'sn' => 'SN-SSD500G-006', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'DEV-MON-005',
        'nama' => 'Monitor Portabel 15.6 Inch Full HD IPS USB Type-C & HDMI',
        'kategori' => 'Peripheral & Testing Tools',
        'lokasi' => 'Lemari Peripheral - Rak D3',
        'kondisi' => 'Baik',
        'accent' => '#4f46e5',
        'deskripsi' => 'Monitor portabel ultra tipis panel IPS 1080p Full HD sudut pandang 178 derajat dengan kabel Type-C tunggal untuk power dan sinyal display, dual speaker stereo, cocok untuk dual-screen coding laptop.',
        'units' => [
            ['kode' => 'DEVMON05-001', 'sn' => 'SN-MON156FHD-001', 'rfid' => 'E20000590501', 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVMON05-002', 'sn' => 'SN-MON156FHD-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVMON05-003', 'sn' => 'SN-MON156FHD-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVMON05-004', 'sn' => 'SN-MON156FHD-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVMON05-005', 'sn' => 'SN-MON156FHD-005', 'rfid' => null, 'status' => 'DIPINJAM', 'kondisi' => 'Baik'],
        ]
    ],
    [
        'kode' => 'DEV-HUB-006',
        'nama' => 'USB 3.0 Hub 7-Port Powered dengan Saklar LED Mandiri',
        'kategori' => 'Peripheral & Testing Tools',
        'lokasi' => 'Meja Uji Coba Software - Rak D3',
        'kondisi' => 'Baik',
        'accent' => '#4f46e5',
        'deskripsi' => 'Hub ekspansi USB 7 port berkecepatan 5 Gbps dengan adaptor daya tambahan 5V 2A dan saklar power on/off individual berlampu indikator LED biru untuk mengontrol banyak perangkat peripheral pengujian.',
        'units' => [
            ['kode' => 'DEVHUB06-001', 'sn' => 'SN-HUB7PORT-001', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVHUB06-002', 'sn' => 'SN-HUB7PORT-002', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVHUB06-003', 'sn' => 'SN-HUB7PORT-003', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVHUB06-004', 'sn' => 'SN-HUB7PORT-004', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVHUB06-005', 'sn' => 'SN-HUB7PORT-005', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVHUB06-006', 'sn' => 'SN-HUB7PORT-006', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVHUB06-007', 'sn' => 'SN-HUB7PORT-007', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
            ['kode' => 'DEVHUB06-008', 'sn' => 'SN-HUB7PORT-008', 'rfid' => null, 'status' => 'TERSEDIA', 'kondisi' => 'Baik'],
        ]
    ]
];

// PREPARED STATEMENTS
$stCheckAlat = $pdo->prepare("SELECT id FROM peralatan WHERE kode_barang = ?");
$stInsAlat = $pdo->prepare("
    INSERT INTO peralatan (kode_barang, nama_barang, kategori_id, jumlah, jumlah_tersedia, kondisi, lokasi, deskripsi, foto, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'aktif')
");
$stUpdAlatFoto = $pdo->prepare("UPDATE peralatan SET foto = ? WHERE id = ?");

$stCheckUnit = $pdo->prepare("SELECT id FROM unit_peralatan WHERE kode_unit = ?");
$stInsUnit = $pdo->prepare("
    INSERT INTO unit_peralatan (peralatan_id, kode_unit, serial_number, rfid_uid, kondisi, lokasi, status, catatan)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

$stCheckRfid = $pdo->prepare("SELECT id FROM rfid_cards WHERE uid = ?");
$stInsRfid = $pdo->prepare("
    INSERT INTO rfid_cards (unit_id, uid, tipe, status, catatan, created_at)
    VALUES (?, ?, 'alat', 'aktif', ?, NOW())
");

$totalAddedMaster = 0;
$totalAddedUnits = 0;
$totalAddedRfid = 0;

foreach ($catalogData as $item) {
    $kode = $item['kode'];
    $nama = $item['nama'];
    $catName = $item['kategori'];
    $catId = $categoryMap[$catName] ?? 2;
    $units = $item['units'];
    $totalQty = count($units);
    $availQty = count(array_filter($units, fn($u) => $u['status'] === 'TERSEDIA' && $u['kondisi'] !== 'Rusak Berat'));

    // Siapkan foto visual
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $kode));
    $photoFileName = 'alat_' . $slug . '.jpg';
    $photoDiskPath = $uploadDir . '/' . $photoFileName;
    $photoRelPath = 'assets/uploads/peralatan/' . $photoFileName;

    generateEquipmentImage($photoDiskPath, $nama, $kode, $catName, $item['accent'] ?? '#0284c7');

    // Cek apakah master alat sudah ada
    $stCheckAlat->execute([$kode]);
    $existingId = $stCheckAlat->fetchColumn();

    if (!$existingId) {
        $stInsAlat->execute([
            $kode,
            $nama,
            $catId,
            $totalQty,
            $availQty,
            $item['kondisi'],
            $item['lokasi'],
            $item['deskripsi'],
            $photoRelPath
        ]);
        $alatId = (int)$pdo->lastInsertId();
        $totalAddedMaster++;
        echo "[+] Master Alat Ditambahkan: $kode - $nama (ID: $alatId)\n";
    } else {
        $alatId = (int)$existingId;
        // Pastikan foto terpasang jika sebelumnya kosong
        $stUpdAlatFoto->execute([$photoRelPath, $alatId]);
        echo "[i] Master Alat sudah terdaftar: $kode - $nama (ID: $alatId)\n";
    }

    // Masukkan Unit Fisik
    foreach ($units as $u) {
        $unitCode = $u['kode'];
        $stCheckUnit->execute([$unitCode]);
        $existingUnitId = $stCheckUnit->fetchColumn();

        if (!$existingUnitId) {
            $stInsUnit->execute([
                $alatId,
                $unitCode,
                $u['sn'],
                $u['rfid'],
                $u['kondisi'],
                $item['lokasi'],
                $u['status'],
                "Unit inventaris Lab Komputer RPL - " . $nama
            ]);
            $unitId = (int)$pdo->lastInsertId();
            $totalAddedUnits++;

            // Jika unit memiliki RFID UID, registrasikan di rfid_cards
            if (!empty($u['rfid'])) {
                $stCheckRfid->execute([$u['rfid']]);
                if (!$stCheckRfid->fetchColumn()) {
                    $stInsRfid->execute([
                        $unitId,
                        $u['rfid'],
                        "Tag RFID Alat: {$nama} ({$unitCode})"
                    ]);
                    $totalAddedRfid++;
                }
            }
        }
    }

    // Sinkronisasi stok master
    sync_peralatan_stock($alatId);
}

// Log audit
audit_log('CREATE', 'PERALATAN', null, "Seeder: Menambahkan $totalAddedMaster master peralatan baru dan $totalAddedUnits unit fisik untuk ujicoba laboratorium");

echo "\n========================================================\n";
echo "   SEEDING PERALATAN SELESAI DENGAN SUKSES!             \n";
echo "========================================================\n";
echo " - Master Peralatan Baru : $totalAddedMaster alat\n";
echo " - Unit Fisik Baru       : $totalAddedUnits unit\n";
echo " - Tag RFID Alat Baru    : $totalAddedRfid kartu/chip\n";
echo " - Total Master Alat DB  : " . $pdo->query("SELECT COUNT(*) FROM peralatan")->fetchColumn() . " jenis\n";
echo " - Total Unit Fisik DB   : " . $pdo->query("SELECT COUNT(*) FROM unit_peralatan")->fetchColumn() . " unit\n";
echo " - Total Kategori DB     : " . $pdo->query("SELECT COUNT(*) FROM kategori")->fetchColumn() . " kategori\n";
echo "========================================================\n";
