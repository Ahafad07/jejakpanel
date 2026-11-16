<?php
/**
 * GET api/tick.php -> menjalankan satu siklus otomatis (baca log baru, deteksi,
 * blokir otomatis, tulis .htaccess), lalu mengembalikan status simulasi & mode.
 * Dipanggil dashboard setiap kali memperbarui diri. Dibatasi maksimal satu siklus per 2,5 detik.
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../app/siklus.php';

$pdo = db();
$hasil = jalankanSiklus($pdo);

kirim([
    'siklus'          => $hasil,
    'simulasi'        => statusSimulasi(),
    'mode_demo'       => (bool) atur('mode_demo'),
    'blokir_otomatis' => (bool) atur('blokir_otomatis'),
    'tulis_htaccess'  => bisaTulisHtaccess(),
]);
