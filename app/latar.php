<?php
/**
 * Lalu lintas latar (mode demo): website uji tidak pernah diam.
 *
 * Setiap siklus, baris log untuk detik-detik yang sudah lewat ditambahkan ke data/live.log:
 *  - pengunjung biasa (ramai siang dan malam, sepi dini hari), sesekali membuka link lama (404);
 *  - pemantau uptime setiap 5 menit dan mesin pencari;
 *  - pegawai yang masuk ke halaman admin, kadang salah ketik kata sandi;
 *  - sesekali (rata-rata sekitar 7 kali sehari) bot pemindai acak dari internet;
 *  - IP yang sudah diblokir sesekali mencoba lagi dan ditolak server (403), seperti bot sungguhan.
 *
 * Penyerang serius (penebak kata sandi) TIDAK muncul di sini, karena di website sungguhan kejadiannya jarang.
 * Untuk menghadirkannya saat demo, gunakan tombol "Jalankan serangan tiruan".
 *
 * Tidak ada request yang dikirim; semua hanya baris log tiruan. Jika dashboard lama tidak dibuka,
 * lalu lintas yang terlewat diisi saat dibuka lagi (paling lama 24 jam ke belakang).
 */
require_once __DIR__ . '/contoh.php';
require_once __DIR__ . '/simulasi.php';

const LATAR_KUNJUNGAN_PER_HARI = 2300;
const LATAR_PINDAI_PER_JAM     = 0.3;   // peluang bot pemindai muncul dalam satu jam
const LATAR_KETUK_PER_JAM      = 5;     // rata-rata percobaan ulang IP terblokir per jam

/**
 * Membuat baris log untuk rentang waktu (dari, sampai] dalam detik unix.
 * @param string[] $dipakai IP yang sudah tercatat (agar pemindai baru memakai IP baru)
 * @param array<string,int> $terblokir ip => detik unix mulai diblokir
 */
function buatLalulintasLatar(int $dari, int $sampai, array $dipakai, array $terblokir = []): string
{
    if ($sampai <= $dari) {
        return '';
    }
    $wib = new DateTimeZone('Asia/Jakarta');
    $acak = fn(float $a, float $b) => $a + mt_rand() / mt_getrandmax() * ($b - $a);
    $pilih = fn(array $a) => $a[mt_rand(0, count($a) - 1)];
    $rata = array_sum(BOBOT_JAM) / 24;
    $baris = [];
    $tambah = function (int $t, string $ip, string $m, string $p, int $st, int $sz, string $ua, string $ref = '-')
              use (&$baris, $dari, $sampai) {
        if ($t > $dari && $t <= $sampai) {
            $baris[] = [$t, $ip, $m, $p, $st, $sz, $ua, $ref];
        }
    };

    // Per menit: jumlah kunjungan mengikuti keramaian jam itu
    for ($menit = intdiv($dari, 60) * 60; $menit <= $sampai; $menit += 60) {
        $jam = (int) (new DateTime('@' . $menit))->setTimezone($wib)->format('G');
        $lambda = LATAR_KUNJUNGAN_PER_HARI / 1440 * BOBOT_JAM[$jam] / $rata;
        $n = (int) floor($lambda + $acak(-0.5, 0.5) * min(1, $lambda) + $acak(0, 1));
        for ($k = 0; $k < $n; $k++) {
            $t = $menit + mt_rand(0, 59);
            $ip = '10.' . mt_rand(10, 60) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
            $ua = $pilih(UA_BIASA);
            for ($h = mt_rand(1, 4); $h > 0; $h--) {
                $p = mt_rand(1, 100) <= 2 ? $pilih(CONTOH_LINK_LAMA) : $pilih(CONTOH_HALAMAN);
                $st = in_array($p, CONTOH_LINK_LAMA, true) ? 404 : 200;
                $tambah($t, $ip, 'GET', $p, $st, $st === 200 ? mt_rand(6000, 42000) : 512, $ua);
                if (mt_rand(1, 100) <= 60) {
                    $tambah($t + 1, $ip, 'GET', $pilih(CONTOH_ASET), 200, mt_rand(2000, 90000), $ua, 'https://jejakpanel.my.id' . $p);
                }
                $t += mt_rand(8, 90);
            }
        }

        // Mesin pencari
        if (mt_rand(1, 100) <= 12) {
            $p = mt_rand(1, 100) <= 6 ? $pilih(CONTOH_LINK_LAMA) : $pilih(CONTOH_HALAMAN);
            $tambah($menit + mt_rand(0, 59), '192.0.2.20', 'GET', $p, in_array($p, CONTOH_LINK_LAMA, true) ? 404 : 200,
                    mt_rand(5000, 40000), 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
        }

        // Pegawai membuka halaman admin pada jam kerja, kadang salah ketik kata sandi
        if ($jam >= 7 && $jam <= 17 && $acak(0, 1) < 0.25 / 60) {
            $ip = $pilih(['10.20.0.11', '10.20.0.12', '10.20.0.27']);
            $t = $menit + mt_rand(0, 59);
            $tambah($t, $ip, 'GET', '/login.php', 200, 2100, UA_BIASA[0]);
            for ($g = mt_rand(0, 2); $g > 0; $g--) {
                $t += mt_rand(6, 20);
                $tambah($t, $ip, 'POST', '/login.php', 401, 312, UA_BIASA[0], '/login.php');
            }
            $t += mt_rand(6, 20);
            $tambah($t, $ip, 'POST', '/login.php', 302, 0, UA_BIASA[0], '/login.php');
            for ($a = mt_rand(2, 6); $a > 0; $a--) {
                $t += mt_rand(20, 120);
                $tambah($t, $ip, 'GET', $pilih(CONTOH_ADMIN), 200, mt_rand(3000, 9000), UA_BIASA[0]);
            }
        }

        // IP terblokir mencoba lagi: server menolak (403)
        if ($terblokir && $acak(0, 1) < LATAR_KETUK_PER_JAM / 60) {
            $ip = array_rand($terblokir);
            $t = max($menit + mt_rand(0, 59), $terblokir[$ip] + 1);
            for ($i = mt_rand(1, 4); $i > 0; $i--) {
                $p = mt_rand(0, 1) ? '/login.php' : $pilih(CONTOH_PINDAI);
                $tambah($t, $ip, $p === '/login.php' ? 'POST' : 'GET', $p, 403, 199, $pilih(UA_TEBAK));
                $t += mt_rand(1, 4);
            }
        }

        // Bot pemindai acak dari internet (risiko sedang, menunggu keputusan operator)
        if ($acak(0, 1) < LATAR_PINDAI_PER_JAM / 60) {
            do {
                $ip = $pilih(['192.0.2.', '198.51.100.', '203.0.113.']) . mt_rand(30, 254);
            } while (in_array($ip, $dipakai, true));
            $dipakai[] = $ip;
            $ua = $pilih(UA_PINDAI);
            $jalur = CONTOH_PINDAI;
            shuffle($jalur);
            $t = $menit + mt_rand(0, 40);
            foreach (array_slice($jalur, 0, mt_rand(10, 16)) as $p) {
                $tambah($t, $ip, 'GET', $p, mt_rand(1, 5) === 1 ? 403 : 404, mt_rand(150, 600), $ua);
                $t += mt_rand(1, 3);
            }
        }
    }

    // Pemantau uptime setiap 5 menit
    for ($t = intdiv($dari, 300) * 300 + 40; $t <= $sampai; $t += 300) {
        $tambah($t, '192.0.2.10', 'HEAD', '/', 200, 0, 'Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)');
    }

    usort($baris, fn($a, $b) => $a[0] <=> $b[0]);
    $teks = '';
    foreach ($baris as [$t, $ip, $m, $p, $st, $sz, $ua, $ref]) {
        $waktu = (new DateTime('@' . $t))->setTimezone($wib)->format('d/M/Y:H:i:s O');
        $teks .= "$ip - - [$waktu] \"$m $p HTTP/1.1\" $st $sz \"$ref\" \"$ua\"\n";
    }
    return $teks;
}

/** Menambahkan lalu lintas latar sampai detik ini ke data/live.log. Status disimpan di $s['latar_sampai']. */
const LATAR_MAKS_PER_SIKLUS = 7200;

function majukanLatar(PDO $pdo, array &$s): void
{
    $sekarang = time() - 1;
    $dari = max((int) ($s['latar_sampai'] ?? $sekarang - 60), $sekarang - 86400);
    if ($sekarang - $dari < 2) {
        return;
    }
    // Setelah lama tidak dibuka, ketertinggalan dikejar bertahap (paling banyak 2 jam per siklus)
    // supaya satu permintaan tidak terlalu lama di hosting bersama.
    $sekarang = min($sekarang, $dari + LATAR_MAKS_PER_SIKLUS);
    $dipakai = $pdo->query('SELECT ip FROM incidents UNION SELECT ip FROM blocklist UNION SELECT ip FROM ip_aman')
                   ->fetchAll(PDO::FETCH_COLUMN);
    // IP terblokir yang boleh "mengetuk" lagi. IP yang sedang dipakai simulasi dikecualikan,
    // karena simulasi "Uji blokir gagal" sengaja membuat blokirnya seolah tidak berlaku.
    $sim = bacaSimulasi();
    $dikecualikan = ($sim && !$sim['selesai']) ? ($sim['ips'] ?? []) : [];
    $terblokir = [];
    foreach ($pdo->query('SELECT ip, UNIX_TIMESTAMP(COALESCE(dikonfirmasi_pada, dibuat_pada)) AS sejak FROM blocklist') as $b) {
        if (!in_array($b['ip'], $dikecualikan, true)) {
            $terblokir[$b['ip']] = (int) $b['sejak'];
        }
    }
    $teks = buatLalulintasLatar($dari, $sekarang, $dipakai, $terblokir);
    if ($teks !== '') {
        file_put_contents(fileLogLangsung(), $teks, FILE_APPEND | LOCK_EX);
    }
    $s['latar_sampai'] = $sekarang;
}

/**
 * Perawatan berkala agar database dan file log tidak membengkak:
 * baris log pengunjung biasa yang lebih tua dari 3 hari dihapus
 * (log milik IP yang tercatat sebagai insiden, diblokir, atau ditandai aman tetap disimpan).
 */
function rawatData(PDO $pdo, array &$s): void
{
    if (time() - ($s['dirawat'] ?? 0) < 3600) {
        return;
    }
    $pdo->exec(
        'DELETE l FROM log_entries l
         LEFT JOIN incidents i ON i.ip = l.ip
         LEFT JOIN blocklist b ON b.ip = l.ip
         LEFT JOIN ip_aman a ON a.ip = l.ip
         WHERE l.waktu < NOW() - INTERVAL 72 HOUR AND i.ip IS NULL AND b.ip IS NULL AND a.ip IS NULL'
    );
    $s['dirawat'] = time();
}
