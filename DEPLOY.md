# Panduan Memasang JejakPanel di Hosting (cPanel)

Panduan ini untuk hosting bersama (shared hosting) dengan cPanel, contohnya paket hosting ArenHost,
dengan domain `jejakpanel.my.id`. Hosting tidak perlu terminal: semua skrip dijalankan di laptop,
lalu hasilnya (database dan file) diunggah.

Perkiraan waktu: 45 - 60 menit.

---

## Gambaran susunan folder di hosting

```
/home/NAMAAKUN/
├── app/            <- isi folder app/ (DI LUAR public_html, tidak bisa dibuka dari internet)
│   ├── config.php  <- dibuat langsung di hosting, berisi password database hosting
│   ├── db.php
│   ├── detektor.php
│   └── ...
└── public_html/    <- isi folder public/ (yang bisa dibuka pengunjung)
    ├── .htaccess
    ├── index.html
    ├── insiden.html
    ├── api/
    └── assets/
```

Kode di `public/api/` mencari `app/` dua tingkat di atasnya, jadi susunan ini bekerja tanpa mengubah kode.

**Jangan pernah mengunggah:** `adminer.php`, folder `data/`, folder `tools/`, folder `.git/`, dan `app/config.php` dari laptop.

---

## Langkah 1 - Siapkan database (2 menit)

Sejak versi 6 data tidak perlu disiapkan di laptop. Untuk pemasangan baru cukup siapkan file
`database/schema.sql` untuk diimpor di Langkah 5 (pengganti `jejakpanel.sql`; Langkah 2 boleh dilewati). Hapus dulu baris `CREATE DATABASE` dan `USE` di file itu, karena di cPanel database dibuat lewat Database Wizard.
Data contoh 24 jam terakhir dibuat otomatis saat dashboard pertama kali dibuka.

## Langkah 2 - Ekspor database dari Adminer (5 menit)

1. Buka Adminer, login, pilih database `jejakpanel`.
2. Klik **Export**.
3. Atur pilihan berikut:
   - Output: **save**
   - Format: **SQL**
   - Database: **kosongkan** (jangan pilih CREATE atau USE, karena nama database di hosting berbeda)
   - Tables: **DROP+CREATE**
   - Data: **INSERT**
4. Klik **Export**. File `jejakpanel.sql` akan terunduh.

## Langkah 3 - Siapkan file untuk diunggah (5 menit)

Di PowerShell, dari folder `jejakpanel`:

```powershell
Compress-Archive -Path public\* -DestinationPath "$HOME\Desktop\unggah-public.zip" -Force
Get-ChildItem app -Exclude config.php | Compress-Archive -DestinationPath "$HOME\Desktop\unggah-app.zip" -Force
```

Dua file zip akan muncul di Desktop. `config.php` laptop sengaja tidak ikut.

## Langkah 4 - Atur versi PHP di cPanel (3 menit)

1. Login ke cPanel.
2. Buka **Select PHP Version** (atau **MultiPHP Manager**).
3. Pilih **PHP 8.1 atau lebih baru** (8.2 / 8.3 lebih baik).
4. Di tab **Extensions**, pastikan **pdo_mysql** dan **mbstring** tercentang.

## Langkah 5 - Buat database dan pengguna (5 menit)

1. Buka **MySQL Databases** (atau **Manage My Databases**).
2. **Create New Database**: isi `jejakpanel`. Nama lengkapnya akan menjadi seperti `NAMAAKUN_jejakpanel`. **Catat.**
3. **Add New User**: isi misalnya `jpweb`, lalu klik **Password Generator** untuk password kuat. **Catat nama lengkap user (`NAMAAKUN_jpweb`) dan password-nya.**
4. **Add User To Database**: pilih user dan database tadi, centang **ALL PRIVILEGES**, simpan.

## Langkah 6 - Impor database (5 menit)

1. Buka **phpMyAdmin** dari cPanel.
2. Klik database `NAMAAKUN_jejakpanel` di kiri.
3. Klik tab **Import**, pilih file `jejakpanel.sql` dari Langkah 2, klik **Import** / **Go**.
4. Pastikan muncul 5 tabel: `blocklist`, `import_state`, `incident_stages`, `incidents`, `log_entries`.

## Langkah 7 - Unggah file (10 menit)

1. Buka **File Manager**. Di **Settings** (kanan atas), centang **Show Hidden Files (dotfiles)**.
2. **Folder app:**
   - Masuk ke folder rumah (`/home/NAMAAKUN`, satu tingkat di atas `public_html`).
   - Klik **+ Folder**, buat folder `app`.
   - Masuk ke `app`, klik **Upload**, unggah `unggah-app.zip`.
   - Klik kanan zip, pilih **Extract**, lalu hapus file zip-nya.
3. **Folder public_html:**
   - Masuk ke `public_html`. Hapus file bawaan hosting jika ada (misalnya `default.html` atau `index.html` lama).
   - Unggah `unggah-public.zip`, **Extract**, lalu hapus file zip-nya.
   - Pastikan `.htaccess`, `index.html`, `insiden.html`, folder `api` dan `assets` ada langsung di dalam `public_html` (bukan di subfolder `public`).

## Langkah 8 - Buat config.php di hosting (3 menit)

1. Di File Manager, masuk ke `/home/NAMAAKUN/app`.
2. Klik kanan `config.example.php`, pilih **Copy**, beri nama `config.php`.
3. Klik kanan `config.php`, pilih **Edit**, lalu isi:

```php
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'NAMAAKUN_jejakpanel',
    'db_user' => 'NAMAAKUN_jpweb',
    'db_pass' => 'PASSWORD_DARI_LANGKAH_5',
];
```

4. Simpan.

## Langkah 9 - Uji (5 menit)

| Uji | Hasil yang benar |
|---|---|
| Buka `https://jejakpanel.my.id` | Dashboard tampil dengan 22 insiden (21 perlu tindakan) |
| Buka `http://jejakpanel.my.id` (tanpa s) | Otomatis pindah ke `https://` |
| Buka `https://jejakpanel.my.id/api/summary.php` | Muncul teks JSON |
| Buka `https://jejakpanel.my.id/.htaccess` | Ditolak (403 Forbidden) |
| Buka `https://jejakpanel.my.id/assets/` | Ditolak (403), daftar file tidak tampil |
| Klik **Mode demo**, lalu **Jalankan serangan tiruan**, tunggu sekitar 1,5 menit | Tiga insiden baru: dua "Diblokir otomatis", satu pemindai "Belum ditangani" |
| Klik **Blokir semua** di tab Perlu tindakan, lalu **Hanya kritis dan tinggi** | Muncul "Selesai: ... diblokir", angka Perlu tindakan turun |
| Buka file `.htaccess` di File Manager | Ada bagian `# BEGIN JejakPanel blokir` berisi IP penyerang tiruan |
| Klik **Reset demo** | Kembali ke 22 insiden; bagian blokir di `.htaccess` berisi 4 IP contoh |
| Buka di HP | Tampilan tersusun rapi ke bawah |

## Mengembalikan kondisi demo di hosting

Klik **Mode demo**, lalu **Reset demo** di dashboard. Data contoh 24 jam terakhir dibuat ulang (berakhir beberapa menit sebelum tombol ditekan); hasil simulasi dan tindakan operator dihapus.

## Memperbarui ke versi baru

1. Gunakan `unggah-app.zip` dan `unggah-public.zip` yang disertakan bersama rilis, atau buat ulang seperti Langkah 3.
2. Di File Manager, unggah `unggah-app.zip` ke `/home/NAMAAKUN/app` dan `unggah-public.zip` ke `public_html`,
   lalu **Extract** dan timpa file lama. `config.php` di hosting tidak ikut tertimpa karena tidak ada di zip.
3. Hapus kedua zip.
4. Buka dashboard. Struktur database diperbarui otomatis saat halaman pertama kali dibuka,
   dan folder `/home/NAMAAKUN/data` (status siklus dan log simulasi) dibuat otomatis.
   Sejak versi 6, data contoh 24 jam dimuat otomatis sekali saat dashboard pertama dibuka; tidak perlu impor database lagi.
5. Tekan **Ctrl + F5** agar browser memakai CSS/JS terbaru.

---

## Jika ada masalah

| Gejala | Penyebab paling mungkin | Solusi |
|---|---|---|
| Kotak status menampilkan "Terjadi kesalahan pada server." | `config.php` salah atau tidak ada | Periksa Langkah 8: nama database/user harus lengkap dengan awalan `NAMAAKUN_` |
| Halaman putih / error 500 | Versi PHP terlalu lama | Langkah 4: pilih PHP 8.1+ |
| Grafik tidak muncul | `assets/vendor/chart.umd.js` tidak terunggah | Ulangi ekstrak `unggah-public.zip` |
| Tampilan berantakan tanpa font | Folder `assets/fonts` tidak terunggah | Ulangi ekstrak `unggah-public.zip` |
| Error 500 setelah `.htaccess` diunggah | Server tidak mendukung salah satu perintah | Hapus sementara bagian `Header always set Content-Security-Policy`, lalu tanyakan ke admin hosting |

Pesan error lengkap PHP bisa dilihat di cPanel, menu **Errors** atau file `error_log` di `public_html`.

## Catatan keamanan

- `app/` berada di luar `public_html`, sehingga `config.php` tidak bisa dibuka dari browser.
- `.htaccess` memaksa HTTPS, menolak file tersembunyi dan cadangan, menonaktifkan daftar isi folder,
  dan memasang header keamanan (CSP, X-Frame-Options, nosniff).
- User database hosting hanya punya akses ke satu database JejakPanel.
- Tombol Blokir dan simulasi pada versi online adalah mode demo: hanya bisa memproses IP yang tercatat sebagai insiden,
  dan hanya menerima permintaan JSON dari situs ini. Pada pemakaian sungguhan, tombol ini harus dilindungi login operator
  dan `mode_demo` dimatikan di `config.php`.
- JejakPanel hanya mengubah bagian khusus `.htaccess` di antara penanda `# BEGIN JejakPanel blokir` dan `# END JejakPanel blokir`,
  dan menyimpan cadangan `.htaccess.jejakpanel.bak` (tidak bisa dibuka dari internet).
