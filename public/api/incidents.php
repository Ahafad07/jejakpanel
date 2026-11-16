<?php
/** GET api/incidents.php -> daftar insiden, paling berbahaya di atas. */
require __DIR__ . '/_bootstrap.php';

$rows = db()->query(
    "SELECT i.id, i.ip, i.mulai, i.selesai, i.risiko, i.status, i.ringkasan,
            COUNT(s.id)           AS jumlah_tahap,
            SUM(s.jumlah_request) AS jumlah_request,
            (b.ip IS NOT NULL)    AS diblokir,
            b.cara                AS cara_blokir,
            (b.permanen_pada IS NOT NULL) AS permanen,
            (a.ip IS NOT NULL)    AS aman
     FROM incidents i
     LEFT JOIN incident_stages s ON s.incident_id = i.id
     LEFT JOIN blocklist b ON b.ip = i.ip
     LEFT JOIN ip_aman a ON a.ip = i.ip
     GROUP BY i.id, b.ip, b.cara, b.permanen_pada, a.ip
     ORDER BY (i.status = 'serangan_ulang') DESC,
              (b.cara = 'otomatis' AND b.permanen_pada IS NULL) DESC,
              (i.status = 'ditangani'),
              FIELD(i.risiko, 'kritis', 'tinggi', 'sedang', 'rendah'),
              i.selesai DESC"
)->fetchAll();

foreach ($rows as &$r) {
    $r['id']             = (int) $r['id'];
    $r['jumlah_tahap']   = (int) $r['jumlah_tahap'];
    $r['jumlah_request'] = (int) $r['jumlah_request'];
    $r['diblokir']       = (bool) $r['diblokir'];
    $r['permanen']       = (bool) $r['permanen'];
    $r['aman']           = (bool) $r['aman'];
    $r['menunggu']       = $r['diblokir'] && $r['cara_blokir'] === 'otomatis' && !$r['permanen'];
    // Sama dengan definisi "perlu tindakan" di summary.php
    $r['perlu_tindakan'] = $r['status'] === 'serangan_ulang' || ($r['status'] === 'baru' && !$r['aman']) || $r['menunggu'];
}

kirim($rows);
