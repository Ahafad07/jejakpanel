// JejakPanel - fungsi bantu bersama

const LABEL_TAHAP = {
  pengintaian: 'Pengintaian',
  login_paksa: 'Login paksa',
  login_berhasil: 'Login berhasil',
  lonjakan_error: 'Lonjakan error',
  serangan_ulang: 'Serangan setelah diblokir',
};
const RISIKO_TAHAP = {
  pengintaian: 'sedang', login_paksa: 'tinggi', login_berhasil: 'kritis', lonjakan_error: 'rendah', serangan_ulang: 'kritis',
};

/**
 * Label dan kelas tampilan untuk status insiden, termasuk jenis blokirnya.
 * i: objek insiden dari API (status, cara_blokir, permanen / permanen_pada, aman)
 */
function statusTampil(i) {
  const permanen = Boolean(i.permanen || i.permanen_pada);
  if (i.status === 'serangan_ulang') return { kelas: 'ulang', teks: 'Masih menyerang' };
  if (i.status === 'ditangani') {
    if (i.cara_blokir === 'otomatis' && !permanen) return { kelas: 'otomatis', teks: 'Diblokir otomatis' };
    return { kelas: 'diblokir', teks: permanen ? 'Diblokir permanen' : 'Diblokir' };
  }
  if (i.aman) return { kelas: 'aman', teks: 'Ditandai aman' };
  return { kelas: 'terbuka', teks: 'Belum ditangani' };
}

function labelStatus(i) {
  const s = statusTampil(i);
  return el('span', { class: `status ${s.kelas}` }, s.teks);
}

/**
 * Memanggil fungsi secara berkala (pantau langsung) dan menampilkan
 * keterangan "diperbarui pukul ..." di header.
 */
function pantau(fungsi, detik = 5) {
  const info = document.getElementById('pantau');
  let gagal = 0;
  const tandai = () => {
    if (!info) return;
    info.className = 'pantau aktif';
    info.textContent = `Pantau langsung · ${new Date().toLocaleTimeString('id-ID')}`;
  };
  tandai();
  // Permintaan berikutnya baru dikirim setelah yang sebelumnya selesai,
  // supaya tidak menumpuk saat server hosting sedang lambat.
  const jalan = async () => {
    if (!document.hidden) {
      try {
        await fungsi();
        gagal = 0;
        tandai();
      } catch (err) {
        gagal++;
        console.warn('Pembaruan gagal:', err);
        if (info && gagal >= 3) {
          info.className = 'pantau putus';
          info.textContent = 'Koneksi ke server terputus, mencoba lagi...';
        }
      }
    }
    setTimeout(jalan, detik * 1000);
  };
  setTimeout(jalan, detik * 1000);
}

async function ambilJSON(url, opsi = {}) {
  const batas = new AbortController();
  const jam = setTimeout(() => batas.abort(), 20000);
  let res;
  try {
    res = await fetch(url, { signal: batas.signal, ...opsi, headers: { Accept: 'application/json', ...(opsi.headers || {}) } });
  } finally {
    clearTimeout(jam);
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `Gagal memuat data (${res.status})`);
  return data;
}

function kirimJSON(url, isi) {
  return ambilJSON(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(isi),
  });
}

/**
 * Membuat elemen HTML dengan aman. Teks selalu dimasukkan lewat textContent,
 * sehingga isi log (yang bisa saja dikirim penyerang) tidak dijalankan sebagai HTML.
 */
function el(tag, attrs = {}, ...anak) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v == null || v === false) continue;
    if (k === 'class') node.className = v;
    else if (k === 'on') for (const [ev, fn] of Object.entries(v)) node.addEventListener(ev, fn);
    else if (k.includes('-')) node.setAttribute(k, v);   // data-*, aria-*
    else node[k] = v;
  }
  for (const a of anak.flat()) {
    if (a == null || a === false) continue;
    node.append(a instanceof Node ? a : document.createTextNode(String(a)));
  }
  return node;
}

/** Ikon SVG sederhana (garis), dibuat lewat DOM agar tidak memakai innerHTML. */
function ikon(nama) {
  const jalur = {
    peringatan: ['M12 3 2 21h20L12 3z', 'M12 10v5', 'M12 18v.01'],
    aman: ['M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z', 'm7.5 12 3 3 6-6'],
    blokir: ['M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z', 'M5 5l14 14'],
    salin: ['M8 8h12v12H8z', 'M4 16V4h12'],
    kanan: ['M9 6l6 6-6 6'],
  }[nama] || [];
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '2');
  svg.setAttribute('aria-hidden', 'true');
  for (const d of jalur) {
    const p = document.createElementNS(ns, 'path');
    p.setAttribute('d', d);
    svg.append(p);
  }
  return svg;
}

function keDate(s) { return new Date(s.replace(' ', 'T')); }

function formatJam(s) {
  return keDate(s).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function formatTanggal(s) {
  return keDate(s).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

/** "Hari ini 20.39", "Kemarin 23.10", atau "9 Nov 23.10". */
function formatHariJam(s) {
  const d = keDate(s);
  const jam = d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
  const awalHari = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const selisih = Math.round((awalHari(new Date()) - awalHari(d)) / 86400000);
  if (selisih === 0) return `Hari ini ${jam}`;
  if (selisih === 1) return `Kemarin ${jam}`;
  return `${d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })} ${jam}`;
}

/** "baru saja", "12 menit lalu", "3 jam lalu", "2 hari lalu". */
function waktuRelatif(s) {
  const detik = Math.max(0, Math.round((Date.now() - keDate(s)) / 1000));
  if (detik < 90) return 'baru saja';
  if (detik < 3600) return `${Math.round(detik / 60)} menit lalu`;
  if (detik < 86400) return `${Math.floor(detik / 3600)} jam lalu`;
  return `${Math.floor(detik / 86400)} hari lalu`;
}

/** Menampilkan nama website yang dipantau di header. */
function isiSitus(nama) {
  const n = document.getElementById('situs');
  if (n && nama) n.textContent = nama;
}

function detikAntara(mulai, selesai) {
  return Math.round((keDate(selesai) - keDate(mulai)) / 1000);
}

function formatDetik(detik) {
  if (detik < 1) return 'sesaat';
  if (detik < 60) return `${detik} detik`;
  const m = Math.floor(detik / 60), s = detik % 60;
  if (m < 60) return s ? `${m} menit ${s} detik` : `${m} menit`;
  return `${Math.floor(m / 60)} jam ${m % 60} menit`;
}

function formatDurasi(mulai, selesai) { return formatDetik(detikAntara(mulai, selesai)); }

function angka(n) { return Number(n).toLocaleString('id-ID'); }

function sev(risiko) { return el('span', { class: 'sev', 'data-risiko': risiko }, risiko); }

/** Tautan ke halaman resmi MITRE ATT&CK, misal T1595.003 -> /techniques/T1595/003/ */
function urlAttack(kode) { return `https://attack.mitre.org/techniques/${kode.replace('.', '/')}/`; }

function tampilkanPesan(wadah, teks) { wadah.replaceChildren(el('div', { class: 'pesan' }, teks)); }

/* ---------- Aturan blokir siap salin ---------- */

const FORMAT_ATURAN = {
  htaccess: {
    label: '.htaccess',
    buat: (daftar, waktu) => [
      `# JejakPanel - daftar blokir (${waktu})`,
      '# Apache 2.4: tempel di file .htaccess pada folder utama website',
      '<RequireAll>',
      '    Require all granted',
      ...daftar.map(b => `    Require not ip ${b.ip}`),
      '</RequireAll>',
    ].join('\n'),
  },
  iptables: {
    label: 'iptables',
    buat: (daftar, waktu) => [
      `# JejakPanel - daftar blokir (${waktu})`,
      '# Jalankan di server Linux sebagai root',
      ...daftar.map(b => `iptables -A INPUT -s ${b.ip} -j DROP   # insiden #${b.incident_id ?? '-'}`),
    ].join('\n'),
  },
  nginx: {
    label: 'nginx',
    buat: (daftar, waktu) => [
      `# JejakPanel - daftar blokir (${waktu})`,
      '# Tempel di dalam blok server { ... }, lalu reload nginx',
      ...daftar.map(b => `deny ${b.ip};   # insiden #${b.incident_id ?? '-'}`),
    ].join('\n'),
  },
};

/** Membuat panel tab aturan blokir untuk daftar IP yang diberikan. */
function panelAturan(daftar, tabAwal = 'htaccess', saatPilih = () => {}) {
  const waktu = new Date().toLocaleString('id-ID');
  const pre = el('pre', { class: 'aturan', tabIndex: 0 });
  const tombolSalin = el('button', { class: 'btn sekunder', type: 'button' }, 'Salin aturan', ikon('salin'));
  const tab = el('div', { class: 'tab', role: 'tablist' });

  const pilih = (kunci) => {
    pre.textContent = FORMAT_ATURAN[kunci].buat(daftar, waktu);
    tab.querySelectorAll('button').forEach(b => b.setAttribute('aria-selected', String(b.dataset.k === kunci)));
    saatPilih(kunci);
  };
  for (const [kunci, f] of Object.entries(FORMAT_ATURAN)) {
    tab.append(el('button', { type: 'button', role: 'tab', 'data-k': kunci, on: { click: () => pilih(kunci) } }, f.label));
  }

  tombolSalin.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(pre.textContent);
      tombolSalin.firstChild.textContent = 'Tersalin';
    } catch {
      const r = document.createRange(); r.selectNodeContents(pre);
      getSelection().removeAllRanges(); getSelection().addRange(r);
      tombolSalin.firstChild.textContent = 'Tekan Ctrl + C';
    }
    setTimeout(() => { tombolSalin.firstChild.textContent = 'Salin aturan'; }, 2000);
  });

  pilih(FORMAT_ATURAN[tabAwal] ? tabAwal : 'htaccess');
  return el('div', {}, tab, pre, tombolSalin);
}
