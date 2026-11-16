<?php
/**
 * JejakPanel - impor log akses ke tabel log_entries.
 *
 * Pemakaian (dari folder jejakpanel):
 *     php app/import_log.php                         -> baca baris baru dari data/access.log
 *     php app/import_log.php data/live.log           -> baca baris baru dari file lain
 *     php app/import_log.php data/access.log --reset -> kosongkan tabel dulu, baca dari awal
 *
 * Tanpa --reset, impor hanya membaca baris yang belum pernah dibaca.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/siklus.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk dijalankan lewat terminal.\n");
}

$args  = array_slice($argv, 1);
$reset = in_array('--reset', $args, true);
$files = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$path  = $files[0] ?? __DIR__ . '/../data/access.log';

if (!is_readable($path)) {
    fwrite(STDERR, "File log tidak ditemukan: $path\nJalankan dulu: python tools/generate_logs.py\n");
    exit(1);
}

$pdo = db();
migrasi($pdo);
$hasil = imporLog($pdo, $path, $reset);

// Data hasil impor manual menjadi "data dasar": tombol Reset demo tidak akan menghapusnya
if (basename($path) !== 'live.log') {
    $s = bacaStatusSiklus();
    $s['id_dasar'] = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM log_entries')->fetchColumn();
    $s['skema'] = VERSI_SKEMA;
    $s['perlu_deteksi'] = true;
    $s['contoh'] = VERSI_CONTOH;   // memakai log sendiri: jangan timpa dengan data contoh
    simpanStatusSiklus($s);
}
if ($reset) {
    echo "Tabel log_entries dikosongkan.\n";
}
echo "Selesai: {$hasil['baru']} baris baru masuk ke log_entries, {$hasil['dilewati']} baris dilewati.\n";
