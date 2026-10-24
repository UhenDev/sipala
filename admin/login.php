<?php

/**
 * FILE       : admin/login.php
 * MODUL      : Pengalihan Halaman Masuk Terpadu
 * KETERANGAN : Modul login telah disatukan pada portal utama (/login.php)
 *              yang otomatis mendeteksi peran Admin, Petugas, dan Siswa.
 */

declare(strict_types=1);

header('Location: ../login.php', true, 301);
exit;
