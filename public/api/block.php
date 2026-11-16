<?php
/**
 * POST api/block.php   body JSON: {"incident_id": 3, "aksi": "blokir" | "permanen" | "batal" | "hapus_aman"}
 *                      atau       {"aksi": "massal", "incident_ids": [3, 5, 8]}
 *
 *  - blokir     : blokir manual (atau "Blokir ulang" jika IP sudah ada di daftar blokir)
 *  - permanen   : konfirmasi blokir otomatis menjadi permanen
 *  - batal      : batalkan blokir dan tandai IP aman (salah deteksi, tidak diblokir otomatis lagi)
 *  - hapus_aman : hapus tanda aman
 *  - massal     : tangani sekaligus insiden yang dipilih dan masih perlu tindakan (lihat tindakanMassal)
 *
 * Batasan keamanan mode demo:
 *  - Hanya IP yang tercatat di tabel incidents yang bisa diproses; tidak ada input IP bebas.
 *  - Wajib Content-Type: application/json, sehingga form dari situs lain tidak bisa
 *    mengirim permintaan ini (mencegah CSRF sederhana).
 *  - Pada pemakaian sungguhan, endpoint ini harus dilindungi login operator.
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../app/siklus.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    kirim(['error' => 'Gunakan metode POST.'], 405);
}
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    kirim(['error' => 'Content-Type harus application/json.'], 415);
}

$body = json_decode(file_get_contents('php://input', false, null, 0, 8192), true);
$body = is_array($body) ? $body : [];
$aksi = $body['aksi'] ?? 'blokir';

if ($aksi === 'massal') {
    $daftar = $body['incident_ids'] ?? null;
    if (!is_array($daftar) || !$daftar || !array_is_list($daftar) || count($daftar) > 500) {
        kirim(['error' => 'Daftar insiden tidak valid.'], 400);
    }
    $ids = [];
    foreach ($daftar as $v) {
        $v = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$v) {
            kirim(['error' => 'Daftar insiden tidak valid.'], 400);
        }
        $ids[$v] = $v;
    }
    $pdo = db();
    $pdo->beginTransaction();
    $hasil = tindakanMassal($pdo, array_values($ids));
    $pdo->commit();
    deteksi($pdo);
    terapkanBlokir($pdo);
    kirim(['ok' => true, 'aksi' => 'massal', 'hasil' => $hasil]);
}

$id = filter_var($body['incident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$id || !in_array($aksi, ['blokir', 'permanen', 'batal', 'hapus_aman'], true)) {
    kirim(['error' => 'Permintaan tidak valid.'], 400);
}

$pdo  = db();
$stmt = $pdo->prepare('SELECT id, ip, risiko, ringkasan FROM incidents WHERE id = ?');
$stmt->execute([$id]);
$insiden = $stmt->fetch();
if (!$insiden) {
    kirim(['error' => 'Insiden tidak ditemukan.'], 404);
}

$pdo->beginTransaction();
tindakanOperator($pdo, $insiden, $aksi);
$pdo->commit();

deteksi($pdo);          // susun ulang kronologi & status insiden
terapkanBlokir($pdo);   // tulis ulang aturan di .htaccess

kirim(['ok' => true, 'ip' => $insiden['ip'], 'aksi' => $aksi]);
