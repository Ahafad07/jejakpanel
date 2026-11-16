<?php
/** GET api/summary.php -> angka ringkasan + trafik 24 jam terakhir untuk dashboard. */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../app/pengaturan.php';

$pdo = db();

// Angka log dihitung untuk 24 jam terakhir (sama dengan grafik)
$terakhir = $pdo->query('SELECT MAX(waktu) FROM log_entries')->fetchColumn();
$log = ['total' => 0, 'ip_unik' => 0, 'awal' => null, 'terakhir' => $terakhir];
if ($terakhir) {
    $q = $pdo->prepare(
        'SELECT COUNT(*) AS total, COUNT(DISTINCT ip) AS ip_unik, MIN(waktu) AS awal FROM log_entries
         WHERE waktu > ? - INTERVAL 24 HOUR'
    );
    $q->execute([$terakhir]);
    $log = $q->fetch() + ['terakhir' => $terakhir];
}

$risiko = ['kritis' => 0, 'tinggi' => 0, 'sedang' => 0, 'rendah' => 0];
foreach ($pdo->query('SELECT risiko, COUNT(*) AS n FROM incidents GROUP BY risiko') as $r) {
    $risiko[$r['risiko']] = (int) $r['n'];
}

// Insiden yang masih perlu tindakan operator, per tingkat risiko
$perlu = ['kritis' => 0, 'tinggi' => 0, 'sedang' => 0, 'rendah' => 0];
foreach ($pdo->query(
    "SELECT i.risiko, COUNT(*) AS n FROM incidents i
     LEFT JOIN blocklist b ON b.ip = i.ip LEFT JOIN ip_aman a ON a.ip = i.ip
     WHERE i.status = 'serangan_ulang'
        OR (i.status = 'baru' AND a.ip IS NULL)
        OR (b.cara = 'otomatis' AND b.permanen_pada IS NULL)
     GROUP BY i.risiko"
) as $r) {
    $perlu[$r['risiko']] = (int) $r['n'];
}
$seranganUlang = (int) $pdo->query("SELECT COUNT(*) FROM incidents WHERE status = 'serangan_ulang'")->fetchColumn();
$blokir = $pdo->query(
    "SELECT COUNT(*) AS total, COALESCE(SUM(cara = 'otomatis'), 0) AS otomatis, COALESCE(SUM(cara = 'manual'), 0) AS manual,
            COALESCE(SUM(cara = 'otomatis' AND permanen_pada IS NULL), 0) AS menunggu
     FROM blocklist"
)->fetch();

// Bukti blokir bekerja: permintaan dari IP terblokir yang ditolak server sesudah diblokir
$ditolak = (int) $pdo->query(
    'SELECT COUNT(*) FROM log_entries l JOIN blocklist b ON b.ip = l.ip
     WHERE l.status = 403 AND l.waktu > b.dibuat_pada'
)->fetchColumn();   // sejak diblokir, tidak dibatasi 24 jam

// Trafik per jam selama 24 jam terakhir (berakhir di jam log terbaru)
$perJam = [];
if ($log['terakhir']) {
    $akhir = new DateTime(substr($log['terakhir'], 0, 13) . ':00:00');
    $awal = (clone $akhir)->modify('-23 hours');
    for ($j = clone $awal; $j <= $akhir; $j->modify('+1 hour')) {
        $perJam[$j->format('Y-m-d H')] = ['jam' => $j->format('Y-m-d H:00:00'), 'normal' => 0, 'penyerang' => 0];
    }
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(l.waktu, '%Y-%m-%d %H') AS kunci,
                SUM(x.ip IS NULL)     AS normal,
                SUM(x.ip IS NOT NULL) AS penyerang
         FROM log_entries l
         LEFT JOIN (SELECT DISTINCT ip FROM incidents) x ON x.ip = l.ip
         WHERE l.waktu >= ?
         GROUP BY kunci"
    );
    $stmt->execute([$awal->format('Y-m-d H:i:s')]);
    foreach ($stmt as $r) {
        if (isset($perJam[$r['kunci']])) {
            $perJam[$r['kunci']]['normal'] = (int) $r['normal'];
            $perJam[$r['kunci']]['penyerang'] = (int) $r['penyerang'];
        }
    }
}

$host = preg_replace('/[^a-z0-9.\-:]/i', '', $_SERVER['HTTP_HOST'] ?? '') ?: 'website uji';

kirim([
    'situs'          => trim((atur('nama_situs') ?: $host) . ' ' . (atur('keterangan_situs') ?? '')),
    'kebijakan'      => [
        'blokir_otomatis' => (bool) atur('blokir_otomatis'),
        'risiko'          => array_values((array) atur('risiko_otomatis')),
        'jendela_menit'   => (int) atur('jendela_otomatis_menit'),
    ],
    'perlu_tindakan' => $perlu,
    'serangan_ulang' => $seranganUlang,
    'diblokir'       => (int) $blokir['total'],
    'blokir_otomatis' => (int) $blokir['otomatis'],
    'blokir_manual'  => (int) $blokir['manual'],
    'menunggu_konfirmasi' => (int) $blokir['menunggu'],
    'ditolak'        => $ditolak,
    'total_request'  => (int) $log['total'],
    'ip_unik'        => (int) $log['ip_unik'],
    'log_awal'       => $log['awal'],
    'log_terakhir'   => $log['terakhir'],
    'insiden'        => array_sum($risiko),
    'risiko'         => $risiko,
    'per_jam'        => array_values($perJam),
]);
