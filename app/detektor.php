<?php
/**
 * JejakPanel - mesin deteksi dan penyusun kronologi serangan.
 *
 * Membaca log_entries, mengelompokkan aktivitas mencurigakan per IP menjadi
 * tahap (pengintaian, login paksa, login berhasil), lalu menyimpannya sebagai
 * satu insiden per IP. ID insiden dipertahankan antar-putaran deteksi.
 *
 * Untuk IP yang sudah diblokir operator:
 *  - permintaan sesudah waktu blokir yang DITOLAK server (status 403) berarti blokir bekerja;
 *  - permintaan sesudah waktu blokir yang DITERIMA server berarti blokir belum terpasang
 *    -> insiden dibuka lagi sebagai "serangan_ulang" dan dinaikkan ke risiko kritis.
 */

// ---------------------------------------------------------------------------
// Aturan deteksi (ambang batas). Jika diubah, sesuaikan juga teks di kronologi.js.
// ---------------------------------------------------------------------------
const LOGIN_PATHS      = ['/login.php', '/admin/login', '/wp-login.php'];
const JEDA_MAKS_DETIK  = 300;  // jeda > 5 menit = rangkaian baru
const MIN_PATH_SCAN    = 10;   // pengintaian: minimal 10 alamat berbeda yang tidak ada (404/403)
const MIN_LOGIN_GAGAL  = 20;   // login paksa: minimal 20 percobaan gagal dalam satu rangkaian
const JENDELA_BERHASIL = 600;  // login berhasil dihitung jika <= 10 menit setelah login paksa
const PATH_SENSITIF    = ['/.env', '/.git/config', '/backup.zip', '/backup.sql', '/db.sql',
                          '/config.php.bak', '/phpmyadmin', '/adminer.php', '/install.php'];

const TEKNIK = [
    'pengintaian'    => 'T1595.003', // Active Scanning: Wordlist Scanning
    'login_paksa'    => 'T1110.001', // Brute Force: Password Guessing
    'login_berhasil' => 'T1078',     // Valid Accounts
];
const LEVEL_RISIKO = ['rendah' => 1, 'sedang' => 2, 'tinggi' => 3, 'kritis' => 4];
const LABEL_JENIS  = [
    'pengintaian'    => 'Pengintaian',
    'login_paksa'    => 'Login paksa',
    'login_berhasil' => 'Login berhasil',
    'serangan_ulang' => 'Serangan setelah diblokir',
];

function durasi(int $detik): string
{
    if ($detik < 60) {
        return "$detik detik";
    }
    $m = intdiv($detik, 60);
    $s = $detik % 60;
    return $s ? "$m menit $s detik" : "$m menit";
}

function jam(string $waktu): string
{
    return date('H.i.s', strtotime($waktu));
}

/** Pecah daftar kejadian (urut waktu) menjadi rangkaian berdasarkan jeda. */
function rangkaian(array $events): array
{
    $hasil = [];
    $sekarang = [];
    $sebelum = null;
    foreach ($events as $e) {
        $t = strtotime($e['waktu']);
        if ($sebelum !== null && $t - $sebelum > JEDA_MAKS_DETIK) {
            $hasil[] = $sekarang;
            $sekarang = [];
        }
        $sekarang[] = $e;
        $sebelum = $t;
    }
    if ($sekarang) {
        $hasil[] = $sekarang;
    }
    return $hasil;
}

function isLogin(array $e): bool
{
    return $e['method'] === 'POST' && in_array($e['path'], LOGIN_PATHS, true);
}

/** Menyusun tahap-tahap serangan dari kejadian sebelum waktu blokir. */
function susunTahap(PDO $pdo, string $ip, array $events): array
{
    $scan   = array_values(array_filter($events, fn($e) => !isLogin($e) && in_array((int) $e['status'], [403, 404], true)));
    $gagal  = array_values(array_filter($events, fn($e) => isLogin($e) && in_array((int) $e['status'], [401, 403], true)));
    $sukses = array_values(array_filter($events, fn($e) => isLogin($e) && in_array((int) $e['status'], [200, 302], true)));

    $tahap = [];

    foreach (rangkaian($scan) as $r) {
        $paths = array_unique(array_column($r, 'path'));
        if (count($paths) < MIN_PATH_SCAN) {
            continue;
        }
        $mulai = $r[0]['waktu'];
        $selesai = end($r)['waktu'];
        $sensitif = array_values(array_intersect(PATH_SENSITIF, $paths));

        $q = $pdo->prepare(
            "SELECT DISTINCT path FROM log_entries
             WHERE ip = ? AND method = 'GET' AND status = 200 AND waktu BETWEEN ? AND ?"
        );
        $q->execute([$ip, $mulai, $selesai]);
        $ditemukan = $q->fetchAll(PDO::FETCH_COLUMN);

        $teks = 'Dalam ' . durasi(strtotime($selesai) - strtotime($mulai)) . ", IP $ip mencoba " . count($r)
              . ' kali membuka ' . count($paths) . ' alamat berbeda yang tidak tersedia di website. '
              . 'Pola ini menandakan penyerang sedang memetakan website untuk mencari halaman admin atau file yang bocor.';
        if ($sensitif) {
            $teks .= ' Alamat sensitif yang dicari antara lain: ' . implode(', ', array_slice($sensitif, 0, 4)) . '.';
        }
        if ($ditemukan) {
            $teks .= ' Alamat yang ternyata ADA dan berhasil ditemukan penyerang: ' . implode(', ', $ditemukan) . '.';
        }
        $tahap[] = [
            'jenis' => 'pengintaian', 'mulai' => $mulai, 'selesai' => $selesai, 'jumlah' => count($r),
            'risiko' => 'sedang', 'teknik' => TEKNIK['pengintaian'], 'penjelasan' => $teks,
            'rekomendasi' => 'Pastikan file cadangan, .env, dan folder .git tidak bisa diakses publik. '
                           . 'Pantau IP ini; jika berlanjut ke percobaan login, blokir.',
        ];
    }

    foreach (rangkaian($gagal) as $r) {
        if (count($r) < MIN_LOGIN_GAGAL) {
            continue;
        }
        $mulai = $r[0]['waktu'];
        $selesai = end($r)['waktu'];
        $lama = max(1, strtotime($selesai) - strtotime($mulai));
        $perMenit = round(count($r) / ($lama / 60), 1);
        $tahap[] = [
            'jenis' => 'login_paksa', 'mulai' => $mulai, 'selesai' => $selesai, 'jumlah' => count($r),
            'risiko' => 'tinggi', 'teknik' => TEKNIK['login_paksa'],
            'penjelasan' => "IP $ip mencoba login " . count($r) . ' kali ke ' . $r[0]['path'] . ' dalam ' . durasi($lama)
                          . " (sekitar $perMenit kali per menit) dan semuanya gagal. "
                          . 'Kecepatan ini tidak mungkin dilakukan manusia; penyerang memakai program untuk menebak kata sandi.',
            'rekomendasi' => 'Blokir IP ini sekarang. Batasi jumlah percobaan login per IP dan '
                           . 'pastikan akun admin memakai kata sandi kuat.',
        ];
    }

    foreach ($sukses as $s) {
        $tSukses = strtotime($s['waktu']);
        foreach ($tahap as $tp) {
            if ($tp['jenis'] !== 'login_paksa') {
                continue;
            }
            $jarak = $tSukses - strtotime($tp['selesai']);
            if ($jarak < 0 || $jarak > JENDELA_BERHASIL) {
                continue;
            }
            $q = $pdo->prepare('SELECT path FROM log_entries WHERE ip = ? AND waktu >= ? AND id > ? ORDER BY waktu, id LIMIT 1');
            $q->execute([$ip, $s['waktu'], $s['id']]);
            $lanjut = $q->fetchColumn();

            $teks = 'Pukul ' . jam($s['waktu']) . ', setelah ' . $tp['jumlah'] . ' percobaan gagal, '
                  . "IP $ip BERHASIL login ke " . $s['path'] . '.';
            if ($lanjut) {
                $teks .= " Sesudahnya penyerang langsung membuka $lanjut.";
            }
            $teks .= ' Kemungkinan besar kata sandi akun telah berhasil ditebak.';
            $tahap[] = [
                'jenis' => 'login_berhasil', 'mulai' => $s['waktu'], 'selesai' => $s['waktu'], 'jumlah' => 1,
                'risiko' => 'kritis', 'teknik' => TEKNIK['login_berhasil'], 'penjelasan' => $teks,
                'rekomendasi' => 'SEGERA ganti kata sandi akun admin, keluarkan semua sesi login aktif, '
                               . 'blokir IP ini, lalu periksa perubahan data/konten sejak waktu tersebut.',
            ];
            break;
        }
    }

    return $tahap;
}

/** Tahap tambahan jika IP yang sudah diblokir masih DITERIMA server. */
function tahapSeranganUlang(string $ip, string $waktuBlokir, array $diterima, bool $setelahBlokirUlang = false,
                            ?string $ditindaklanjuti = null): array
{
    $loginGagal    = count(array_filter($diterima, fn($e) => isLogin($e) && (int) $e['status'] === 401));
    $loginBerhasil = count(array_filter($diterima, fn($e) => isLogin($e) && in_array((int) $e['status'], [200, 302], true)));
    $tidakAda      = count(array_filter($diterima, fn($e) => (int) $e['status'] === 404));

    $rincian = [];
    if ($loginGagal)    { $rincian[] = "$loginGagal percobaan login gagal"; }
    if ($loginBerhasil) { $rincian[] = "$loginBerhasil login BERHASIL"; }
    if ($tidakAda)      { $rincian[] = "$tidakAda alamat tidak ditemukan"; }

    $teks = ($setelahBlokirUlang ? 'Sejak blokir ulang pukul ' : 'Sejak diblokir pukul ') . jam($waktuBlokir)
          . ", IP $ip masih bisa mengakses website: "
          . count($diterima) . ' permintaan diterima server'
          . ($rincian ? ' (' . implode(', ', $rincian) . ')' : '') . '. '
          . 'Artinya aturan blokir belum terpasang di server website, atau belum aktif.';
    if ($loginBerhasil) {
        $teks .= ' Penyerang kembali berhasil masuk.';
    }

    return [
        'jenis' => 'serangan_ulang', 'mulai' => $diterima[0]['waktu'], 'selesai' => end($diterima)['waktu'],
        'jumlah' => count($diterima), 'risiko' => 'kritis',
        'teknik' => $loginBerhasil ? TEKNIK['login_berhasil'] : ($loginGagal ? TEKNIK['login_paksa'] : TEKNIK['pengintaian']),
        'penjelasan' => $teks,
        'rekomendasi' => $ditindaklanjuti
            ? 'Sudah ditindaklanjuti: operator melakukan blokir ulang pukul ' . jam($ditindaklanjuti)
              . '. Pastikan permintaan berikutnya dari IP ini berstatus 403 (ditolak).'
            : 'Pasang aturan blokir dari panel Tindakan di server website, pastikan aktif (permintaan '
                       . 'dari IP ini harus berstatus 403), lalu klik "Blokir ulang". '
              . ($loginBerhasil ? 'Ganti kata sandi admin dan keluarkan semua sesi login sekarang.' : ''),
    ];
}

/**
 * Menjalankan deteksi atas seluruh log_entries dan memperbarui tabel incidents.
 * @return array<int, array{ip:string, risiko:string, status:string, ringkasan:string}>
 */
function deteksi(PDO $pdo): array
{
    $ph = implode(',', array_fill(0, count(LOGIN_PATHS), '?'));

    $blokir = [];   // ip => [waktu blokir pertama, waktu blokir ulang terakhir|null]
    foreach ($pdo->query('SELECT ip, dibuat_pada, dikonfirmasi_pada FROM blocklist') as $b) {
        $blokir[$b['ip']] = [$b['dibuat_pada'], $b['dikonfirmasi_pada']];
    }

    // Kandidat: hanya IP yang jumlah totalnya sudah mungkin memenuhi aturan (syarat perlu, bukan cukup),
    // ditambah IP yang diblokir. Ratusan pengunjung biasa tidak perlu diperiksa satu per satu.
    $q = $pdo->prepare(
        "SELECT ip FROM log_entries
         WHERE status IN (401, 403, 404)
         GROUP BY ip
         HAVING COUNT(DISTINCT CASE WHEN status IN (403, 404) AND NOT (method = 'POST' AND path IN ($ph)) THEN path END) >= ?
             OR SUM(method = 'POST' AND path IN ($ph)) >= ?"
    );
    $q->execute(array_merge(LOGIN_PATHS, [MIN_PATH_SCAN], LOGIN_PATHS, [MIN_LOGIN_GAGAL]));
    $ips = array_unique(array_merge($q->fetchAll(PDO::FETCH_COLUMN), array_keys($blokir)));

    $ambilEvent = $pdo->prepare(
        "SELECT id, waktu, method, path, status FROM log_entries
         WHERE ip = ? AND waktu <= ? AND (status IN (403, 404) OR (method = 'POST' AND path IN ($ph)))
         ORDER BY waktu, id"
    );
    $ambilSetelah = $pdo->prepare(
        'SELECT id, waktu, method, path, status FROM log_entries WHERE ip = ? AND waktu > ? ORDER BY waktu, id'
    );
    $cariInsiden = $pdo->prepare('SELECT id FROM incidents WHERE ip = ?');
    $tambahInsiden = $pdo->prepare(
        'INSERT INTO incidents (ip, mulai, selesai, risiko, status, ringkasan) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $ubahInsiden = $pdo->prepare(
        'UPDATE incidents SET mulai = ?, selesai = ?, risiko = ?, status = ?, ringkasan = ? WHERE id = ?'
    );
    $hapusTahap = $pdo->prepare('DELETE FROM incident_stages WHERE incident_id = ?');
    $simpanTahap = $pdo->prepare(
        'INSERT INTO incident_stages
           (incident_id, urutan, jenis, teknik_attack, mulai, selesai, jumlah_request, penjelasan, rekomendasi)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $hasil = [];
    $dipertahankan = [];

    foreach ($ips as $ip) {
        $batas = $blokir[$ip][0] ?? '9999-12-31 23:59:59';   // kejadian sebelum waktu blokir pertama
        $ambilEvent->execute(array_merge([$ip, $batas], LOGIN_PATHS));
        $tahap = susunTahap($pdo, $ip, $ambilEvent->fetchAll());

        $status = 'baru';
        if (isset($blokir[$ip])) {
            [$b1, $b2] = $blokir[$ip];
            $status = 'ditangani';
            $ambilSetelah->execute([$ip, $b1]);
            $diterima = array_values(array_filter($ambilSetelah->fetchAll(), fn($e) => (int) $e['status'] !== 403));

            // Periode 1: sesudah blokir pertama, sebelum blokir ulang (riwayat)
            $periode1 = $b2 ? array_values(array_filter($diterima, fn($e) => $e['waktu'] <= $b2)) : $diterima;
            if ($periode1) {
                $tahap[] = tahapSeranganUlang($ip, $b1, $periode1, false, $b2);
                if (!$b2) {
                    $status = 'serangan_ulang';
                }
            }
            // Periode 2: sesudah blokir ulang terakhir (masih aktif)
            if ($b2) {
                $periode2 = array_values(array_filter($diterima, fn($e) => $e['waktu'] > $b2));
                if ($periode2) {
                    $tahap[] = tahapSeranganUlang($ip, $b2, $periode2, true);
                    $status = 'serangan_ulang';
                }
            }
        }
        if (!$tahap) {
            continue;
        }

        usort($tahap, fn($a, $b) => strcmp($a['mulai'], $b['mulai']));
        $risiko = 'rendah';
        foreach ($tahap as $tp) {
            if (LEVEL_RISIKO[$tp['risiko']] > LEVEL_RISIKO[$risiko]) {
                $risiko = $tp['risiko'];
            }
        }
        $ringkasan = implode(' → ', array_map(fn($t) => LABEL_JENIS[$t['jenis']], $tahap));
        $mulai = $tahap[0]['mulai'];
        $selesai = max(array_column($tahap, 'selesai'));

        $pdo->beginTransaction();
        $cariInsiden->execute([$ip]);
        $id = $cariInsiden->fetchColumn();
        if ($id) {
            $ubahInsiden->execute([$mulai, $selesai, $risiko, $status, $ringkasan, $id]);
            $hapusTahap->execute([$id]);
        } else {
            $tambahInsiden->execute([$ip, $mulai, $selesai, $risiko, $status, $ringkasan]);
            $id = $pdo->lastInsertId();
        }
        foreach ($tahap as $i => $tp) {
            $simpanTahap->execute([
                $id, $i + 1, $tp['jenis'], $tp['teknik'], $tp['mulai'], $tp['selesai'],
                $tp['jumlah'], $tp['penjelasan'], $tp['rekomendasi'],
            ]);
        }
        $pdo->commit();

        $dipertahankan[] = (int) $id;
        $hasil[] = ['ip' => $ip, 'risiko' => $risiko, 'status' => $status, 'ringkasan' => $ringkasan];
    }

    // Insiden yang tidak lagi memenuhi aturan dihapus
    if ($dipertahankan) {
        $ph2 = implode(',', array_fill(0, count($dipertahankan), '?'));
        $pdo->prepare("DELETE FROM incidents WHERE id NOT IN ($ph2)")->execute($dipertahankan);
    } else {
        $pdo->exec('DELETE FROM incidents');
    }
    $pdo->exec('UPDATE blocklist b JOIN incidents i ON i.ip = b.ip SET b.incident_id = i.id');

    return $hasil;
}
