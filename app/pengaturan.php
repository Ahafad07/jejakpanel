<?php
/**
 * Pengaturan JejakPanel dengan nilai bawaan.
 * Nilai bisa ditimpa lewat kunci yang sama di app/config.php.
 */

const PENGATURAN_BAWAAN = [
    // Blokir otomatis insiden yang masih berlangsung (perlu konfirmasi operator)
    'blokir_otomatis'          => true,
    // Tingkat risiko yang diblokir otomatis. Pengintaian (sedang) sengaja tidak termasuk.
    'risiko_otomatis'          => ['kritis', 'tinggi'],
    // Hanya insiden dengan aktivitas dalam N menit terakhir yang diblokir otomatis
    'jendela_otomatis_menit'   => 30,
    // Tulis aturan blokir ke file .htaccess website yang dilindungi
    'terapkan_htaccess'        => true,
    // Lokasi .htaccess yang ditulisi. null = otomatis: ../public_html/.htaccess jika ada
    'htaccess'                 => null,
    // IP yang tidak boleh diblokir otomatis (misal IP kantor / admin)
    'ip_aman'                  => [],
    // Mode demo: tambahkan lalu lintas pengunjung tiruan terus-menerus agar website uji tidak pernah diam
    'lalu_lintas_latar'        => true,
    // Nama website yang dipantau, tampil di header. null = memakai nama domain saat ini
    'nama_situs'               => null,
    // Keterangan di belakang nama website, misalnya "(uji)" untuk website percobaan
    'keterangan_situs'         => '(uji)',
    // Tampilkan tombol simulasi serangan di dashboard (untuk demo)
    'mode_demo'                => true,
];

function atur(string $kunci)
{
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . '/config.php';
        $config = file_exists($file) ? (require $file) : [];
    }
    return $config[$kunci] ?? PENGATURAN_BAWAAN[$kunci] ?? null;
}

/** Folder data di luar folder publik (log simulasi, kunci, status siklus). */
function folderData(): string
{
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

function fileHtaccess(): ?string
{
    $diatur = atur('htaccess');
    if ($diatur) {
        return $diatur;
    }
    $hosting = __DIR__ . '/../public_html/.htaccess';
    return file_exists($hosting) ? $hosting : null;
}

/** true jika JejakPanel bisa menulis aturan blokir ke .htaccess. */
function bisaTulisHtaccess(): bool
{
    $f = fileHtaccess();
    return atur('terapkan_htaccess') && $f !== null && is_writable($f);
}
