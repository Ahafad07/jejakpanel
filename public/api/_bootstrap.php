<?php
/**
 * Bagian bersama untuk semua endpoint API: koneksi database dan respons JSON.
 * Folder app/ (berisi config.php) berada di luar folder public/,
 * sehingga tidak bisa dibuka langsung dari browser.
 */
require __DIR__ . '/../../app/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function kirim($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log('[JejakPanel] ' . $e->getMessage());
    // Detail error tidak dikirim ke browser agar informasi server tidak bocor
    kirim(['error' => 'Terjadi kesalahan pada server.'], 500);
});

// Pastikan struktur database sudah versi terbaru (sekali saja, dicatat di data/siklus.json)
require_once __DIR__ . '/../../app/pengaturan.php';
require_once __DIR__ . '/../../app/skema.php';
(function () {
    $f = folderData() . '/siklus.json';
    $s = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    if (($s['skema'] ?? 0) < VERSI_SKEMA) {
        $pdo = db();
        migrasi($pdo);
        $s['skema'] = VERSI_SKEMA;
        $s['id_dasar'] ??= (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM log_entries')->fetchColumn();
        $s['perlu_deteksi'] = true;
        file_put_contents($f, json_encode($s), LOCK_EX);
    }
})();
