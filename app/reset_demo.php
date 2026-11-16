<?php
/**
 * Mengembalikan kondisi demo.
 *     php app/reset_demo.php           -> hapus data simulasi, daftar blokir, dan IP aman
 *                                         (data log satu hari tetap ada)
 *     php app/reset_demo.php --semua   -> kosongkan SEMUA data (log, insiden, blokir)
 *
 * Di hosting, gunakan tombol "Reset demo" di dashboard.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/siklus.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk dijalankan lewat terminal.\n");
}

$pdo = db();
migrasi($pdo);

if (in_array('--semua', $argv, true)) {
    foreach (['blocklist', 'ip_aman', 'incidents', 'log_entries', 'import_state'] as $t) {
        $pdo->exec("DELETE FROM $t");
    }
    foreach (['incidents', 'incident_stages', 'log_entries', 'blocklist'] as $t) {
        $pdo->exec("ALTER TABLE $t AUTO_INCREMENT = 1");
    }
    @unlink(fileLogLangsung());
    simpanSimulasi(null);
    terapkanBlokir($pdo);
    simpanStatusSiklus(['skema' => VERSI_SKEMA, 'id_dasar' => 0]);
    echo "Semua data dikosongkan. Siap untuk demo pantau langsung.\n";
    exit;
}

resetDemo($pdo);
echo "Demo direset: data simulasi, daftar blokir, dan IP aman dihapus.\n";
