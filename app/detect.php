<?php
/**
 * JejakPanel - jalankan deteksi dan susun kronologi serangan.
 *
 * Pemakaian (dari folder jejakpanel):
 *     php app/detect.php
 *
 * Aturan deteksi ada di app/detektor.php.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/detektor.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk dijalankan lewat terminal.\n");
}

$hasil = deteksi(db());
foreach ($hasil as $h) {
    $tanda = $h['status'] === 'serangan_ulang' ? '  << MASIH MENYERANG SETELAH DIBLOKIR' : '';
    printf("  [%-6s] %-15s %s%s\n", strtoupper($h['risiko']), $h['ip'], $h['ringkasan'], $tanda);
}
echo 'Selesai: ' . count($hasil) . " insiden terdeteksi.\n";
