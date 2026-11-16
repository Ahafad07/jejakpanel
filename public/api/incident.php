<?php
/** GET api/incident.php?id=3 -> satu insiden beserta tahap-tahap kronologinya. */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../app/pengaturan.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    kirim(['error' => 'Parameter id tidak valid.'], 400);
}

$pdo = db();

$stmt = $pdo->prepare('SELECT id, ip, mulai, selesai, risiko, status, ringkasan FROM incidents WHERE id = ?');
$stmt->execute([$id]);
$insiden = $stmt->fetch();
if (!$insiden) {
    kirim(['error' => 'Insiden tidak ditemukan.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT urutan, jenis, teknik_attack, mulai, selesai, jumlah_request, penjelasan, rekomendasi
     FROM incident_stages WHERE incident_id = ? ORDER BY urutan'
);
$stmt->execute([$id]);
$tahap = $stmt->fetchAll();
foreach ($tahap as &$t) {
    $t['urutan']         = (int) $t['urutan'];
    $t['jumlah_request'] = (int) $t['jumlah_request'];
}

$stmt = $pdo->prepare('SELECT dibuat_pada, dikonfirmasi_pada, cara, permanen_pada, diterapkan_pada FROM blocklist WHERE ip = ?');
$stmt->execute([$insiden['ip']]);
$blokir = $stmt->fetch() ?: ['dibuat_pada' => false, 'dikonfirmasi_pada' => null, 'cara' => null,
                             'permanen_pada' => null, 'diterapkan_pada' => null];
$stmt = $pdo->prepare('SELECT alasan, dibuat_pada FROM ip_aman WHERE ip = ?');
$stmt->execute([$insiden['ip']]);
$aman = $stmt->fetch() ?: null;
$diblokirPada = $blokir['dibuat_pada'];

// Permintaan sesudah diblokir yang ditolak server (403) = bukti blokir bekerja
$ditolak = 0;
$ditolakTerakhir = null;
if ($diblokirPada) {
    $stmt = $pdo->prepare('SELECT COUNT(*), MAX(waktu) FROM log_entries WHERE ip = ? AND waktu > ? AND status = 403');
    $stmt->execute([$insiden['ip'], $diblokirPada]);
    [$ditolak, $ditolakTerakhir] = $stmt->fetch(PDO::FETCH_NUM);
}

$insiden['diblokir']         = $diblokirPada !== false;
$insiden['diblokir_pada']    = $diblokirPada ?: null;
$insiden['blokir_ulang_pada'] = $blokir['dikonfirmasi_pada'];
$insiden['cara_blokir']      = $blokir['cara'];
$insiden['permanen_pada']    = $blokir['permanen_pada'];
$insiden['diterapkan_pada']  = $blokir['diterapkan_pada'];
$insiden['aman']             = $aman;
$insiden['ditolak']          = (int) $ditolak;
$insiden['ditolak_terakhir'] = $ditolakTerakhir;
$insiden['id']    = (int) $insiden['id'];
$insiden['tahap'] = $tahap;
$insiden['situs'] = trim((atur('nama_situs') ?: (preg_replace('/[^a-z0-9.\-:]/i', '', $_SERVER['HTTP_HOST'] ?? '') ?: 'website uji')) . ' ' . (atur('keterangan_situs') ?? ''));

kirim($insiden);
