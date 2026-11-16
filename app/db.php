<?php
/**
 * Koneksi database bersama (PDO). Dipakai oleh semua file PHP lain.
 * Semua waktu memakai WIB (Asia/Jakarta), baik di PHP maupun di MySQL,
 * agar waktu log dan waktu pemblokiran bisa dibandingkan dengan benar.
 */
date_default_timezone_set('Asia/Jakarta');

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        throw new RuntimeException(
            "File app/config.php belum ada. Salin app/config.example.php menjadi app/config.php lalu isi password MySQL."
        );
    }
    $c = require $configFile;

    $dsn = "mysql:host={$c['db_host']};port={$c['db_port']};dbname={$c['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '+07:00'");
    return $pdo;
}
