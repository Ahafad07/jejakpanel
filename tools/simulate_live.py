"""
JejakPanel - simulasi serangan secara langsung (untuk demo).

Menulis baris log ke data/live.log sedikit demi sedikit dengan waktu SEKARANG,
seolah-olah website sedang diserang. Tidak ada request yang dikirim ke server mana pun.
Jalankan bersama `php app/watch.php` agar dashboard ikut berubah.

Pemakaian:
    python tools/simulate_live.py                    -> serangan lengkap (sekitar 1 menit)
    python tools/simulate_live.py --ulang            -> IP yang sama menyerang lagi dan DITERIMA server
                                                        (blokir belum dipasang)
    python tools/simulate_live.py --ulang --ditolak  -> IP yang sama menyerang lagi tetapi DITOLAK (403)
                                                        (blokir sudah bekerja)
    tambahkan --cepat untuk kecepatan 2x
"""
import argparse
import os
import random
import sys
import time
from datetime import datetime, timedelta, timezone

WIB = timezone(timedelta(hours=7))

NORMAL_PATHS = ["/", "/profil", "/berita", "/berita/kegiatan-bakti-sosial", "/galeri", "/kontak",
                "/assets/css/style.css", "/assets/js/app.js", "/assets/img/logo.png", "/pengumuman"]
NORMAL_UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36"
SCAN_PATHS = ["/admin", "/administrator", "/wp-login.php", "/wp-admin", "/phpmyadmin", "/.env",
              "/.git/config", "/backup.zip", "/backup.sql", "/config.php.bak", "/db.sql", "/server-status",
              "/admin/login", "/cpanel", "/adminer.php", "/old", "/test", "/login.php", "/panel",
              "/uploads", "/install.php", "/.htaccess"]
SCAN_UA = "Mozilla/5.0 (compatible; scanner-sim/1.0)"
BRUTE_UA = "python-requests/2.32 (sim)"

PENGUNJUNG = [f"10.{random.randint(10, 60)}.{random.randint(0, 255)}.{random.randint(1, 254)}" for _ in range(40)]


class Penulis:
    def __init__(self, path, kecepatan):
        os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
        self.f = open(path, "a", encoding="utf-8")
        self.k = kecepatan

    def tulis(self, ip, method, path, status, size, ua, ref="-"):
        ts = datetime.now(WIB).strftime("%d/%b/%Y:%H:%M:%S %z")
        self.f.write(f'{ip} - - [{ts}] "{method} {path} HTTP/1.1" {status} {size} "{ref}" "{ua}"\n')
        self.f.flush()

    def normal(self):
        if random.random() < 0.6:
            self.tulis(random.choice(PENGUNJUNG), "GET", random.choice(NORMAL_PATHS), 200,
                       random.randint(800, 40000), NORMAL_UA)

    def tunggu(self, a, b):
        """Menunggu sambil tetap menulis trafik normal di latar belakang."""
        akhir = time.time() + random.uniform(a, b) / self.k
        while time.time() < akhir:
            time.sleep(min(0.25, max(0, akhir - time.time())))
            if random.random() < 0.15:
                self.normal()


def info(teks):
    print(f"{datetime.now(WIB):%H.%M.%S}  {teks}", flush=True)


def serangan_lengkap(w, ip):
    info("trafik normal...")
    w.tunggu(6, 8)

    info(f"[1/3] pengintaian dari {ip}")
    for path in random.sample(SCAN_PATHS, len(SCAN_PATHS)):
        status = 200 if path == "/login.php" else random.choice([404, 404, 404, 403])
        w.tulis(ip, "GET", path, status, random.randint(150, 600), SCAN_UA)
        w.tunggu(0.4, 0.9)

    info("jeda...")
    w.tunggu(8, 10)

    jumlah = random.randint(36, 44)
    info(f"[2/3] login paksa: {jumlah} percobaan")
    for _ in range(jumlah):
        w.tulis(ip, "POST", "/login.php", 401, 312, BRUTE_UA, ref="/login.php")
        w.tunggu(0.4, 0.8)

    info("[3/3] login BERHASIL")
    w.tulis(ip, "POST", "/login.php", 302, 0, BRUTE_UA, ref="/login.php")
    w.tunggu(0.8, 1.2)
    w.tulis(ip, "GET", "/admin/dashboard", 200, 5120, BRUTE_UA)
    w.tunggu(5, 6)


def serangan_ulang(w, ip, ditolak):
    keterangan = "DITOLAK server (403), blokir bekerja" if ditolak else "DITERIMA server, blokir belum dipasang"
    info(f"serangan ulang dari {ip}: {keterangan}")
    w.tunggu(2, 3)
    for _ in range(random.randint(22, 28)):
        w.tulis(ip, "POST", "/login.php", 403 if ditolak else 401, 199 if ditolak else 312, BRUTE_UA, ref="/login.php")
        w.tunggu(0.4, 0.8)
    if ditolak:
        w.tulis(ip, "GET", "/admin/dashboard", 403, 199, BRUTE_UA)
    else:
        info("login BERHASIL lagi")
        w.tulis(ip, "POST", "/login.php", 302, 0, BRUTE_UA, ref="/login.php")
        w.tunggu(0.8, 1.2)
        w.tulis(ip, "GET", "/admin/dashboard", 200, 5120, BRUTE_UA)
    w.tunggu(4, 5)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default="data/live.log")
    ap.add_argument("--ip", default="198.51.100.77", help="IP penyerang (blok alamat dokumentasi)")
    ap.add_argument("--ulang", action="store_true", help="serangan ulang setelah IP diblokir")
    ap.add_argument("--ditolak", action="store_true", help="dengan --ulang: server menolak (403)")
    ap.add_argument("--cepat", action="store_true", help="kecepatan 2x")
    args = ap.parse_args()

    w = Penulis(args.out, 2.0 if args.cepat else 1.0)
    try:
        if args.ulang:
            serangan_ulang(w, args.ip, args.ditolak)
        else:
            serangan_lengkap(w, args.ip)
        info("selesai.")
    except KeyboardInterrupt:
        info("dihentikan.")
        sys.exit(0)


if __name__ == "__main__":
    main()
