<?php

/**
 * FILE       : config/database.php
 * MODUL      : Konfigurasi Basis Data
 * FUNGSI     : Mengelola koneksi PDO ke database MySQL/MariaDB SIPALA
 * AKSES      : Seluruh Modul Sistem
 *
 * ALUR:
 * 1. Mendefinisikan konstanta host, nama database, username, dan password.
 * 2. Menginisialisasi koneksi PDO menggunakan pola Singleton agar efisien.
 * 3. Mengatur atribut error handling PDO menjadi PDO::ERRMODE_EXCEPTION.
 * 4. Menonaktifkan emulasi prepared statements demi proteksi maksimal dari SQL Injection.
 */

declare(strict_types=1);

// Menjamin $_SERVER['REQUEST_METHOD'] terdefinisi dengan aman di lingkungan CLI / Web
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

// Set zona waktu standar Indonesia Barat (WIB)
date_default_timezone_set('Asia/Jakarta');

const DB_HOST = '127.0.0.1';
const DB_NAME = 'sipala';
const DB_USER = 'root';
const DB_PASS = '';

/**
 * Mengembalikan objek koneksi PDO tunggal (Singleton).
 *
 * @return PDO Objek koneksi database aktif
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+07:00'");
    } catch (PDOException $exception) {
        error_log(
            'SIPALA database connection error: ' .
            $exception->getMessage()
        );

        http_response_code(500);
        exit(
            'Koneksi database gagal. Pastikan MySQL aktif ' .
            'dan database SIPALA sudah dibuat.'
        );
    }

    return $pdo;
}