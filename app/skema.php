<?php
/**
 * Pembaruan struktur database ke versi terbaru tanpa menghapus data.
 * Aman dijalankan berkali-kali. Dipanggil oleh migrate.php dan otomatis oleh siklus.
 */

const VERSI_SKEMA = 5;

function adaKolom(PDO $pdo, string $tabel, string $kolom): bool
{
    return (bool) $pdo->query("SHOW COLUMNS FROM `$tabel` LIKE " . $pdo->quote($kolom))->fetch();
}

function migrasi(PDO $pdo): void
{
    $pdo->exec(
        "ALTER TABLE incidents MODIFY status
         ENUM('baru','ditinjau','ditangani','serangan_ulang') NOT NULL DEFAULT 'baru'"
    );
    $pdo->exec(
        "ALTER TABLE incident_stages MODIFY jenis
         ENUM('pengintaian','login_paksa','login_berhasil','lonjakan_error','serangan_ulang') NOT NULL"
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS import_state (
            file        VARCHAR(255) PRIMARY KEY,
            posisi      BIGINT       NOT NULL DEFAULT 0,
            diperbarui  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
         ) ENGINE=InnoDB'
    );
    if (!adaKolom($pdo, 'blocklist', 'dikonfirmasi_pada')) {
        $pdo->exec('ALTER TABLE blocklist ADD dikonfirmasi_pada TIMESTAMP NULL DEFAULT NULL AFTER dibuat_pada');
    }
    if (!adaKolom($pdo, 'blocklist', 'cara')) {
        $pdo->exec("ALTER TABLE blocklist ADD cara ENUM('manual','otomatis') NOT NULL DEFAULT 'manual' AFTER alasan");
    }
    if (!adaKolom($pdo, 'blocklist', 'permanen_pada')) {
        $pdo->exec('ALTER TABLE blocklist ADD permanen_pada TIMESTAMP NULL DEFAULT NULL');
    }
    if (!adaKolom($pdo, 'blocklist', 'diterapkan_pada')) {
        $pdo->exec('ALTER TABLE blocklist ADD diterapkan_pada TIMESTAMP NULL DEFAULT NULL');
    }
    if (!adaKolom($pdo, 'log_entries', 'sumber')) {
        $pdo->exec("ALTER TABLE log_entries ADD sumber ENUM('impor','live') NOT NULL DEFAULT 'impor'");
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ip_aman (
            ip          VARCHAR(45)  PRIMARY KEY,
            alasan      VARCHAR(255) NOT NULL,
            dibuat_pada TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
         ) ENGINE=InnoDB'
    );
}
