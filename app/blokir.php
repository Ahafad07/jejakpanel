<?php
/**
 * Pengelolaan blokir IP: blokir manual/otomatis, jadikan permanen, batalkan,
 * dan penulisan aturan ke file .htaccess website yang dilindungi.
 */
require_once __DIR__ . '/pengaturan.php';

const PENANDA_AWAL  = '# BEGIN JejakPanel blokir (dikelola otomatis, jangan diubah manual)';
const PENANDA_AKHIR = '# END JejakPanel blokir';

/**
 * Menulis semua IP di blocklist ke bagian khusus .htaccess (memakai mod_rewrite,
 * didukung Apache dan LiteSpeed). Penulisan bersifat atomik dan menyimpan cadangan.
 * @return bool true jika berhasil ditulis
 */
function terapkanBlokir(PDO $pdo): bool
{
    if (!atur('terapkan_htaccess')) {
        return false;
    }
    $file = fileHtaccess();
    if (!$file || !is_writable($file)) {
        return false;
    }

    $ips = array_values(array_filter(
        $pdo->query('SELECT ip FROM blocklist ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        fn($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false
    ));

    $baris = [PENANDA_AWAL];
    if ($ips) {
        $baris[] = '<IfModule mod_rewrite.c>';
        $baris[] = 'RewriteEngine On';
        foreach ($ips as $i => $ip) {
            $baris[] = 'RewriteCond %{REMOTE_ADDR} ^' . preg_quote($ip, '/') . '$' . ($i < count($ips) - 1 ? ' [OR]' : '');
        }
        $baris[] = 'RewriteRule ^ - [F]';
        $baris[] = '</IfModule>';
    }
    $baris[] = PENANDA_AKHIR;
    $bagian = implode("\n", $baris);

    $isi = file_get_contents($file);
    $pola = '/' . preg_quote(PENANDA_AWAL, '/') . '.*?' . preg_quote(PENANDA_AKHIR, '/') . '\n?/s';
    $tanpaBagian = preg_replace($pola, '', $isi);
    $baru = $bagian . "\n\n" . ltrim($tanpaBagian);   // diletakkan paling atas agar dievaluasi lebih dulu

    if ($baru === $isi) {
        return true;
    }
    $cadangan = $file . '.jejakpanel.bak';
    if (!file_exists($cadangan)) {
        copy($file, $cadangan);
    }
    $sementara = $file . '.tmp-' . getmypid();
    if (file_put_contents($sementara, $baru, LOCK_EX) === false || !rename($sementara, $file)) {
        @unlink($sementara);
        return false;
    }
    $pdo->exec('UPDATE blocklist SET diterapkan_pada = CURRENT_TIMESTAMP WHERE diterapkan_pada IS NULL');
    return true;
}

function ipDianggapAman(PDO $pdo, string $ip): bool
{
    if (in_array($ip, (array) atur('ip_aman'), true)) {
        return true;
    }
    $q = $pdo->prepare('SELECT 1 FROM ip_aman WHERE ip = ?');
    $q->execute([$ip]);
    return (bool) $q->fetchColumn();
}

/**
 * Memblokir otomatis insiden berisiko tinggi/kritis yang masih berlangsung dan belum ditangani.
 * @return string[] IP yang baru diblokir
 */
function blokirOtomatis(PDO $pdo): array
{
    $risiko = array_values(array_intersect((array) atur('risiko_otomatis'), ['kritis', 'tinggi', 'sedang', 'rendah']));
    if (!atur('blokir_otomatis') || !$risiko) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($risiko), '?'));
    $q = $pdo->prepare(
        "SELECT i.id, i.ip, i.risiko, i.ringkasan FROM incidents i
         LEFT JOIN blocklist b ON b.ip = i.ip
         WHERE i.risiko IN ($ph) AND i.status = 'baru' AND b.ip IS NULL
           AND i.selesai >= NOW() - INTERVAL ? MINUTE"
    );
    $q->execute(array_merge($risiko, [(int) atur('jendela_otomatis_menit')]));

    $simpan = $pdo->prepare("INSERT IGNORE INTO blocklist (ip, incident_id, alasan, cara) VALUES (?, ?, ?, 'otomatis')");
    $diblokir = [];
    foreach ($q->fetchAll() as $i) {
        if (ipDianggapAman($pdo, $i['ip'])) {
            continue;
        }
        $simpan->execute([$i['ip'], $i['id'], mb_substr('Otomatis - insiden #' . $i['id'] . ' ' . $i['risiko'] . ': ' . $i['ringkasan'], 0, 255)]);
        $diblokir[] = $i['ip'];
    }
    return $diblokir;
}

/**
 * Tindakan operator terhadap IP sebuah insiden.
 * aksi: blokir | permanen | batal | hapus_aman
 */
function tindakanOperator(PDO $pdo, array $insiden, string $aksi): void
{
    $ip = $insiden['ip'];
    switch ($aksi) {
        case 'blokir':
            // Blokir manual oleh operator langsung dianggap keputusan permanen.
            // Jika IP sudah ada (misal "Blokir ulang"), catat waktu konfirmasinya.
            $pdo->prepare(
                "INSERT INTO blocklist (ip, incident_id, alasan, cara, permanen_pada) VALUES (?, ?, ?, 'manual', CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE dikonfirmasi_pada = CURRENT_TIMESTAMP,
                                         permanen_pada = COALESCE(permanen_pada, CURRENT_TIMESTAMP)"
            )->execute([$ip, $insiden['id'], mb_substr('Manual - insiden #' . $insiden['id'] . ': ' . $insiden['ringkasan'], 0, 255)]);
            $pdo->prepare('DELETE FROM ip_aman WHERE ip = ?')->execute([$ip]);
            break;

        case 'permanen':
            $pdo->prepare('UPDATE blocklist SET permanen_pada = CURRENT_TIMESTAMP WHERE ip = ? AND permanen_pada IS NULL')
                ->execute([$ip]);
            break;

        case 'batal':
            // Dibatalkan operator = dianggap salah deteksi, jangan diblokir otomatis lagi
            $pdo->prepare('DELETE FROM blocklist WHERE ip = ?')->execute([$ip]);
            $pdo->prepare('INSERT IGNORE INTO ip_aman (ip, alasan) VALUES (?, ?)')
                ->execute([$ip, 'Blokir dibatalkan operator (insiden #' . $insiden['id'] . ')']);
            break;

        case 'hapus_aman':
            $pdo->prepare('DELETE FROM ip_aman WHERE ip = ?')->execute([$ip]);
            break;
    }
}

/**
 * Tindakan massal: menangani sekaligus semua insiden yang dipilih operator dan masih perlu tindakan.
 *  - masih menyerang setelah diblokir  -> blokir ulang
 *  - diblokir otomatis, belum dikonfirmasi -> jadikan permanen
 *  - belum ditangani (dan tidak ditandai aman) -> blokir
 * Insiden lain (sudah ditangani, ditandai aman) dilewati.
 * @param int[] $ids
 * @return array{blokir:int, blokir_ulang:int, permanen:int, dilewati:int}
 */
function tindakanMassal(PDO $pdo, array $ids): array
{
    $hasil = ['blokir' => 0, 'blokir_ulang' => 0, 'permanen' => 0, 'dilewati' => 0];
    if (!$ids) {
        return $hasil;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare(
        "SELECT i.id, i.ip, i.risiko, i.status, i.ringkasan, b.cara, b.permanen_pada, (b.ip IS NOT NULL) AS diblokir,
                (a.ip IS NOT NULL) AS aman
         FROM incidents i LEFT JOIN blocklist b ON b.ip = i.ip LEFT JOIN ip_aman a ON a.ip = i.ip
         WHERE i.id IN ($ph)"
    );
    $q->execute(array_values($ids));
    foreach ($q->fetchAll() as $i) {
        if ($i['status'] === 'serangan_ulang') {
            tindakanOperator($pdo, $i, 'blokir');
            $hasil['blokir_ulang']++;
        } elseif ($i['diblokir'] && $i['cara'] === 'otomatis' && $i['permanen_pada'] === null) {
            tindakanOperator($pdo, $i, 'permanen');
            $hasil['permanen']++;
        } elseif ($i['status'] === 'baru' && !$i['diblokir'] && !$i['aman']) {
            tindakanOperator($pdo, $i, 'blokir');
            $hasil['blokir']++;
        } else {
            $hasil['dilewati']++;
        }
    }
    return $hasil;
}
