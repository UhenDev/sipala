<?php

/**
 * FILE       : includes/qrcode.php
 * MODUL      : Generator Kode QR Vektor (100% Offline & Mandiri)
 * FUNGSI     : Menghasilkan kode QR standar ISO/IEC 18004 dalam format Vektor SVG & Data URI
 * DEPENDENSI : 0 (Tanpa GD, tanpa Composer, tanpa pustaka eksternal)
 * AKSES      : Seluruh Modul SIPALA (Admin, Unit Fisik, Portal Siswa)
 */

declare(strict_types=1);

class SipalaQRCode
{
    // Error correction levels
    public const EC_L = 1; // 7% recovery
    public const EC_M = 0; // 15% recovery (default)
    public const EC_Q = 3; // 25% recovery
    public const EC_H = 2; // 30% recovery

    // Galois Field GF(256) tables
    private static array $exp = [];
    private static array $log = [];
    private static bool $gfInit = false;

    // Version capacity table (Version 1-10, EC_M byte capacity)
    private static array $capacities = [
        1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84,
        6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213
    ];

    // Total data codewords per version (EC_M)
    private static array $dataCodewords = [
        1 => 16, 2 => 28, 3 => 44, 4 => 64, 5 => 86,
        6 => 108, 7 => 124, 8 => 154, 9 => 182, 10 => 216
    ];

    // EC codewords per block
    private static array $ecPerBlock = [
        1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24,
        6 => 16, 7 => 18, 8 => 22, 9 => 22, 10 => 26
    ];

    // Number of blocks
    private static array $numBlocks = [
        1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2,
        6 => 4, 7 => 4, 8 => 4, 9 => 5, 10 => 5
    ];

    // Alignment pattern positions
    private static array $alignmentPositions = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    private static function initGF(): void
    {
        if (self::$gfInit) return;
        self::$exp = array_fill(0, 512, 0);
        self::$log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 256) {
                $x ^= 0x11d; // Primitive polynomial x^8 + x^4 + x^3 + x^2 + 1
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
        self::$gfInit = true;
    }

    private static function gfMul(int $x, int $y): int
    {
        if ($x === 0 || $y === 0) return 0;
        return self::$exp[self::$log[$x] + self::$log[$y]];
    }

    private static function rsGeneratorPoly(int $degree): array
    {
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = [1];
            $factor = self::$exp[$i];
            $temp = [];
            $temp[0] = 0;
            for ($j = 0; $j < count($poly); $j++) {
                $temp[$j] = self::gfMul($poly[$j], $factor);
            }
            $res = array_fill(0, count($poly) + 1, 0);
            for ($j = 0; $j < count($poly); $j++) {
                $res[$j] ^= $poly[$j];
                $res[$j + 1] ^= $temp[$j];
            }
            $poly = $res;
        }
        return $poly;
    }

    private static function rsCalculate(array $data, int $ecCount): array
    {
        self::initGF();
        $gen = self::rsGeneratorPoly($ecCount);
        $remainder = array_fill(0, $ecCount, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;
            if ($factor !== 0) {
                for ($j = 0; $j < $ecCount; $j++) {
                    $remainder[$j] ^= self::gfMul($gen[$j + 1], $factor);
                }
            }
        }
        return $remainder;
    }

    /**
     * Memilih versi QR terkecil yang mampu menampung panjang data
     */
    private static function selectVersion(int $byteLen): int
    {
        foreach (self::$capacities as $ver => $cap) {
            if ($byteLen <= $cap) {
                return $ver;
            }
        }
        return 10; // Max fallback
    }

    /**
     * Membuat bit stream 8-bit byte mode
     */
    private static function encodeData(string $text, int $version): array
    {
        $len = strlen($text);
        $bits = [];

        // 1. Mode indicator (0100 for 8-bit byte)
        $bits = array_merge($bits, [0, 1, 0, 0]);

        // 2. Character count indicator (8 bits for v1-9, 16 bits for v10+)
        $countBits = ($version < 10) ? 8 : 16;
        for ($i = $countBits - 1; $i >= 0; $i--) {
            $bits[] = ($len >> $i) & 1;
        }

        // 3. Data bytes
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($text[$i]);
            for ($b = 7; $b >= 0; $b--) {
                $bits[] = ($byte >> $b) & 1;
            }
        }

        // 4. Terminator (up to 4 zeroes)
        $maxBits = self::$dataCodewords[$version] * 8;
        $termLen = min(4, $maxBits - count($bits));
        for ($i = 0; $i < $termLen; $i++) {
            $bits[] = 0;
        }

        // 5. Pad to byte boundary
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        // Convert bits to codewords
        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($b = 0; $b < 8; $b++) {
                $byte = ($byte << 1) | $bits[$i + $b];
            }
            $codewords[] = $byte;
        }

        // 6. Pad codewords (0xEC, 0x11)
        $padBytes = [0xEC, 0x11];
        $padIdx = 0;
        while (count($codewords) < self::$dataCodewords[$version]) {
            $codewords[] = $padBytes[$padIdx];
            $padIdx = ($padIdx + 1) % 2;
        }

        return $codewords;
    }

    /**
     * Menghasilkan matriks QR 2D
     */
    public static function createMatrix(string $text): array
    {
        self::initGF();
        $textLen = strlen($text);
        $version = self::selectVersion($textLen);
        $size = 17 + 4 * $version;

        $codewords = self::encodeData($text, $version);

        // Error correction blocks
        $numBlocks = self::$numBlocks[$version];
        $ecCount = self::$ecPerBlock[$version];

        $dataBlocks = [];
        $ecBlocks = [];

        $totalData = count($codewords);
        $baseBlockSize = (int)floor($totalData / $numBlocks);
        $extraBlocks = $totalData % $numBlocks;

        $offset = 0;
        for ($b = 0; $b < $numBlocks; $b++) {
            $blockSize = $baseBlockSize + ($b >= ($numBlocks - $extraBlocks) ? 1 : 0);
            $blockData = array_slice($codewords, $offset, $blockSize);
            $offset += $blockSize;
            $dataBlocks[] = $blockData;
            $ecBlocks[] = self::rsCalculate($blockData, $ecCount);
        }

        // Interleave codewords
        $finalCodewords = [];
        $maxDataLen = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxDataLen; $i++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                if ($i < count($dataBlocks[$b])) {
                    $finalCodewords[] = $dataBlocks[$b][$i];
                }
            }
        }
        for ($i = 0; $i < $ecCount; $i++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $finalCodewords[] = $ecBlocks[$b][$i];
            }
        }

        // Convert codewords to final bitstream
        $finalBits = [];
        foreach ($finalCodewords as $cw) {
            for ($b = 7; $b >= 0; $b--) {
                $finalBits[] = ($cw >> $b) & 1;
            }
        }

        // Remainder bits for version
        $remainderBits = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0];
        for ($i = 0; $i < ($remainderBits[$version] ?? 0); $i++) {
            $finalBits[] = 0;
        }

        // Create empty matrix and reserved mask
        $matrix = array_fill(0, $size, array_fill(0, $size, null));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // 1. Finder patterns (Top-left, Top-right, Bottom-left)
        self::placeFinder($matrix, $reserved, 0, 0);
        self::placeFinder($matrix, $reserved, $size - 7, 0);
        self::placeFinder($matrix, $reserved, 0, $size - 7);

        // 2. Alignment patterns
        $alignPos = self::$alignmentPositions[$version] ?? [];
        foreach ($alignPos as $r) {
            foreach ($alignPos as $c) {
                if ($reserved[$r][$c]) continue;
                self::placeAlignment($matrix, $reserved, $r - 2, $c - 2);
            }
        }

        // 3. Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            if (!$reserved[6][$i]) {
                $matrix[6][$i] = ($i % 2 === 0) ? 1 : 0;
                $reserved[6][$i] = true;
            }
            if (!$reserved[$i][6]) {
                $matrix[$i][6] = ($i % 2 === 0) ? 1 : 0;
                $reserved[$i][6] = true;
            }
        }

        // 4. Dark module
        $matrix[4 * $version + 9][8] = 1;
        $reserved[4 * $version + 9][8] = true;

        // 5. Reserve format info areas
        for ($i = 0; $i < 9; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }

        // 6. Place data bits in zigzag pattern
        $bitIdx = 0;
        $totalBits = count($finalBits);
        $col = $size - 1;
        $goingUp = true;

        while ($col > 0) {
            if ($col === 6) $col--; // Skip vertical timing column

            $rows = $goingUp ? range($size - 1, 0, -1) : range(0, $size - 1);
            foreach ($rows as $row) {
                for ($c = 0; $c < 2; $c++) {
                    $currCol = $col - $c;
                    if (!$reserved[$row][$currCol]) {
                        $matrix[$row][$currCol] = ($bitIdx < $totalBits) ? $finalBits[$bitIdx] : 0;
                        $bitIdx++;
                    }
                }
            }
            $goingUp = !$goingUp;
            $col -= 2;
        }

        // 7. Apply standard mask 0: (row + col) % 2 === 0
        $mask = 0;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$reserved[$r][$c]) {
                    if (($r + $c) % 2 === 0) {
                        $matrix[$r][$c] ^= 1;
                    }
                }
            }
        }

        // 8. Format information (EC_M: 0, Mask 0: 000 -> 101010000010010 with XOR 101010000010010)
        // Standard Format bits for EC_M (00) & Mask 000 with BCH(15,5) & XOR mask 0x5412 = 0x4B3B = 100101100111011
        $formatBits = [1, 0, 0, 1, 0, 1, 1, 0, 0, 1, 1, 1, 0, 1, 1];

        // Place format bits around top-left
        $coords1 = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]
        ];
        foreach ($coords1 as $idx => [$r, $c]) {
            $matrix[$r][$c] = $formatBits[$idx];
        }

        // Place format bits around top-right & bottom-left
        $coords2 = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8],
            [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5],
            [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1]
        ];
        foreach ($coords2 as $idx => [$r, $c]) {
            $matrix[$r][$c] = $formatBits[$idx];
        }

        return $matrix;
    }

    private static function placeFinder(array &$matrix, array &$reserved, int $startR, int $startC): void
    {
        $size = count($matrix);
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $currR = $startR + $r;
                $currC = $startC + $c;
                if ($currR < 0 || $currR >= $size || $currC < 0 || $currC >= $size) continue;
                $reserved[$currR][$currC] = true;
                if ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6) {
                    if ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)) {
                        $matrix[$currR][$currC] = 1;
                    } else {
                        $matrix[$currR][$currC] = 0;
                    }
                } else {
                    $matrix[$currR][$currC] = 0; // Separator
                }
            }
        }
    }

    private static function placeAlignment(array &$matrix, array &$reserved, int $startR, int $startC): void
    {
        for ($r = 0; $r < 5; $r++) {
            for ($c = 0; $c < 5; $c++) {
                $currR = $startR + $r;
                $currC = $startC + $c;
                $reserved[$currR][$currC] = true;
                if ($r === 0 || $r === 4 || $c === 0 || $c === 4 || ($r === 2 && $c === 2)) {
                    $matrix[$currR][$currC] = 1;
                } else {
                    $matrix[$currR][$currC] = 0;
                }
            }
        }
    }

    /**
     * Render Kode QR sebagai Vektor SVG Tajam
     */
    public static function renderSvg(string $text, int $pixelSize = 200, int $margin = 2, string $darkColor = '#0f172a', string $lightColor = '#ffffff'): string
    {
        $matrix = self::createMatrix($text);
        $modules = count($matrix);
        $totalSize = $modules + ($margin * 2);

        $path = '';
        for ($r = 0; $r < $modules; $r++) {
            for ($c = 0; $c < $modules; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x = $c + $margin;
                    $y = $r + $margin;
                    $path .= "M{$x},{$y}h1v1h-1z ";
                }
            }
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $totalSize . ' ' . $totalSize . '" '
             . 'width="' . $pixelSize . '" height="' . $pixelSize . '" shape-rendering="crispEdges" '
             . 'style="max-width:100%;height:auto;display:block;">';
        if ($lightColor !== 'transparent') {
            $svg .= '<rect width="100%" height="100%" fill="' . htmlspecialchars($lightColor, ENT_QUOTES, 'UTF-8') . '" />';
        }
        $svg .= '<path d="' . trim($path) . '" fill="' . htmlspecialchars($darkColor, ENT_QUOTES, 'UTF-8') . '" />';
        $svg .= '</svg>';

        return $svg;
    }

    /**
     * Menghasilkan Data URI SVG (bisa langsung dimasukkan ke atribut src="data:image/svg+xml;base64,...")
     */
    public static function getDataUri(string $text, int $pixelSize = 200, int $margin = 2): string
    {
        $svg = self::renderSvg($text, $pixelSize, $margin);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}

/**
 * Helper global untuk merender SVG Kode QR
 */
function render_qr_svg(string $text, int $size = 200, int $margin = 2, string $dark = '#0f172a', string $light = '#ffffff'): string
{
    return SipalaQRCode::renderSvg($text, $size, $margin, $dark, $light);
}

/**
 * Helper global untuk mendapatkan URL Data URI Kode QR
 */
function get_qr_data_uri(string $text, int $size = 200, int $margin = 2): string
{
    return SipalaQRCode::getDataUri($text, $size, $margin);
}

/**
 * Helper untuk menyusun URL Scan Publik / PWA
 */
function get_unit_scan_url(string $kodeUnit): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $posAdmin = strpos($scriptDir, '/admin');
    $baseUrl = ($posAdmin !== false) ? substr($scriptDir, 0, $posAdmin) : $scriptDir;
    $baseUrl = rtrim($baseUrl, '/');

    return $protocol . '://' . $host . $baseUrl . '/scan.php?unit=' . urlencode($kodeUnit);
}

/**
 * Helper untuk menyusun URL Scan Master Alat
 */
function get_alat_scan_url(string $kodeBarang): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $posAdmin = strpos($scriptDir, '/admin');
    $baseUrl = ($posAdmin !== false) ? substr($scriptDir, 0, $posAdmin) : $scriptDir;
    $baseUrl = rtrim($baseUrl, '/');

    return $protocol . '://' . $host . $baseUrl . '/scan.php?alat=' . urlencode($kodeBarang);
}
