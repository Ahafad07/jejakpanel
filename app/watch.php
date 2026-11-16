<?php
/**
 * JejakPanel - mode pantau langsung lewat terminal.
 * Menjalankan siklus otomatis (baca log baru, deteksi, blokir otomatis) setiap 3 detik.
 * Di hosting, siklus yang sama berjalan otomatis setiap kali dashboard dibuka.
 *
 * Pemakaian (dari folder jejakpanel):
 *     php app/watch.php        -> pantau data/live.log
 * Hentikan dengan Ctrl + C.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/siklus.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk dijalankan lewat terminal.\n");
}

echo 'Memantau ' . fileLogLangsung() . " (Ctrl + C untuk berhenti)\n";
$pdo = db();
$terakhir = [];
$menunggu = false;

while (true) {
    if (!file_exists(fileLogLangsung()) && !$menunggu) {
        echo date('H.i.s') . "  menunggu log baru (jalankan simulasi dari dashboard atau tools/simulate_live.py)...\n";
        $menunggu = true;
    }
    $h = jalankanSiklus($pdo, true);
    if ($h['baris_baru'] > 0) {
        $menunggu = false;
        echo date('H.i.s') . "  +{$h['baris_baru']} baris log\n";
        foreach ($pdo->query('SELECT ip, status, ringkasan FROM incidents') as $i) {
            $isi = $i['status'] . '|' . $i['ringkasan'];
            if (($terakhir[$i['ip']] ?? null) !== $isi) {
                $tanda = $i['status'] === 'serangan_ulang' ? '  << MASIH MENYERANG SETELAH DIBLOKIR' : '';
                echo "          > {$i['ip']}: {$i['ringkasan']}$tanda\n";
                $terakhir[$i['ip']] = $isi;
            }
        }
    }
    foreach ($h['diblokir_otomatis'] as $ip) {
        echo date('H.i.s') . "  !! $ip DIBLOKIR OTOMATIS (insiden kritis, menunggu konfirmasi operator)\n";
    }
    sleep(3);
}
