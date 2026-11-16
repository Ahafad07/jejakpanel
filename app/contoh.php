<?php
/**
 * Data contoh untuk demo: log akses 24 jam terakhir sebuah website satuan.
 *
 * Isinya dibuat ulang setiap "Reset demo" dan selalu berakhir beberapa menit sebelum saat ini,
 * sehingga grafik "24 jam terakhir" selalu terisi. Skenario penyerang tetap sama setiap reset
 * (memakai seed tetap) supaya demo bisa dilatih.
 *
 * Isi log sengaja dibuat mirip kondisi nyata:
 *  - ratusan pengunjung biasa (ramai siang dan malam, sepi dini hari), sebagian membuka link lama (404);
 *  - mesin pencari dan pemantau uptime yang sah;
 *  - pegawai yang salah ketik kata sandi 1-3 kali lalu berhasil masuk;
 *  - 22 IP penyerang: pemindai, penebak kata sandi, dan satu rangkaian yang berhasil masuk.
 *    Empat di antaranya sudah diblokir otomatis oleh sistem semalam dan menunggu konfirmasi operator.
 *    Tidak ada tindakan manual di data contoh: semua keputusan operator dimulai dari kosong.
 * Tiga kelompok pertama TIDAK boleh terdeteksi sebagai serangan; ini sekaligus menguji salah deteksi.
 *
 * Semua IP memakai alamat privat (10.x) atau blok dokumentasi RFC 5737. Tidak ada request yang dikirim.
 */

const CONTOH_HALAMAN = ['/', '/', '/', '/profil', '/berita', '/berita', '/berita/bakti-sosial-desa-binaan',
    '/berita/upacara-hut-satuan', '/berita/latihan-gabungan', '/berita/donor-darah', '/galeri', '/galeri/2026',
    '/kontak', '/pengumuman', '/pengumuman/penerimaan-tamtama', '/sejarah', '/struktur-organisasi', '/layanan'];
const CONTOH_ASET = ['/assets/css/style.css', '/assets/js/app.js', '/assets/img/logo.png', '/assets/img/banner.jpg'];
const CONTOH_LINK_LAMA = ['/berita/arsip-2019', '/galeri/2018', '/favicon.ico', '/pengumuman/lama.pdf',
    '/berita/hut-ke-60', '/apple-touch-icon.png'];
const CONTOH_PINDAI = ['/admin', '/administrator', '/wp-login.php', '/wp-admin/', '/phpmyadmin/', '/pma/',
    '/.env', '/.git/config', '/backup.zip', '/backup.sql', '/config.php.bak', '/db.sql', '/server-status',
    '/cpanel', '/adminer.php', '/old/', '/test/', '/panel/', '/uploads/', '/api/v1/users', '/.htaccess',
    '/install.php', '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php', '/wp-config.php.bak', '/.aws/credentials',
    '/xmlrpc.php', '/shell.php', '/info.php', '/storage/logs/laravel.log', '/.DS_Store'];
const CONTOH_ADMIN = ['/admin/dashboard', '/admin/pengguna', '/admin/pengaturan', '/admin/backup', '/admin/berita/tambah', '/admin/upload'];

const UA_BIASA = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
    'Mozilla/5.0 (Linux; Android 14; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Linux; Android 13; Redmi Note 12) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0',
];
const UA_PINDAI = ['Mozilla/5.0 (compatible; Nuclei - Open-source project)', 'Mozilla/5.0 zgrab/0.x',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0 Safari/537.36', 'Go-http-client/1.1'];
const UA_TEBAK = ['python-requests/2.32.3', 'Mozilla/5.0 (Hydra)', 'curl/8.5.0', 'Go-http-client/1.1'];

/** Bobot keramaian per jam (WIB): sepi dini hari, ramai jam kerja dan malam. */
const BOBOT_JAM = [0.25, 0.15, 0.1, 0.1, 0.15, 0.35, 0.6, 0.9, 1.2, 1.4, 1.4, 1.3,
                   1.1, 1.2, 1.3, 1.2, 1.0, 0.9, 1.0, 1.3, 1.4, 1.2, 0.8, 0.45];

/**
 * Daftar penyerang: [ip, jenis, menit sebelum log berakhir, opsi].
 *   jenis: pindai | tebak | pindai_tebak | rantai (pindai -> tebak -> berhasil masuk)
 *   opsi : n (jumlah percobaan), blokir (otomatis|manual), setelah (blokir setelah percobaan ke-n),
 *          ulang (jumlah percobaan saat kembali beberapa jam kemudian), aman (alasan)
 */
function daftarPenyerangContoh(): array
{
    return [
        // Belum ditangani: menunggu keputusan operator
        ['203.0.113.45',  'rantai',       132, ['n' => 64]],
        ['192.0.2.77',    'tebak',        318, ['n' => 140]],
        ['198.51.100.61', 'tebak',        505, ['n' => 46, 'path' => '/admin/login']],
        ['203.0.113.140', 'tebak',        640, ['n' => 33]],
        ['192.0.2.201',   'tebak',        912, ['n' => 58, 'path' => '/admin/login']],
        ['203.0.113.88',  'pindai_tebak', 1180, ['n' => 72]],
        ['198.51.100.240','tebak',        1340, ['n' => 27]],
        ['198.51.100.23', 'pindai',       95,  ['n' => 24]],
        ['203.0.113.9',   'pindai',       260, ['n' => 30]],
        ['192.0.2.33',    'pindai',       430, ['n' => 14]],
        ['198.51.100.150','pindai',       700, ['n' => 18]],
        ['203.0.113.201', 'pindai',       860, ['n' => 26]],
        ['192.0.2.115',   'pindai',       1015, ['n' => 12]],
        ['198.51.100.7',  'pindai',       1260, ['n' => 21]],
        ['192.0.2.88',    'pindai',       1390, ['n' => 16]],
        ['203.0.113.230', 'pindai',       555, ['n' => 11]],
        // Diblokir otomatis oleh sistem saat operator tidak berjaga, semua menunggu konfirmasi operator
        ['198.51.100.84', 'pindai_tebak', 380, ['n' => 90, 'blokir' => 'otomatis', 'setelah' => 49]],
        ['203.0.113.17',  'tebak',        760, ['n' => 120, 'blokir' => 'otomatis', 'setelah' => 23]],
        ['192.0.2.150',   'tebak',        58,  ['n' => 85, 'blokir' => 'otomatis', 'setelah' => 22]],
        ['203.0.113.66',  'tebak',        980, ['n' => 52, 'blokir' => 'otomatis', 'setelah' => 24, 'ulang' => 30]],
        ['198.51.100.199','pindai',       1105, ['n' => 25]],
        // Pemindaian kerentanan rutin milik tim IT sendiri: IP-nya sudah terdaftar aman di pengaturan, jadi terdeteksi tapi tidak diblokir
        ['10.20.1.15',    'pindai',       620, ['n' => 30, 'aman' => 'Terdaftar di pengaturan: pemindaian kerentanan rutin oleh tim IT satuan', 'ua' => 'Mozilla/5.0 (compatible; OpenVAS-VT 22.4)']],
    ];
}

function barisLog(string $ip, int $t, string $method, string $path, int $status, int $size, string $ua, string $ref = '-'): array
{
    return [$t, $ip, $method, $path, $status, $size, $ua, $ref];
}

/**
 * Membuat log contoh yang berakhir pada $akhir (unix time), ditulis ke data/contoh-access.log.
 * @return array{file:string, blokir:array, aman:array, baris:int}
 */
function buatLogContoh(int $akhir): array
{
    mt_srand(2026);
    $awal = $akhir - 86400;
    $wib = new DateTimeZone('Asia/Jakarta');
    $jamDari = fn(int $t) => (int) (new DateTime('@' . $t))->setTimezone($wib)->format('G');
    $acak = fn(float $a, float $b) => $a + mt_rand() / mt_getrandmax() * ($b - $a);
    $pilih = fn(array $a) => $a[mt_rand(0, count($a) - 1)];
    $baris = [];

    // 1. Pengunjung biasa: kunjungan berisi beberapa halaman + aset
    $pengunjung = [];
    for ($i = 0; $i < 640; $i++) {
        $pengunjung[] = ['10.' . mt_rand(10, 60) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254), $pilih(UA_BIASA)];
    }
    $totalBobot = array_sum(BOBOT_JAM);
    for ($k = 0; $k < 2300; $k++) {
        // pilih jam berdasarkan bobot, lalu detik acak di jam itu
        do {
            $t = (int) $acak($awal, $akhir - 120);
        } while ($acak(0, 1.4) > BOBOT_JAM[$jamDari($t)]);
        [$ip, $ua] = $pengunjung[mt_rand(0, (int) (count($pengunjung) * $acak(0.15, 1)) - 1)];
        $halaman = mt_rand(1, 4);
        for ($h = 0; $h < $halaman; $h++) {
            $p = mt_rand(1, 100) <= 2 ? $pilih(CONTOH_LINK_LAMA) : $pilih(CONTOH_HALAMAN);
            $status = in_array($p, CONTOH_LINK_LAMA, true) ? 404 : 200;
            $baris[] = barisLog($ip, $t, 'GET', $p, $status, $status === 200 ? mt_rand(6000, 42000) : 512, $ua);
            if ($h === 0) {
                foreach (CONTOH_ASET as $a) {
                    if (mt_rand(1, 100) <= 60) {
                        $baris[] = barisLog($ip, $t + 1, 'GET', $a, 200, mt_rand(2000, 90000), $ua, 'https://jejakpanel.my.id' . $p);
                    }
                }
            }
            $t += mt_rand(8, 90);
        }
    }

    // 2. Mesin pencari dan pemantau uptime (sah)
    for ($t = $awal + 40; $t < $akhir - 60; $t += 300) {
        $baris[] = barisLog('192.0.2.10', $t, 'HEAD', '/', 200, 0, 'Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)');
    }
    foreach ([['192.0.2.20', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 170],
              ['192.0.2.21', 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 90]] as [$ip, $ua, $n]) {
        for ($i = 0; $i < $n; $i++) {
            $t = (int) $acak($awal, $akhir - 60);
            $p = mt_rand(1, 100) <= 6 ? $pilih(CONTOH_LINK_LAMA) : $pilih(CONTOH_HALAMAN);
            $baris[] = barisLog($ip, $t, 'GET', $p, in_array($p, CONTOH_LINK_LAMA, true) ? 404 : 200, mt_rand(5000, 40000), $ua);
        }
        $baris[] = barisLog($ip, (int) $acak($awal, $akhir - 60), 'GET', '/robots.txt', 200, 68, $ua);
    }

    // 3. Pegawai yang mengelola website: kadang salah ketik kata sandi
    foreach (['10.20.0.11', '10.20.0.12', '10.20.0.27'] as $ip) {
        for ($s = 0; $s < 3; $s++) {
            $t = (int) $acak($awal + 7 * 3600, $akhir - 600);
            $baris[] = barisLog($ip, $t, 'GET', '/login.php', 200, 2100, UA_BIASA[0]);
            for ($g = mt_rand(0, 3); $g > 0; $g--) {
                $t += mt_rand(6, 20);
                $baris[] = barisLog($ip, $t, 'POST', '/login.php', 401, 312, UA_BIASA[0], '/login.php');
            }
            $t += mt_rand(6, 20);
            $baris[] = barisLog($ip, $t, 'POST', '/login.php', 302, 0, UA_BIASA[0], '/login.php');
            for ($a = mt_rand(3, 8); $a > 0; $a--) {
                $t += mt_rand(20, 120);
                $baris[] = barisLog($ip, $t, 'GET', $pilih(CONTOH_ADMIN), 200, mt_rand(3000, 9000), UA_BIASA[0]);
            }
        }
    }

    // 4. Penyerang
    $blokir = [];
    $aman = [];
    foreach (daftarPenyerangContoh() as [$ip, $jenis, $menit, $o]) {
        $t = $akhir - $menit * 60;
        $uaPindai = $o['ua'] ?? $pilih(UA_PINDAI);
        $uaTebak = $pilih(UA_TEBAK);
        $loginPath = $o['path'] ?? '/login.php';
        $percobaan = 0;          // nomor permintaan penyerang (untuk menentukan saat diblokir)
        $waktuBlokir = null;
        $setelah = $o['setelah'] ?? PHP_INT_MAX;

        $kirim = function (string $method, string $path, int $status, int $size, string $ua, string $ref = '-')
                 use (&$baris, &$t, &$percobaan, &$waktuBlokir, $setelah, $ip) {
            $percobaan++;
            if ($waktuBlokir !== null) {
                $status = 403;    // sesudah diblokir, server menolak
                $size = 199;
            }
            $baris[] = barisLog($ip, $t, $method, $path, $status, $size, $ua, $ref);
            if ($percobaan === $setelah && $waktuBlokir === null) {
                $waktuBlokir = $t + 2;
                $t = $waktuBlokir;   // permintaan berikutnya datang sesudah aturan berlaku
            }
        };

        if ($jenis === 'pindai' || $jenis === 'pindai_tebak' || $jenis === 'rantai') {
            $jalur = CONTOH_PINDAI;
            shuffle($jalur);
            $n = $jenis === 'pindai' ? $o['n'] : 22;
            for ($i = 0; $i < $n; $i++) {
                $p = $jalur[$i % count($jalur)];
                $kirim('GET', $p, mt_rand(1, 5) === 1 ? 403 : 404, mt_rand(150, 600), $uaPindai);
                $t += mt_rand(1, 3);
            }
            if ($jenis !== 'pindai') {
                $kirim('GET', $loginPath, 200, 2100, $uaPindai);   // halaman login ternyata ada
                $t += mt_rand(40, 200);
            }
        }
        if ($jenis !== 'pindai') {
            $n = $o['n'];
            for ($i = 0; $i < $n; $i++) {
                $kirim('POST', $loginPath, 401, 312, $uaTebak, $loginPath);
                $t += mt_rand(1, 2);
            }
            if ($jenis === 'rantai') {
                $kirim('POST', $loginPath, 302, 0, $uaTebak, $loginPath);
                $t += 2;
                foreach (['/admin/dashboard', '/admin/pengguna', '/admin/backup', '/admin/upload', '/admin/pengaturan'] as $p) {
                    $kirim('GET', $p, 200, mt_rand(3000, 9000), $uaTebak);
                    $t += mt_rand(4, 15);
                }
            }
        }
        // Penyerang yang sudah diblokir kembali beberapa jam kemudian dan langsung ditolak
        if (!empty($o['ulang']) && $waktuBlokir !== null) {
            $t += mt_rand(2, 5) * 3600;
            $t = min($t, $akhir - 900);
            for ($i = 0; $i < $o['ulang']; $i++) {
                $kirim('GET', $jenis === 'pindai' ? $pilih(CONTOH_PINDAI) : $loginPath, 404, 300, $jenis === 'pindai' ? $uaPindai : $uaTebak);
                $t += mt_rand(1, 3);
            }
        }

        if (!empty($o['blokir'])) {
            $blokir[] = [
                'ip' => $ip, 'cara' => $o['blokir'], 'waktu' => $waktuBlokir,
                'permanen' => $o['blokir'] === 'manual' ? $waktuBlokir
                            : (($o['permanen'] ?? null) !== null ? $waktuBlokir + $o['permanen'] * 60 : null),
            ];
        }
        if (!empty($o['aman'])) {
            $aman[] = ['ip' => $ip, 'alasan' => $o['aman'], 'waktu' => $t + 1800];
        }
    }

    usort($baris, fn($a, $b) => $a[0] <=> $b[0]);
    $file = folderData() . '/contoh-access.log';
    $h = fopen($file, 'w');
    foreach ($baris as [$t, $ip, $method, $path, $status, $size, $ua, $ref]) {
        if ($t > $akhir) {
            continue;
        }
        $waktu = (new DateTime('@' . $t))->setTimezone($wib)->format('d/M/Y:H:i:s O');
        fwrite($h, "$ip - - [$waktu] \"$method $path HTTP/1.1\" $status $size \"$ref\" \"$ua\"\n");
    }
    fclose($h);
    mt_srand();   // kembalikan pengacak ke kondisi acak biasa

    return ['file' => $file, 'blokir' => $blokir, 'aman' => $aman, 'baris' => count($baris)];
}

/** Mengisi database dengan data contoh: log, riwayat blokir, dan IP yang ditandai aman. */
function muatDataContoh(PDO $pdo): array
{
    $akhir = time() - 120;
    $c = buatLogContoh($akhir);

    $pdo->exec('DELETE FROM blocklist');
    $pdo->exec('DELETE FROM ip_aman');
    $pdo->exec('DELETE FROM incidents');
    $pdo->exec('DELETE FROM log_entries');
    $pdo->exec('DELETE FROM import_state');
    foreach (['incidents', 'incident_stages', 'log_entries', 'blocklist'] as $t) {
        $pdo->exec("ALTER TABLE $t AUTO_INCREMENT = 1");
    }

    $hasil = imporLog($pdo, $c['file']);
    @unlink($c['file']);
    $pdo->exec('DELETE FROM import_state');

    $wib = fn(?int $t) => $t === null ? null : (new DateTime('@' . $t))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s');
    $q = $pdo->prepare(
        'INSERT INTO blocklist (ip, alasan, cara, dibuat_pada, permanen_pada, diterapkan_pada) VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($c['blokir'] as $b) {
        $alasan = $b['cara'] === 'otomatis' ? 'Otomatis - login paksa sedang berlangsung' : 'Manual - diputuskan operator';
        $q->execute([$b['ip'], $alasan, $b['cara'], $wib($b['waktu']), $wib($b['permanen']), $wib($b['waktu'])]);
    }
    $q = $pdo->prepare('INSERT INTO ip_aman (ip, alasan, dibuat_pada) VALUES (?, ?, ?)');
    foreach ($c['aman'] as $a) {
        $q->execute([$a['ip'], $a['alasan'], $wib(min($a['waktu'], $akhir))]);
    }
    return $hasil;
}
