<?php
/**
 * Fungsi impor log akses (format Apache/Nginx "combined") ke tabel log_entries.
 *
 * Impor bersifat bertahap: posisi baca terakhir disimpan di tabel import_state,
 * sehingga pemanggilan berikutnya hanya membaca baris yang baru ditambahkan.
 */

const POLA_LOG = '/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+) [^"]*" (\d{3}) (\d+|-) "[^"]*" "([^"]*)"/';

/**
 * @return array{baru:int, dilewati:int, mencurigakan:int}
 *   mencurigakan = baris yang bisa mengubah hasil deteksi (401/403/404 atau POST ke halaman login)
 */
function imporLog(PDO $pdo, string $path, bool $reset = false): array
{
    $kunci = realpath($path) ?: $path;

    if ($reset) {
        $pdo->exec('DELETE FROM log_entries');
        $pdo->exec('ALTER TABLE log_entries AUTO_INCREMENT = 1');
        $pdo->exec('DELETE FROM import_state');
    }

    $stmt = $pdo->prepare('SELECT posisi FROM import_state WHERE file = ?');
    $stmt->execute([$kunci]);
    $posisi = (int) ($stmt->fetchColumn() ?: 0);

    clearstatcache(true, $path);
    if (filesize($path) < $posisi) {
        $posisi = 0;   // file dibuat ulang / dipotong -> baca dari awal
    }

    $simpan = $pdo->prepare(
        'INSERT INTO log_entries (ip, waktu, method, path, status, bytes, user_agent, sumber)
         VALUES (:ip, :waktu, :method, :path, :status, :bytes, :ua, :sumber)'
    );
    $wib = new DateTimeZone('Asia/Jakarta');
    $sumber = basename($path) === 'live.log' ? 'live' : 'impor';

    $baru = 0;
    $mencurigakan = 0;
    $dilewati = 0;
    $h = fopen($path, 'r');
    fseek($h, $posisi);
    $pdo->beginTransaction();

    while (true) {
        $awal = ftell($h);
        $raw = fgets($h);
        if ($raw === false) {
            break;
        }
        if (!str_ends_with($raw, "\n")) {
            $posisi = $awal;   // baris belum selesai ditulis; baca lagi di putaran berikutnya
            break;
        }
        $posisi = ftell($h);

        $raw = trim($raw);
        if ($raw === '' || !preg_match(POLA_LOG, $raw, $m)) {
            $dilewati++;
            continue;
        }
        $t = DateTime::createFromFormat('d/M/Y:H:i:s O', $m[2]);
        if ($t === false) {
            $dilewati++;
            continue;
        }
        $t->setTimezone($wib);

        $simpan->execute([
            ':ip'     => $m[1],
            ':waktu'  => $t->format('Y-m-d H:i:s'),
            ':method' => $m[3],
            ':path'   => mb_substr($m[4], 0, 500),
            ':status' => (int) $m[5],
            ':bytes'  => $m[6] === '-' ? 0 : (int) $m[6],
            ':ua'     => mb_substr($m[7], 0, 300),
            ':sumber' => $sumber,
        ]);
        $baru++;
        if (in_array((int) $m[5], [401, 403, 404], true) || $m[3] === 'POST') {
            $mencurigakan++;
        }
        if ($baru % 1000 === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
        }
    }
    fclose($h);

    $pdo->prepare(
        'INSERT INTO import_state (file, posisi) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE posisi = VALUES(posisi)'
    )->execute([$kunci, $posisi]);
    $pdo->commit();

    return ['baru' => $baru, 'dilewati' => $dilewati, 'mencurigakan' => $mencurigakan];
}
