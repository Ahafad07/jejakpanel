<?php
/** GET api/blocklist.php -> daftar IP yang diblokir + status penerapan ke .htaccess server. */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../app/blokir.php';

$daftar = db()->query(
    'SELECT ip, incident_id, alasan, cara, dibuat_pada, permanen_pada, diterapkan_pada
     FROM blocklist ORDER BY dibuat_pada, id'
)->fetchAll();

kirim([
    'daftar'         => $daftar,
    'tulis_htaccess' => bisaTulisHtaccess(),
]);
