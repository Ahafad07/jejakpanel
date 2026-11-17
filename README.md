# JejakPanel

Kronologi serangan website untuk operator satuan.
Karya untuk Kartika Cyber Hackathon 2026, kategori Penangkalan.

JejakPanel membaca log akses website, mendeteksi pola serangan (pengintaian, login paksa, login berhasil),
lalu menyusunnya menjadi kronologi yang mudah dipahami operator tanpa tim SOC. Operator dapat langsung
memblokir IP penyerang, menyalin aturan blokir untuk server, dan mendapat peringatan jika IP yang
sudah diblokir ternyata masih bisa mengakses website.

## Fitur

- **Deteksi berbasis aturan** dari log Apache/Nginx (format combined): pengintaian, login paksa, login berhasil.
- **Kronologi per penyerang** dalam satu garis waktu, dengan penjelasan bahasa sederhana, kode MITRE ATT&CK, dan rekomendasi tindakan.
- **Blokir IP** dengan aturan siap salin untuk `.htaccess`, `iptables`, dan `nginx`.
- **Eskalasi serangan ulang**: jika IP yang sudah diblokir masih diterima server (bukan 403), insiden dibuka lagi sebagai kritis. Jika permintaannya ditolak (403), JejakPanel mencatat bahwa blokir bekerja.
- **Pantau langsung**: log dibaca bertahap dan dashboard memperbarui diri setiap 5 detik.
- **Blokir otomatis bertingkat**: insiden kritis dan tinggi yang sedang berlangsung langsung diblokir otomatis dan aturannya ditulis ke `.htaccess` website. Operator lalu memilih **Jadikan permanen** atau **Batalkan, tandai aman** (salah deteksi; IP tidak akan diblokir otomatis lagi).
- **Konfirmasi blokir manual** dengan peringatan sesuai tingkat risiko (misalnya pengintaian belum tentu serangan), dan konfirmasi sebelum membatalkan blokir.
- **Tabel insiden untuk kerja harian**: tab *Perlu tindakan / Diblokir / Ditandai aman / Semua* beserta jumlahnya, pencarian IP, dan **Blokir semua** dengan konfirmasi (bisa memilih hanya yang kritis dan tinggi).
- **Bukti blokir bekerja di dashboard**: angka *Ditolak server* menghitung permintaan dari IP terblokir yang ditolak (403).
- **Data contoh 24 jam yang realistis**: sekitar 13 ribu permintaan dari ±550 IP, 22 penyerang (pemindai, penebak kata sandi, satu rangkaian yang berhasil masuk), 4 blokir otomatis yang menunggu konfirmasi operator, dan satu pemindai internal yang IP-nya terdaftar aman. Mesin pencari, pemantau uptime, dan pegawai yang salah ketik kata sandi 1-3 kali sengaja dimasukkan dan **tidak** terdeteksi sebagai serangan.
- **Website uji tidak pernah diam**: dalam mode demo, lalu lintas pengunjung tiruan ditambahkan terus-menerus (pengunjung biasa, mesin pencari, pemantau uptime, pegawai, dan sesekali bot pemindai acak, rata-rata ±7 per hari). Penyerang serius sengaja tidak muncul sendiri karena di dunia nyata jarang; tombol simulasi menghadirkannya saat demo. Log pengunjung biasa yang lebih tua dari 3 hari dibersihkan otomatis.
- **Simulasi dari web**: tombol **Mode demo** di kanan atas dashboard membuka panel *Jalankan serangan tiruan* (tiga penyerang dengan risiko berbeda + satu pegawai salah ketik kata sandi), *Uji blokir gagal* (sampai tiga IP terblokir kembali, seolah `.htaccess` tertimpa), dan *Reset demo*, sehingga seluruh alur bisa dicoba tanpa terminal.

## Struktur folder

```
jejakpanel/
├── app/                     # logika (TIDAK bisa diakses dari browser)
│   ├── config.example.php   # contoh konfigurasi database
│   ├── db.php               # koneksi database
│   ├── importer.php         # pembaca log bertahap
│   ├── detektor.php         # aturan deteksi dan penyusun kronologi
│   ├── blokir.php           # blokir otomatis/manual dan penulisan .htaccess
│   ├── siklus.php           # satu siklus otomatis: simulasi, impor, deteksi, blokir
│   ├── simulasi.php         # penyerang tiruan untuk demo dari web
│   ├── contoh.php           # pembuat data contoh 24 jam terakhir (dipakai Reset demo)
│   ├── latar.php            # lalu lintas latar terus-menerus (mode demo)
│   ├── skema.php            # pembaruan struktur database otomatis
│   ├── pengaturan.php       # pengaturan bawaan (bisa ditimpa di config.php)
│   ├── import_log.php       # CLI: impor log
│   ├── detect.php           # CLI: jalankan deteksi
│   ├── watch.php            # CLI: pantau langsung (impor + deteksi tiap 3 detik)
│   ├── migrate.php          # CLI: perbarui struktur database lama
│   └── reset_demo.php       # CLI: kembalikan kondisi demo
├── database/schema.sql      # skema MySQL
├── database/contoh-data.sql # isi database hasil uji (opsional, data tiruan)
├── public/                  # satu-satunya folder yang dibuka dari browser
│   ├── index.html           # ringkasan
│   ├── insiden.html         # kronologi satu insiden
│   ├── api/                 # endpoint JSON
│   ├── assets/              # CSS, JS, font, Chart.js
│   └── .htaccess            # HTTPS dan header keamanan (hosting)
├── tools/
│   ├── generate_logs.py     # membuat log simulasi satu hari
│   └── simulate_live.py     # simulasi serangan secara langsung (demo)
├── docs/materi-presentasi.md # bahan slide dan naskah video (tidak diunggah)
├── DEPLOY.md                # panduan pasang di hosting cPanel
└── data/                    # file log (tidak masuk Git)
```

## Persiapan pertama kali

1. Impor `database/schema.sql` lewat Adminer atau phpMyAdmin.
2. Salin `app/config.example.php` menjadi `app/config.php`, lalu isi password MySQL.
3. Jika database sudah dibuat dengan versi lama: `php app/migrate.php`.

## Menjalankan

```
php -S localhost:8090 -t public
```

Buka `http://localhost:8090`. Saat pertama dibuka dalam mode demo, data contoh 24 jam terakhir dibuat otomatis
(sekitar 1 detik). Tidak perlu mengimpor log secara manual.

Untuk menganalisis file log sendiri:

```
php app/import_log.php path/ke/access.log --reset   # masukkan log ke database
php app/detect.php                                  # deteksi dan susun kronologi
```

Log yang diimpor sendiri tidak akan ditimpa data contoh, kecuali tombol **Reset demo** ditekan.

## Demo dari web (disarankan)

1. Dashboard dibuka dengan data contoh: 22 insiden, 21 perlu tindakan (termasuk 4 IP yang semalam diblokir otomatis dan menunggu konfirmasi), satu penyerang kritis yang berhasil login.
2. Klik **Blokir semua**, lalu **Hanya kritis dan tinggi**. Pemindai (risiko sedang) dibiarkan untuk ditinjau satu per satu.
3. Klik **Mode demo** → **Jalankan serangan tiruan** (sekitar 1,5 menit). Tiga penyerang muncul: dua penebak kata sandi **diblokir otomatis** sebelum berhasil masuk, pemindai tetap *Belum ditangani*, dan pegawai yang salah ketik kata sandi tidak muncul sebagai insiden.
4. Buka insiden yang diblokir otomatis, klik **Jadikan permanen** (atau **Batalkan, tandai aman** jika salah deteksi).
5. Klik **Uji blokir gagal**: tiga IP terblokir terakhir kembali dan seolah diterima server. Muncul peringatan "Masih menyerang".
6. Kembali ke tab **Perlu tindakan**, klik **Blokir semua** untuk blokir ulang. Permintaan berikutnya ditolak (403).
7. Klik **Reset demo** untuk memuat ulang data contoh.

Di hosting, siklus otomatis berjalan setiap kali dashboard dibuka (lewat `api/tick.php`), sehingga tidak perlu terminal.

## Demo pantau langsung lewat terminal

Butuh tiga terminal:

```
# Terminal 1 - dashboard
php -S localhost:8090 -t public

# Terminal 2 - pemantau log
php app/reset_demo.php --semua
php app/watch.php

# Terminal 3 - simulasi serangan
python tools/simulate_live.py                    # serangan baru (sekitar 1 menit)
```

Alur demo yang disarankan:

1. Jalankan `simulate_live.py`. Dashboard berubah sendiri: insiden muncul, tahap bertambah, bilah status menjadi merah.
2. Buka insiden, klik **Blokir**.
3. Jalankan `python tools/simulate_live.py --ulang`. Penyerang kembali dan **diterima** server: muncul peringatan "Masih menyerang".
4. Klik **Blokir ulang**.
5. Jalankan `python tools/simulate_live.py --ulang --ditolak`. Permintaan **ditolak** server (403): kronologi mencatat "blokir bekerja".

Tambahkan `--cepat` untuk kecepatan 2x.

## Mengulang demo

- Tombol **Reset demo** di dashboard, atau `php app/reset_demo.php`, membuat ulang data contoh 24 jam terakhir dan menghapus hasil simulasi serta tindakan operator.
- `php app/reset_demo.php --semua` mengosongkan seluruh data (untuk demo pantau langsung dari nol).

## Etika dan batasan

- Semua serangan disimulasikan dengan menulis file log tiruan. Tidak ada request yang dikirim ke server mana pun.
- IP penyerang memakai blok alamat dokumentasi RFC 5737, sehingga bukan milik pihak mana pun.
- Deteksi berbasis aturan ambang batas (threshold), bukan pembelajaran mesin. Penyerang yang sangat lambat bisa lolos dari ambang batas.
- Satu insiden per IP. Serangan dari banyak IP sekaligus (terdistribusi) belum digabungkan.
- Di hosting bersama, log akses asli biasanya tidak tersedia secara langsung (diperbarui berkala oleh hosting), sehingga demo online memakai log contoh dan simulasi. Pada server sendiri, `watch.php` atau cron dapat diarahkan ke log Apache/Nginx sungguhan.
- Blokir otomatis hanya untuk insiden kritis dan tinggi yang aktivitasnya terjadi dalam 30 menit terakhir; pengintaian (sedang) dan insiden lama menunggu keputusan operator. Pengaturan ada di `app/pengaturan.php` dan bisa ditimpa di `config.php` (`blokir_otomatis`, `risiko_otomatis`, `jendela_otomatis_menit`, `ip_aman`, `terapkan_htaccess`, `mode_demo`).
- Mode demo: tombol Blokir dan simulasi bisa dipakai siapa pun yang membuka situs, tetapi hanya untuk IP yang tercatat sebagai insiden dan wajib dikirim sebagai JSON. Pada pemakaian sungguhan, tombol ini harus dilindungi login operator dan `mode_demo` dimatikan.
- Aturan blokir ditulis otomatis hanya ke bagian khusus `.htaccess` (antara penanda BEGIN/END JejakPanel), secara atomik dan dengan cadangan `.htaccess.jejakpanel.bak`. Untuk server lain (iptables, nginx) aturan tersedia siap salin.

## Pustaka pihak ketiga

- [Chart.js](https://www.chartjs.org) v4.4.4 (lisensi MIT) untuk grafik trafik, disimpan lokal di `public/assets/vendor/`.
- Font IBM Plex Sans dan IBM Plex Mono (SIL Open Font License), disimpan lokal di `public/assets/fonts/`.
- Gaya tampilan mengacu pada IBM Carbon Design System (tema g100).
- Kode teknik serangan mengacu pada [MITRE ATT&CK](https://attack.mitre.org).

## Riwayat perubahan

- **Versi 7**: dashboard langsung tampil tanpa menunggu siklus; lalu lintas latar yang tertinggal disusul bertahap (maksimal 2 jam per siklus); peringatan "Koneksi ke server terputus" baru muncul setelah 3 kali gagal berturut-turut; permintaan pembaruan tidak lagi menumpuk.
- **Versi 6**: data contoh 24 jam dimuat otomatis, lalu lintas latar terus-menerus, simulasi dengan beberapa penyerang, Blokir semua.

