<?php
/**
 * Satu siklus kerja otomatis JejakPanel:
 *   1. perbarui struktur database jika perlu,
 *   2. majukan simulasi (jika ada),
 *   3. baca baris log baru,
 *   4. jalankan deteksi,
 *   5. blokir otomatis insiden kritis yang masih berlangsung,
 *   6. tulis aturan blokir ke .htaccess.
 *
 * Dipanggil oleh api/tick.php (setiap kali dashboard memperbarui diri) dan oleh watch.php.
 * Hanya satu siklus yang berjalan pada satu waktu (dikunci dengan flock).
 */
require_once __DIR__ . '/pengaturan.php';
require_once __DIR__ . '/skema.php';
require_once __DIR__ . '/importer.php';
require_once __DIR__ . '/detektor.php';
require_once __DIR__ . '/blokir.php';
require_once __DIR__ . '/simulasi.php';
require_once __DIR__ . '/contoh.php';
require_once __DIR__ . '/latar.php';

// Naikkan angka ini jika isi data contoh berubah: data contoh dimuat ulang otomatis sekali (hanya mode demo)
const VERSI_CONTOH = 3;

const JEDA_MIN_SIKLUS = 2.5; // detik

function bacaStatusSiklus(): array
{
    $f = folderData() . '/siklus.json';
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}

function simpanStatusSiklus(array $s): void
{
    file_put_contents(folderData() . '/siklus.json', json_encode($s), LOCK_EX);
}

/**
 * @return array{dijalankan:bool, baris_baru:int, diblokir_otomatis:string[]}
 */
function jalankanSiklus(PDO $pdo, bool $paksa = false): array
{
    $hasil = ['dijalankan' => false, 'baris_baru' => 0, 'diblokir_otomatis' => []];

    $kunci = fopen(folderData() . '/siklus.lock', 'c');
    if (!flock($kunci, LOCK_EX | LOCK_NB)) {
        return $hasil;   // siklus lain sedang berjalan
    }
    try {
        $s = bacaStatusSiklus();
        if (!$paksa && microtime(true) - ($s['terakhir'] ?? 0) < JEDA_MIN_SIKLUS) {
            return $hasil;
        }

        if (($s['skema'] ?? 0) < VERSI_SKEMA) {
            migrasi($pdo);
            $s['skema'] = VERSI_SKEMA;
            $s['id_dasar'] ??= (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM log_entries')->fetchColumn();
            $s['perlu_deteksi'] = true;
        }

        if (atur('mode_demo') && ($s['contoh'] ?? 0) < VERSI_CONTOH) {
            muatContohKeStatus($pdo, $s);
        }

        if (atur('mode_demo') && atur('lalu_lintas_latar')) {
            majukanLatar($pdo, $s);
        }
        $barisSimulasi = majukanSimulasi($pdo);
        $log = fileLogLangsung();
        $mencurigakan = 0;
        if (file_exists($log)) {
            $impor = imporLog($pdo, $log);
            $hasil['baris_baru'] = $impor['baru'];
            $mencurigakan = $impor['mencurigakan'];
            kecilkanLogLangsung($pdo, $log);
        }

        // Deteksi hanya perlu diulang jika ada baris yang bisa mengubah hasilnya
        // (kunjungan biasa berstatus 200 tidak mengubah insiden apa pun)
        if ($mencurigakan > 0 || $barisSimulasi > 0 || !empty($s['perlu_deteksi'])) {
            deteksi($pdo);
            $hasil['diblokir_otomatis'] = blokirOtomatis($pdo);
            if ($hasil['diblokir_otomatis']) {
                deteksi($pdo);   // perbarui status insiden yang baru diblokir
            }
            $s['perlu_deteksi'] = false;
        }
        rawatData($pdo, $s);

        // Selalu disinkronkan: jika .htaccess sempat tertimpa (misal diunggah ulang), bagian blokir ditulis lagi
        terapkanBlokir($pdo);

        $s['terakhir'] = microtime(true);
        simpanStatusSiklus($s);
        $hasil['dijalankan'] = true;
        return $hasil;
    } finally {
        flock($kunci, LOCK_UN);
        fclose($kunci);
    }
}

/** File log pantau langsung yang sudah terbaca semua dan cukup besar dikosongkan, agar tidak terus membesar. */
function kecilkanLogLangsung(PDO $pdo, string $log): void
{
    clearstatcache(true, $log);
    $ukuran = filesize($log);
    if ($ukuran < 512 * 1024) {
        return;
    }
    $q = $pdo->prepare('SELECT posisi FROM import_state WHERE file = ?');
    $q->execute([realpath($log) ?: $log]);
    if ((int) $q->fetchColumn() === $ukuran) {
        file_put_contents($log, '', LOCK_EX);
        $pdo->prepare('UPDATE import_state SET posisi = 0 WHERE file = ?')->execute([realpath($log) ?: $log]);
    }
}

/** Menandai bahwa deteksi perlu dijalankan ulang pada siklus berikutnya. */
function mintaDeteksiUlang(): void
{
    $s = bacaStatusSiklus();
    $s['perlu_deteksi'] = true;
    $s['terakhir'] = 0;
    simpanStatusSiklus($s);
}

/** Memuat data contoh 24 jam terakhir dan mencatatnya di status siklus. */
function muatContohKeStatus(PDO $pdo, array &$s): void
{
    @unlink(fileLogLangsung());
    simpanSimulasi(null);
    muatDataContoh($pdo);
    deteksi($pdo);
    terapkanBlokir($pdo);
    $s['contoh'] = VERSI_CONTOH;
    $s['latar_sampai'] = time() - 120;   // lalu lintas latar melanjutkan dari akhir data contoh
    $s['id_dasar'] = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM log_entries')->fetchColumn();
    $s['perlu_deteksi'] = false;
}

/**
 * Mengembalikan kondisi demo: log 24 jam terakhir dibuat ulang (berakhir beberapa menit lalu),
 * riwayat blokir contoh dipulihkan, dan semua hasil simulasi serta tindakan operator dihapus.
 */
function resetDemo(PDO $pdo): void
{
    $kunci = fopen(folderData() . '/siklus.lock', 'c');
    flock($kunci, LOCK_EX);   // tunggu siklus yang sedang berjalan selesai
    try {
        $s = bacaStatusSiklus();
        if (($s['skema'] ?? 0) < VERSI_SKEMA) {
            migrasi($pdo);
            $s['skema'] = VERSI_SKEMA;
        }
        muatContohKeStatus($pdo, $s);
        $s['terakhir'] = microtime(true);
        simpanStatusSiklus($s);
    } finally {
        flock($kunci, LOCK_UN);
        fclose($kunci);
    }
}
