<?php

/**
 * FILE       : includes/barcode.php
 * MODUL      : Generator Barcode Linear Code 128 Vektor SVG (100% Offline & Mandiri)
 * FUNGSI     : Menghasilkan barcode Code 128 Subset B standar industri dalam format Vektor SVG & Data URI
 * DEPENDENSI : 0 (Tanpa GD, tanpa Composer, tanpa pustaka eksternal)
 * AKSES      : Seluruh Modul SIPALA (Admin, Unit Fisik, Pencetakan Label, Verifikasi Scanner)
 */

declare(strict_types=1);

class SipalaBarcode128
{
    /**
     * Pola modul Code 128 (baris 0 - 106)
     * Angka ganjil (indeks 0, 2, 4...) adalah batang hitam (BAR),
     * angka genap (indeks 1, 3, 5...) adalah spasi putih (SPACE).
     */
    private static array $patterns = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112'
    ];

    /**
     * Menghasilkan representasi Barcode Code 128 dalam format Vektor SVG mandiri.
     *
     * @param string $code       Teks kode yang akan dikonversi menjadi barcode (ASCII 32-126)
     * @param int    $height     Tinggi garis barcode dalam pixel
     * @param int    $barWidth   Lebar minimum 1 modul garis dalam pixel (default: 2)
     * @param bool   $showText   Tampilkan teks kode di bawah barcode
     * @param int    $quietZone  Zona tenang (ruang kosong) di sisi kiri & kanan (dalam modul)
     * @return string            Elemen <svg> lengkap yang siap disisipkan ke HTML
     */
    public static function renderSvg(
        string $code,
        int $height = 40,
        int $barWidth = 2,
        bool $showText = false,
        int $quietZone = 10
    ): string {
        $cleanCode = preg_replace('/[^\x20-\x7E]/', '', trim($code));
        if ($cleanCode === '') {
            $cleanCode = 'SIP-ALT';
        }

        // Start Code B (indeks 104)
        $startB = 104;
        $checksum = $startB;
        $sequence = [self::$patterns[$startB]];
        $len = strlen($cleanCode);

        for ($i = 0; $i < $len; $i++) {
            $val = ord($cleanCode[$i]) - 32;
            if ($val < 0 || $val > 95) {
                $val = 0;
            }
            $checksum += ($i + 1) * $val;
            $sequence[] = self::$patterns[$val];
        }

        // Checksum modulo 103
        $sequence[] = self::$patterns[$checksum % 103];
        // Stop Pattern (indeks 106)
        $sequence[] = self::$patterns[106];

        // Hitung total modul
        $totalModules = $quietZone * 2;
        foreach ($sequence as $pat) {
            $patLen = strlen($pat);
            for ($k = 0; $k < $patLen; $k++) {
                $totalModules += (int)$pat[$k];
            }
        }

        $textHeight = $showText ? 16 : 0;
        $totalWidth = $totalModules * $barWidth;
        $totalHeight = $height + $textHeight;

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $totalWidth . ' ' . $totalHeight . '" '
             . 'preserveAspectRatio="xMidYMid meet" style="max-width:100%;height:auto;display:block;margin:0 auto;">';

        // Latar belakang putih solid untuk memastikan kontras optimal bagi scanner optik
        $svg .= '<rect width="' . $totalWidth . '" height="' . $totalHeight . '" fill="#ffffff" />';

        // Garis-garis barcode
        $posX = $quietZone * $barWidth;
        foreach ($sequence as $pat) {
            $patLen = strlen($pat);
            for ($k = 0; $k < $patLen; $k++) {
                $w = (int)$pat[$k] * $barWidth;
                if ($k % 2 === 0) {
                    $svg .= '<rect x="' . $posX . '" y="0" width="' . $w . '" height="' . $height . '" fill="#000000" />';
                }
                $posX += $w;
            }
        }

        // Teks terbaca manusia di bawah barcode
        if ($showText) {
            $svg .= '<text x="' . ($totalWidth / 2) . '" y="' . ($height + 12) . '" '
                  . 'font-family="monospace, Consolas, Courier, sans-serif" font-size="12" font-weight="700" '
                  . 'fill="#0f172a" text-anchor="middle" letter-spacing="1">'
                  . htmlspecialchars($cleanCode, ENT_QUOTES, 'UTF-8')
                  . '</text>';
        }

        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Menghasilkan Data URI SVG (bisa langsung disematkan ke atribut <img src="...">)
     */
    public static function getDataUri(string $code, int $height = 40, int $barWidth = 2, bool $showText = false): string
    {
        $svg = self::renderSvg($code, $height, $barWidth, $showText);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}

/**
 * Helper global untuk merender SVG Barcode Code 128
 */
if (!function_exists('render_code128_svg')) {
    function render_code128_svg(
        string $code,
        int $height = 40,
        int $barWidth = 2,
        bool $showText = false,
        int $quietZone = 10
    ): string {
        return SipalaBarcode128::renderSvg($code, $height, $barWidth, $showText, $quietZone);
    }
}

/**
 * Helper global untuk mendapatkan Data URI Barcode Code 128
 */
if (!function_exists('get_barcode_data_uri')) {
    function get_barcode_data_uri(string $code, int $height = 40, int $barWidth = 2, bool $showText = false): string
    {
        return SipalaBarcode128::getDataUri($code, $height, $barWidth, $showText);
    }
}

/**
 * Helper untuk membuat kode barcode standar SIPALA (Format: SIP-ALT-XXXXXX)
 */
if (!function_exists('generate_sipala_barcode')) {
    function generate_sipala_barcode(int $unitId): string
    {
        return sprintf("SIP-ALT-%06d", $unitId);
    }
}
