<?php
/**
 * Memperbarui struktur database lama ke versi terbaru tanpa menghapus data.
 * Aman dijalankan berkali-kali. Di hosting, pembaruan ini juga berjalan otomatis.
 *     php app/migrate.php
 */
require __DIR__ . '/db.php';
require __DIR__ . '/skema.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk dijalankan lewat terminal.\n");
}

migrasi(db());
echo "Database sudah versi terbaru.\n";
