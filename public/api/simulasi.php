<?php
/**
 * POST api/simulasi.php   body JSON:
 *   {"aksi": "mulai", "jenis": "serangan"}      -> simulasi penyerang baru
 *   {"aksi": "mulai", "jenis": "blokir_gagal"}  -> IP yang sudah diblokir kembali, aturan dianggap tidak berlaku
 *   {"aksi": "reset"}                           -> kembalikan kondisi demo
 *
 * Hanya aktif jika mode_demo = true. Simulasi hanya menulis log tiruan, tidak mengirim request ke mana pun.
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
if (!atur('mode_demo')) {
    kirim(['error' => 'Mode demo tidak aktif.'], 403);
}

$body = json_decode(file_get_contents('php://input', false, null, 0, 1024), true) ?: [];
$aksi = $body['aksi'] ?? '';
$pdo  = db();

try {
    if ($aksi === 'mulai') {
        $jenis = ($body['jenis'] ?? '') === 'blokir_gagal' ? 'blokir_gagal' : 'serangan';
        jalankanSiklus($pdo, true);   // pastikan skema & data terbaru sebelum mulai
        kirim(['ok' => true, 'simulasi' => mulaiSimulasi($pdo, $jenis)]);
    }
    if ($aksi === 'reset') {
        $sim = statusSimulasi();
        if ($sim && $sim['berjalan']) {
            kirim(['error' => 'Tunggu simulasi selesai sebelum mereset.'], 409);
        }
        resetDemo($pdo);
        kirim(['ok' => true]);
    }
} catch (RuntimeException $e) {
    kirim(['error' => $e->getMessage()], 409);
}

kirim(['error' => 'Aksi tidak dikenal.'], 400);
