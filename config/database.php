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