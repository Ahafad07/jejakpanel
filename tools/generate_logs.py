"""
JejakPanel - generator log akses simulasi (format Apache/Nginx "combined").

Menghasilkan trafik normal + skenario serangan untuk demo, TANPA mengirim
request ke server mana pun. Semua IP penyerang memakai blok alamat
dokumentasi (RFC 5737: 192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24),
jadi tidak ada IP milik orang sungguhan.

Pemakaian:
    python generate_logs.py                       -> data/access.log
    python generate_logs.py --out demo.log --seed 7
"""
import argparse
import random
from datetime import datetime, timedelta, timezone

WIB = timezone(timedelta(hours=7))

NORMAL_PATHS = [
    "/", "/profil", "/berita", "/berita/kegiatan-bakti-sosial", "/berita/upacara-hut",
    "/galeri", "/kontak", "/assets/css/style.css", "/assets/js/app.js",
    "/assets/img/logo.png", "/pengumuman", "/sejarah",
]
NORMAL_UA = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36",
    "Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36",
    "Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1",
]
SCAN_PATHS = [
    "/admin", "/administrator", "/wp-login.php", "/wp-admin", "/phpmyadmin", "/pma",
    "/.env", "/.git/config", "/backup.zip", "/backup.sql", "/config.php.bak", "/db.sql",
    "/server-status", "/admin/login", "/cpanel", "/adminer.php", "/old", "/test",
    "/login.php", "/panel", "/uploads", "/api/v1/users", "/.htaccess", "/install.php",
]
SCAN_UA = "Mozilla/5.0 (compatible; scanner-sim/1.0)"
BRUTE_UA = "python-requests/2.32 (sim)"


def line(ip, t, method, path, status, size, ua, ref="-"):
    ts = t.strftime("%d/%b/%Y:%H:%M:%S %z")
    return f'{ip} - - [{ts}] "{method} {path} HTTP/1.1" {status} {size} "{ref}" "{ua}"'


def normal_traffic(start, end, n, pengunjung=250):
    out = []
    span = (end - start).total_seconds()
    # Sekelompok pengunjung tetap yang datang berulang kali (lebih realistis)
    pool = [f"10.{random.randint(10, 60)}.{random.randint(0, 255)}.{random.randint(1, 254)}"
            for _ in range(pengunjung)]
    for _ in range(n):
        ip = random.choice(pool)
        t = start + timedelta(seconds=random.uniform(0, span))
        path = random.choice(NORMAL_PATHS)
        status = 200 if random.random() > 0.03 else 404
        out.append((t, line(ip, t, "GET", path, status, random.randint(800, 40000), random.choice(NORMAL_UA))))
    return out


def scenario_full_attack(ip, t0, success=True):
    """Pengintaian -> login paksa -> (opsional) login berhasil."""
    out, t = [], t0
    # Tahap 1: pengintaian - banyak path berbeda, mayoritas 404
    for path in random.sample(SCAN_PATHS, len(SCAN_PATHS)):
        t += timedelta(seconds=random.uniform(0.3, 2.0))
        status = 200 if path == "/login.php" else random.choice([404, 404, 404, 403])
        out.append((t, line(ip, t, "GET", path, status, random.randint(150, 600), SCAN_UA)))
    # jeda singkat
    t += timedelta(minutes=random.randint(3, 6))
    # Tahap 2: login paksa - banyak POST gagal dalam waktu singkat
    attempts = random.randint(120, 200)
    for _ in range(attempts):
        t += timedelta(seconds=random.uniform(0.4, 1.5))
        out.append((t, line(ip, t, "POST", "/login.php", 401, 312, BRUTE_UA, ref="/login.php")))
    # Tahap 3: login berhasil setelah rentetan gagal
    if success:
        t += timedelta(seconds=1)
        out.append((t, line(ip, t, "POST", "/login.php", 302, 0, BRUTE_UA, ref="/login.php")))
        t += timedelta(seconds=1)
        out.append((t, line(ip, t, "GET", "/admin/dashboard", 200, 5120, BRUTE_UA)))
    return out


def scenario_scan_only(ip, t0, n=40):
    out, t = [], t0
    for _ in range(n):
        t += timedelta(seconds=random.uniform(0.2, 1.0))
        out.append((t, line(ip, t, "GET", random.choice(SCAN_PATHS), 404, 196, SCAN_UA)))
    return out


def scenario_brute_only(ip, t0, n=60):
    out, t = [], t0
    for _ in range(n):
        t += timedelta(seconds=random.uniform(0.5, 2.0))
        out.append((t, line(ip, t, "POST", "/login.php", 401, 312, BRUTE_UA, ref="/login.php")))
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default="data/access.log")
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--normal", type=int, default=3000, help="jumlah request normal")
    ap.add_argument("--date", default="2026-11-06", help="tanggal log (YYYY-MM-DD)")
    args = ap.parse_args()

    random.seed(args.seed)
    day = datetime.strptime(args.date, "%Y-%m-%d").replace(tzinfo=WIB)
    start, end = day, day + timedelta(hours=24)

    rows = normal_traffic(start, end, args.normal)
    # Skenario utama untuk demo: serangan lengkap dini hari
    rows += scenario_full_attack("203.0.113.45", day + timedelta(hours=1, minutes=2), success=True)
    # Skenario pendukung
    rows += scenario_scan_only("198.51.100.23", day + timedelta(hours=9, minutes=15))
    rows += scenario_brute_only("192.0.2.77", day + timedelta(hours=14, minutes=40))
    rows += scenario_full_attack("203.0.113.88", day + timedelta(hours=21, minutes=5), success=False)

    rows.sort(key=lambda r: r[0])
    import os
    os.makedirs(os.path.dirname(args.out) or ".", exist_ok=True)
    with open(args.out, "w", encoding="utf-8") as f:
        f.write("\n".join(r[1] for r in rows) + "\n")
    print(f"{len(rows)} baris log ditulis ke {args.out}")


if __name__ == "__main__":
    main()
