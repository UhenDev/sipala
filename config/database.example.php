<?php

/**
 * FILE       : config/database.example.php
 * MODUL      : Konfigurasi Basis Data (Template)
 * FUNGSI     : Template konfigurasi koneksi PDO ke database MySQL/MariaDB SIPALA
 * PETUNJUK   : Salin berkas ini menjadi `config/database.php` dan sesuaikan kredensial server Anda.
 */

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'sipala';
const DB_USER = 'root';
const DB_PASS = '';

/**
 * Mengembalikan objek koneksi PDO tunggal (Singleton).
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
    } catch (PDOException $exception) {
        error_log('SIPALA database connection error: ' . $exception->getMessage());
        http_response_code(500);
        exit('Koneksi database gagal. Pastikan MySQL aktif dan database SIPALA sudah dibuat.');
    }

    return $pdo;
}
