<?php
/**
 * Simulasi serangan dari web (mode demo).
 *
 * Simulasi tidak mengirim request ke mana pun. Ia hanya menulis baris log tiruan ke
 * data/live.log sesuai jadwal, sedikit demi sedikit setiap siklus berjalan.
 * Saat menulis baris milik penyerang, simulasi meniru perilaku server:
 * jika IP penyerang sudah diblokir, baris ditulis dengan status 403 (ditolak).
 */
require_once __DIR__ . '/pengaturan.php';

const SIM_SCAN_PATHS = ['/admin', '/administrator', '/wp-login.php', '/wp-admin', '/phpmyadmin', '/.env',
    '/.git/config', '/backup.zip', '/backup.sql', '/config.php.bak', '/db.sql', '/server-status',
    '/admin/login', '/cpanel', '/adminer.php', '/old', '/test', '/login.php', '/panel', '/uploads',
    '/install.php', '/.htaccess'];
const SIM_NORMAL_PATHS = ['/', '/profil', '/berita', '/berita/kegiatan-bakti-sosial', '/galeri', '/kontak',
    '/assets/css/style.css', '/assets/js/app.js', '/assets/img/logo.png', '/pengumuman'];
const SIM_ADMIN_PATHS = ['/admin/pengguna', '/admin/pengaturan', '/admin/backup', '/admin/berita/tambah', '/admin/upload'];
const SIM_UA_NORMAL = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const SIM_UA_SCAN = 'Mozilla/5.0 (compatible; scanner-sim/1.0)';
const SIM_UA_BRUTE = 'python-requests/2.32 (sim)';

function fileSimulasi(): string { return folderData() . '/simulasi.json'; }
function fileLogLangsung(): string { return folderData() . '/live.log'; }

function bacaSimulasi(): ?array
{
    $f = fileSimulasi();
    return file_exists($f) ? json_decode(file_get_contents($f), true) : null;
}

function simpanSimulasi(?array $sim): void
{
    if ($sim === null) {
        @unlink(fileSimulasi());
        return;
    }
    file_put_contents(fileSimulasi(), json_encode($sim), LOCK_EX);
}

function acak(float $a, float $b): float
{
    return $a + mt_rand() / mt_getrandmax() * ($b - $a);
}

/** Kunjungan normal di latar belakang sepanjang simulasi. */
function jadwalNormal(float $sampai): array
{
    $pengunjung = [];
    for ($i = 0; $i < 30; $i++) {
        $pengunjung[] = '10.' . mt_rand(10, 60) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
    }
    $acara = [];
    for ($t = acak(0, 1); $t < $sampai; $t += acak(1.0, 2.2)) {
        $acara[] = [$t, $pengunjung[array_rand($pengunjung)], 'GET', SIM_NORMAL_PATHS[array_rand(SIM_NORMAL_PATHS)],
                    200, mt_rand(800, 40000), SIM_UA_NORMAL, '-', false];
    }
    return $acara;
}

/** Setelah berhasil masuk, penyerang membuka halaman admin berulang kali. */
function jadwalPascaMasuk(string $ip, float $dari, float $sampai): array
{
    $acara = [];
    for ($t = $dari; $t < $sampai; $t += acak(1.5, 2.5)) {
        $acara[] = [$t, $ip, 'GET', SIM_ADMIN_PATHS[array_rand(SIM_ADMIN_PATHS)], 200, mt_rand(3000, 9000), SIM_UA_BRUTE, '-', true];
    }
    return $acara;
}

/** Memindai alamat umum mulai detik $t. Mengembalikan [acara, detik selesai]. */
function jadwalPindai(string $ip, float $t, int $n, float $jedaMin, float $jedaMaks): array
{
    $paths = SIM_SCAN_PATHS;
    shuffle($paths);
    $acara = [];
    foreach (array_slice($paths, 0, $n) as $p) {
        $acara[] = [$t, $ip, 'GET', $p, $p === '/login.php' ? 200 : [404, 404, 404, 403][mt_rand(0, 3)], mt_rand(150, 600), SIM_UA_SCAN, '-', true];
        $t += acak($jedaMin, $jedaMaks);
    }
    return [$acara, $t];
}

/** Menebak kata sandi $n kali, lalu berhasil. Mengembalikan [acara, detik berhasil]. */
function jadwalTebak(string $ip, float $t, int $n, string $path = '/login.php'): array
{
    $acara = [];
    for ($i = 0; $i < $n; $i++) {
        $acara[] = [$t, $ip, 'POST', $path, 401, 312, SIM_UA_BRUTE, $path, true];
        $t += acak(0.5, 0.8);
    }
    $t += 1;
    $acara[] = [$t, $ip, 'POST', $path, 302, 0, SIM_UA_BRUTE, $path, true];
    return [$acara, $t];
}

/** Pegawai sungguhan yang salah ketik kata sandi beberapa kali. Tidak boleh terdeteksi. */
function jadwalPegawai(float $t): array
{
    $ip = ['10.20.0.11', '10.20.0.12', '10.20.0.27'][mt_rand(0, 2)];
    $acara = [[$t, $ip, 'GET', '/login.php', 200, 2100, SIM_UA_NORMAL, '-', false]];
    for ($i = 0; $i < 3; $i++) {
        $t += acak(5, 9);
        $acara[] = [$t, $ip, 'POST', '/login.php', 401, 312, SIM_UA_NORMAL, '/login.php', false];
    }
    $t += acak(6, 10);
    $acara[] = [$t, $ip, 'POST', '/login.php', 302, 0, SIM_UA_NORMAL, '/login.php', false];
    foreach (['/admin/dashboard', '/admin/berita/tambah'] as $p) {
        $t += acak(4, 8);
        $acara[] = [$t, $ip, 'GET', $p, 200, 5120, SIM_UA_NORMAL, '-', false];
    }
    return $acara;
}

/**
 * Tiga penyerang dengan tingkat bahaya berbeda, ditambah satu pegawai yang salah ketik kata sandi:
 *  - penyerang 1: pengintaian -> login paksa -> berhasil masuk  (diblokir otomatis)
 *  - penyerang 2: hanya pengintaian (risiko sedang, sengaja tidak diblokir otomatis: keputusan operator)
 *  - penyerang 3: login paksa ke /admin/login                    (diblokir otomatis)
 */
function jadwalSeranganBaru(array $ips): array
{
    $total = 90.0;
    $acara = array_merge(jadwalNormal($total), jadwalPegawai(20));

    [$a, $t] = jadwalPindai($ips[0], 4, 22, 0.5, 0.8);
    $acara = array_merge($acara, $a);
    [$a, $t] = jadwalTebak($ips[0], $t + 6, mt_rand(38, 44));
    $acara = array_merge($acara, $a, jadwalPascaMasuk($ips[0], $t + 2, $total));

    [$a] = jadwalPindai($ips[1], 14, 18, 1.0, 1.6);
    $acara = array_merge($acara, $a);

    [$a, $t] = jadwalTebak($ips[2], 38, mt_rand(32, 36), '/admin/login');
    $acara = array_merge($acara, $a, jadwalPascaMasuk($ips[2], $t + 2, $total));
    return $acara;
}

/** IP yang sudah diblokir kembali menyerang, seolah aturan blokir di server hilang (misal .htaccess tertimpa). */
function jadwalBlokirGagal(array $ips): array
{
    $total = 80.0;
    $acara = jadwalNormal($total);
    foreach ($ips as $i => $ip) {
        [$a, $t] = jadwalTebak($ip, 2 + $i * 7, mt_rand(22, 28));
        $acara = array_merge($acara, $a, jadwalPascaMasuk($ip, $t + 1.5, $total));
    }
    return $acara;
}

/** Memilih $n IP penyerang baru dari blok dokumentasi yang belum pernah dipakai. */
function ipPenyerangBaru(PDO $pdo, int $n): array
{
    $dipakai = $pdo->query('SELECT ip FROM incidents UNION SELECT ip FROM blocklist UNION SELECT ip FROM ip_aman')
                   ->fetchAll(PDO::FETCH_COLUMN);
    $hasil = [];
    while (count($hasil) < $n) {
        $ip = ['192.0.2.', '198.51.100.', '203.0.113.'][mt_rand(0, 2)] . mt_rand(30, 254);
        if (!in_array($ip, $dipakai, true) && !in_array($ip, $hasil, true)) {
            $hasil[] = $ip;
        }
    }
    return $hasil;
}

/**
 * Memulai simulasi.
 * jenis: "serangan" (tiga penyerang baru) atau "blokir_gagal" (sampai tiga IP yang sudah diblokir
 *        kembali, seolah aturan blokir tidak berlaku di server).
 */
function mulaiSimulasi(PDO $pdo, string $jenis): array
{
    $sim = bacaSimulasi();
    if ($sim && !$sim['selesai']) {
        throw new RuntimeException('Simulasi lain masih berjalan. Tunggu sampai selesai.');
    }

    if ($jenis === 'blokir_gagal') {
        $ips = $pdo->query('SELECT ip FROM blocklist ORDER BY dibuat_pada DESC, id DESC LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
        if (!$ips) {
            throw new RuntimeException('Belum ada IP yang diblokir. Jalankan simulasi serangan baru dulu.');
        }
        $acara = jadwalBlokirGagal($ips);
    } else {
        $ips = ipPenyerangBaru($pdo, 3);
        $acara = jadwalSeranganBaru($ips);
        $jenis = 'serangan';
    }

    usort($acara, fn($a, $b) => $a[0] <=> $b[0]);
    $sim = [
        'jenis' => $jenis, 'ips' => $ips, 'mulai' => microtime(true),
        'total' => end($acara)[0], 'acara' => $acara, 'indeks' => 0, 'selesai' => false,
    ];
    simpanSimulasi($sim);
    return statusSimulasi();
}

/** Menulis baris log yang jadwalnya sudah lewat. @return int jumlah baris ditulis */
function majukanSimulasi(PDO $pdo): int
{
    $sim = bacaSimulasi();
    if (!$sim || $sim['selesai']) {
        return 0;
    }
    $berlalu = microtime(true) - $sim['mulai'];
    $ips = $sim['ips'] ?? [$sim['ip']];

    // Status blokir tiap IP penyerang saat ini, untuk meniru perilaku server
    $ph = implode(',', array_fill(0, count($ips), '?'));
    $q = $pdo->prepare("SELECT ip, UNIX_TIMESTAMP(dikonfirmasi_pada) AS ulang, UNIX_TIMESTAMP(dibuat_pada) AS dibuat
                        FROM blocklist WHERE ip IN ($ph)");
    $q->execute($ips);
    $berlaku = [];   // ip => sejak kapan server menolak IP ini
    foreach ($q->fetchAll() as $b) {
        if ($sim['jenis'] === 'blokir_gagal') {
            // Aturan dianggap tidak berlaku, sampai operator melakukan "Blokir ulang" sesudah simulasi dimulai
            if ($b['ulang'] !== null && (float) $b['ulang'] > $sim['mulai']) {
                $berlaku[$b['ip']] = (float) $b['ulang'];
            }
        } else {
            $berlaku[$b['ip']] = (float) ($b['ulang'] ?? $b['dibuat']);
        }
    }

    $wib = new DateTimeZone('Asia/Jakarta');
    $tulis = '';
    $n = 0;
    while ($sim['indeks'] < count($sim['acara']) && $sim['acara'][$sim['indeks']][0] <= $berlalu) {
        [$t, $ip, $method, $path, $status, $size, $ua, $ref, $penyerang] = $sim['acara'][$sim['indeks']];
        $detik = $sim['mulai'] + $t;
        if ($penyerang && isset($berlaku[$ip])) {
            // Server baru bisa menolak sesudah aturan blokir berlaku
            $status = 403;
            $size = 199;
            $detik = max($detik, $berlaku[$ip] + 1);
        }
        $waktu = (new DateTime('@' . (int) floor($detik)))->setTimezone($wib)->format('d/M/Y:H:i:s O');
        $tulis .= "$ip - - [$waktu] \"$method $path HTTP/1.1\" $status $size \"$ref\" \"$ua\"\n";
        $sim['indeks']++;
        $n++;
    }
    if ($tulis !== '') {
        file_put_contents(fileLogLangsung(), $tulis, FILE_APPEND | LOCK_EX);
    }
    if ($sim['indeks'] >= count($sim['acara'])) {
        $sim['selesai'] = true;
    }
    simpanSimulasi($sim);
    return $n;
}

function statusSimulasi(): ?array
{
    $sim = bacaSimulasi();
    if (!$sim) {
        return null;
    }
    return [
        'jenis'    => $sim['jenis'],
        'ips'      => $sim['ips'] ?? [$sim['ip']],
        'berjalan' => !$sim['selesai'],
        'detik'    => (int) min($sim['total'], microtime(true) - $sim['mulai']),
        'total'    => (int) ceil($sim['total']),
    ];
}
