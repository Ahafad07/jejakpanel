# Materi Presentasi JejakPanel

Bahan untuk slide dan naskah video. File ini tidak diunggah ke hosting.

## Masalah

Website satuan sering menjadi sasaran pemindaian dan percobaan login paksa, tetapi tanda-tandanya
tersembunyi di ribuan baris log server. Tidak setiap satuan memiliki analis keamanan (tim SOC).

## Solusi (satu kalimat)

JejakPanel mendeteksi serangan, menceritakannya dalam bahasa yang dipahami operator, memblokirnya
otomatis, lalu membuktikan blokirnya bekerja.

## Tiga fitur unggulan

1. **Kronologi yang bisa dibaca.** Log mentah disusun menjadi tahap serangan berurutan per penyerang:
   pengintaian, login paksa, login berhasil. Setiap tahap dijelaskan dalam bahasa sederhana, diberi kode
   MITRE ATT&CK, dan disertai langkah yang harus dilakukan.
2. **Blokir otomatis bertingkat.** Serangan berisiko tinggi atau kritis yang sedang berlangsung langsung
   diblokir, dan aturannya ditulis ke .htaccess server. Keputusan akhir tetap di tangan operator:
   jadikan permanen, atau batalkan dan tandai aman jika salah deteksi.
3. **Blokir yang terbukti.** JejakPanel memeriksa permintaan berikutnya dari IP yang diblokir. Jika ditolak
   server (403), blokir terbukti bekerja. Jika masih diterima, insiden dibuka lagi dengan peringatan
   "Masih menyerang".

## Alur kerja (berjalan otomatis setiap beberapa detik)

1. **Membaca log** - log akses format standar Apache/Nginx dibaca bertahap; hanya baris baru yang diproses.
2. **Mendeteksi pola** - aturan ambang batas yang transparan: >= 10 alamat tidak ditemukan (pengintaian),
   >= 20 login gagal (login paksa), login berhasil sesudahnya.
3. **Menyusun kronologi** - kejadian dikelompokkan per IP menjadi tahap berurutan beserta jeda, tingkat risiko,
   dan penjelasannya.
4. **Merespons** - blokir otomatis untuk serangan tinggi/kritis yang sedang berlangsung, atau blokir manual
   (dengan konfirmasi) oleh operator. Aturan ditulis ke .htaccess; versi iptables dan nginx siap salin.
5. **Memverifikasi** - permintaan sesudah blokir dibedakan: ditolak (403) berarti blokir bekerja,
   diterima berarti aturan belum berlaku dan insiden dieskalasi.
6. **Operator memutuskan** - jadikan permanen, blokir ulang, atau tandai aman. Semua tindakan tercatat di kronologi.

## Fitur pendukung

- Dashboard pantau langsung (diperbarui setiap 5 detik), bilah status ancaman, grafik trafik per jam
- Konfirmasi blokir manual dengan peringatan sesuai tingkat risiko
- Aturan blokir siap salin untuk .htaccess, iptables, dan nginx
- Tabel insiden dengan tab Perlu tindakan / Diblokir / Ditandai aman, pencarian IP, dan Blokir semua
  (dengan pilihan "hanya kritis dan tinggi" supaya pemindai yang mungkin resmi tidak ikut terblokir)
- Angka "Ditolak server": jumlah permintaan IP terblokir yang ditolak (403), bukti blokir bekerja
- Lalu lintas latar terus berjalan (pengunjung, mesin pencari, bot pemindai acak), jadi dashboard selalu hidup
- Mode demo: simulasi tiga penyerang sekaligus, uji blokir gagal (tiga IP), reset ke data contoh

## Angka data contoh (untuk slide pengujian)

- 24 jam log, ±13 ribu permintaan, ±550 IP
- 22 insiden: 1 kritis (berhasil login), 10 tinggi (login paksa), 11 sedang (pengintaian)
- 4 IP diblokir otomatis oleh sistem semalam (menunggu konfirmasi operator), ±280 permintaannya ditolak server
- 1 pemindai internal tim IT terdeteksi tetapi tidak diblokir karena IP-nya terdaftar aman
- Tidak terdeteksi (benar): Googlebot dan Bingbot yang membuka link lama, pemantau uptime tiap 5 menit,
  dan pegawai yang salah ketik kata sandi 1-3 kali. Artinya ambang batas tidak memicu salah deteksi pada pola wajar.

## Teknologi

- PHP 8 dan MySQL/MariaDB, berjalan di hosting cPanel biasa (Apache/LiteSpeed)
- JavaScript tanpa framework, Chart.js untuk grafik
- Data contoh dan simulasi dibuat PHP (dan skrip Python untuk uji lewat terminal)
- Kode teknik serangan mengacu pada MITRE ATT&CK

## Keamanan dan batasan purwarupa

- Kode dan konfigurasi database berada di luar folder publik
- Header keamanan (CSP, HSTS), anti-XSS, dan validasi semua masukan
- Demo online memakai log contoh dan simulasi; IP penyerang memakai alamat dokumentasi (RFC 5737)
- Deteksi berbasis aturan, belum pembelajaran mesin
- Mode demo tanpa login; pemakaian sungguhan memerlukan login operator
- Pengembangan dibantu asisten AI (Claude), ditinjau dan diuji sendiri
