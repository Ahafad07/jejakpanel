// JejakPanel - halaman ringkasan (diperbarui otomatis setiap 5 detik)

let grafik = null;
let jejakSebelumnya = null;   // id + status + ringkasan tiap insiden, untuk menyorot perubahan
let daftarInsiden = [];
let aturanSebelumnya = '';
let tabAturan = 'htaccess';
let demoSebelumnya = '';
let pesanDemo = '';
let filter = null;            // tab aktif; null = dipilih otomatis saat pertama dimuat
let kataCari = '';
let konfirmasiMassal = null;  // daftar insiden yang sedang dikonfirmasi untuk ditangani sekaligus
let demoTerbuka = false;
try { demoTerbuka = sessionStorage.getItem('jp-demo') === '1'; } catch { /* penyimpanan tidak tersedia */ }

function aturDemoTerbuka(buka) {
  demoTerbuka = buka;
  try { sessionStorage.setItem('jp-demo', buka ? '1' : '0'); } catch { /* abaikan */ }
  document.getElementById('tile-demo').hidden = !buka;
  document.getElementById('tombol-demo').setAttribute('aria-expanded', String(buka));
}
document.getElementById('tombol-demo').addEventListener('click', () => aturDemoTerbuka(!demoTerbuka));

/* ---------- Bilah status ancaman ---------- */

function bilahStatus(ringkas, daftar) {
  const belum = daftar.filter(i => i.status === 'baru' && !i.aman);
  const ulang = daftar.filter(i => i.status === 'serangan_ulang');
  const kritis = belum.filter(i => i.risiko === 'kritis');
  const menunggu = daftar.filter(i => i.menunggu && i.status !== 'serangan_ulang');
  const lainnya = (n) => (n > 0 ? ` ${n} insiden lain juga menunggu keputusan.` : '');

  let level, judul, teks, target;
  if (ulang.length) {
    level = 'ulang';
    judul = `Kritis: ${ulang.length} IP yang sudah diblokir masih bisa mengakses website`;
    teks = 'Server masih menerima permintaan dari IP tersebut. Aturan blokir kemungkinan hilang atau tertimpa. '
      + 'Periksa .htaccess, lalu lakukan blokir ulang.';
    target = ulang[0];
  } else if (kritis.length) {
    level = 'kritis';
    judul = `Waspada: ${kritis.length} penyerang berhasil login dan belum diblokir`;
    teks = 'Kata sandi akun admin kemungkinan sudah ditebak. Blokir IP-nya, ganti kata sandi, dan keluarkan semua sesi login.'
      + lainnya(belum.length - kritis.length + menunggu.length);
    target = kritis[0];
  } else if (menunggu.length) {
    level = 'info';
    judul = `${menunggu.length} IP diblokir otomatis, menunggu konfirmasi operator`;
    teks = 'Sistem memblokir karena serangan berisiko tinggi sedang berlangsung. Tinjau kronologinya, lalu jadikan permanen '
      + 'atau batalkan jika salah deteksi.' + lainnya(belum.length);
    target = menunggu[0];
  } else if (belum.length) {
    level = 'tinggi';
    judul = `${belum.length} insiden belum ditangani`;
    teks = 'Tidak ada login yang berhasil dibobol, tetapi masih ada IP penyerang yang belum diputuskan: blokir atau tandai aman.';
    target = belum[0];
  } else if (daftar.length) {
    level = 'aman';
    judul = 'Semua insiden sudah ditangani';
    teks = `${angka(ringkas.diblokir)} IP penyerang diblokir dan ${angka(ringkas.ditolak)} permintaan mereka sudah ditolak server.`;
  } else {
    level = 'aman';
    judul = 'Tidak ada insiden terdeteksi';
    teks = 'Belum ditemukan pola pengintaian atau login paksa pada log yang dianalisis.';
  }

  document.getElementById('status').replaceChildren(el('div', { class: 'notif', 'data-level': level, role: 'status' },
    ikon(level === 'aman' ? 'aman' : 'peringatan'),
    el('div', { class: 'notif-isi' }, el('div', { class: 'notif-judul' }, judul), el('div', { class: 'notif-teks' }, teks)),
    target ? el('a', { href: `insiden.html?id=${target.id}` }, 'Buka kronologi →') : null,
  ));
}

/* ---------- Panel simulasi (mode demo) ---------- */

async function aksiDemo(isi, tombol) {
  tombol.disabled = true;
  const teksAwal = tombol.textContent;
  if (isi.aksi === 'reset') tombol.textContent = 'Memuat data contoh…';
  pesanDemo = '';
  try {
    await kirimJSON('api/simulasi.php', isi);
    if (isi.aksi === 'reset') { filter = null; kataCari = ''; document.getElementById('cari').value = ''; }
  } catch (err) {
    pesanDemo = err.message;
  }
  tombol.textContent = teksAwal;
  demoSebelumnya = '';
  await muat();
}

function panelDemo(tick, adaBlokir) {
  const tombolHeader = document.getElementById('tombol-demo');
  tombolHeader.hidden = !tick.mode_demo;
  if (!tick.mode_demo) { document.getElementById('tile-demo').hidden = true; return; }

  const sim = tick.simulasi;
  const berjalan = Boolean(sim && sim.berjalan);
  tombolHeader.classList.toggle('berjalan', berjalan);
  tombolHeader.textContent = berjalan ? `Simulasi ${sim.detik}/${sim.total} dtk` : 'Mode demo';
  document.getElementById('tile-demo').hidden = !demoTerbuka;

  const kunci = JSON.stringify([sim, adaBlokir, pesanDemo]);
  if (kunci === demoSebelumnya) return;
  demoSebelumnya = kunci;

  const tombol = (teks, kelas, isi, nonaktif, judul) => {
    const b = el('button', { class: `btn ${kelas}`, type: 'button', disabled: nonaktif, title: judul }, teks);
    b.addEventListener('click', () => aksiDemo(isi, b));
    return b;
  };
  const tutup = el('button', { class: 'btn hantu demo-tutup', type: 'button' }, 'Tutup');
  tutup.addEventListener('click', () => aturDemoTerbuka(false));

  let teks;
  const ips = sim ? sim.ips.join(', ') : '';
  if (berjalan && sim.jenis === 'blokir_gagal') {
    teks = `${sim.ips.length} IP yang sudah diblokir (${ips}) kembali menyerang, seolah aturan blokir di server hilang. `
      + 'Perhatikan status "Masih menyerang", lalu lakukan blokir ulang.';
  } else if (berjalan) {
    teks = `Tiga penyerang tiruan (${ips}) dengan tingkat bahaya berbeda sedang beraksi, ditambah satu pegawai yang salah `
      + 'ketik kata sandi. Penebak kata sandi diblokir otomatis; pemindai dibiarkan untuk keputusan operator; pegawai tidak dianggap serangan.';
  } else {
    teks = 'Jalankan serangan tiruan untuk melihat deteksi, blokir otomatis, dan verifikasi blokir secara langsung. '
      + 'Simulasi hanya menulis baris log tiruan; tidak ada permintaan yang dikirim ke server mana pun.';
  }

  document.getElementById('demo').replaceChildren(...[
    el('div', { class: 'demo' },
      el('div', { class: 'demo-teks' },
        el('div', { class: 'demo-label' }, berjalan ? `Simulasi berjalan · ${sim.detik}/${sim.total} detik` : 'Mode demo'),
        el('span', {}, teks),
      ),
      el('div', { class: 'demo-aksi' },
        tombol('Jalankan serangan tiruan', '', { aksi: 'mulai', jenis: 'serangan' }, berjalan),
        tombol('Uji blokir gagal', 'sekunder', { aksi: 'mulai', jenis: 'blokir_gagal' }, berjalan || !adaBlokir,
          'Sampai tiga IP terblokir terakhir kembali menyerang, seolah aturan blokir di server hilang'),
        tombol('Reset demo', 'hantu', { aksi: 'reset' }, berjalan, 'Muat ulang data contoh 24 jam terakhir'),
        tutup,
      ),
    ),
    berjalan ? el('div', { class: 'kemajuan' }, el('i')) : null,
    pesanDemo ? el('div', { class: 'pesan-aksi' }, pesanDemo) : null,
  ].filter(Boolean));
  const batang = document.querySelector('.kemajuan i');
  if (batang) batang.style.width = `${Math.round((sim.detik / sim.total) * 100)}%`;
}

/* ---------- Angka & grafik ---------- */

function isiKpi(d) {
  const p = d.perlu_tindakan;
  const jumlahPerlu = p.kritis + p.tinggi + p.sedang + p.rendah;
  document.getElementById('kpi-perlu').textContent = angka(jumlahPerlu);
  document.getElementById('kpi-perlu-wadah').dataset.ada = jumlahPerlu > 0 ? (p.kritis ? 'kritis' : 'ya') : 'tidak';
  const rincian = [];
  if (p.kritis) rincian.push(el('b', {}, `${p.kritis} kritis`));
  if (p.tinggi) rincian.push(`${p.tinggi} tinggi`);
  if (p.sedang) rincian.push(`${p.sedang} sedang`);
  document.getElementById('kpi-perlu-ket').replaceChildren(...(rincian.length
    ? rincian.flatMap((r, i) => (i ? [' · ', r] : [r]))
    : ['semua insiden sudah ditangani']));

  document.getElementById('kpi-blokir').textContent = angka(d.diblokir);
  document.getElementById('kpi-blokir-ket').textContent = d.serangan_ulang
    ? `${d.serangan_ulang} masih menyerang setelah diblokir`
    : d.diblokir
      ? [d.blokir_otomatis ? `${d.blokir_otomatis} otomatis` : '', d.blokir_manual ? `${d.blokir_manual} manual` : '',
         d.menunggu_konfirmasi ? `${d.menunggu_konfirmasi} menunggu konfirmasi` : ''].filter(Boolean).join(' · ')
      : 'belum ada';

  document.getElementById('kpi-ditolak').textContent = angka(d.ditolak);
  document.getElementById('kpi-request').textContent = angka(d.total_request);
  document.getElementById('kpi-request-ket').textContent = `24 jam terakhir, dari ${angka(d.ip_unik)} IP`;

  isiSitus(d.situs);
  const k = d.kebijakan;
  const meta = [];
  if (d.log_awal) meta.push(`Log ${formatHariJam(d.log_awal)} – ${formatHariJam(d.log_terakhir)}`);
  meta.push(k.blokir_otomatis
    ? `Blokir otomatis aktif untuk risiko ${k.risiko.join(' dan ')} yang masih berlangsung (≤ ${k.jendela_menit} menit)`
    : 'Blokir otomatis nonaktif');
  document.getElementById('meta-hal').textContent = meta.join(' · ');
}

function gambarGrafik(perJam) {
  const label = perJam.map(p => p.jam.slice(11, 13));
  const normal = perJam.map(p => p.normal);
  const penyerang = perJam.map(p => p.penyerang);
  if (grafik) {
    grafik.data.labels = label;
    grafik.data.datasets[0].data = normal;
    grafik.data.datasets[1].data = penyerang;
    grafik.$jam = perJam.map(p => p.jam);
    grafik.update('none');
    return;
  }
  const kanvas = document.getElementById('grafik');
  if (typeof Chart === 'undefined') {
    tampilkanPesan(kanvas.parentElement, 'Grafik tidak dapat dimuat (file assets/vendor/chart.umd.js tidak ditemukan).');
    return;
  }
  const css = getComputedStyle(document.documentElement);
  const v = (n) => css.getPropertyValue(n).trim();
  Chart.defaults.font.family = v('--sans');
  Chart.defaults.font.size = 12;
  Chart.defaults.color = v('--text-3');

  grafik = new Chart(kanvas, {
    type: 'bar',
    data: {
      labels: label,
      datasets: [
        { label: 'Pengunjung biasa', data: normal, backgroundColor: '#4f5763', stack: 'a', borderRadius: 2 },
        { label: 'IP penyerang', data: penyerang, backgroundColor: v('--kritis'), stack: 'a', borderRadius: 2 },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      barPercentage: 0.7,
      plugins: {
        legend: { position: 'top', align: 'end', labels: { boxWidth: 10, boxHeight: 10, color: v('--text-2') } },
        tooltip: { callbacks: { title: (it) => `${formatHariJam(grafik.$jam[it[0].dataIndex])} – ${it[0].label}.59` } },
      },
      scales: {
        x: { stacked: true, grid: { display: false }, border: { color: v('--border-strong') },
             title: { display: true, text: 'Jam (WIB)' } },
        y: { stacked: true, grid: { color: v('--border') }, border: { display: false },
             title: { display: true, text: 'Permintaan' } },
      },
    },
  });
  grafik.$jam = perJam.map(p => p.jam);
}

/* ---------- Tabel insiden: tab, pencarian, tindakan massal ---------- */

const FILTER = {
  perlu:    { label: 'Perlu tindakan', cocok: i => i.perlu_tindakan, kosong: 'Tidak ada insiden yang perlu tindakan. Semua sudah ditangani.' },
  diblokir: { label: 'Diblokir', cocok: i => i.diblokir, kosong: 'Belum ada IP yang diblokir.' },
  aman:     { label: 'Ditandai aman', cocok: i => i.aman && !i.diblokir, kosong: 'Tidak ada IP yang ditandai aman.' },
  semua:    { label: 'Semua', cocok: () => true, kosong: 'Belum ada insiden.' },
};

function cocokCari(i) {
  if (!kataCari) return true;
  return `${i.ip} ${i.ringkasan} ${i.risiko}`.toLowerCase().includes(kataCari);
}

function isiTab() {
  const wadah = document.getElementById('tab-filter');
  if (!wadah.childElementCount) {
    for (const [k, f] of Object.entries(FILTER)) {
      wadah.append(el('button', { type: 'button', role: 'tab', 'data-k': k, on: { click: () => pilihFilter(k) } },
        f.label, el('span', { class: 'jumlah' })));
    }
  }
  for (const b of wadah.children) {
    const k = b.dataset.k;
    b.setAttribute('aria-selected', String(k === filter));
    b.querySelector('.jumlah').textContent = daftarInsiden.filter(FILTER[k].cocok).length;
  }
}

function pilihFilter(k) {
  filter = k;
  konfirmasiMassal = null;
  gambarTabel();
}

function urutanTampil(i) {
  if (i.status === 'serangan_ulang') return 0;
  if (i.perlu_tindakan && i.risiko === 'kritis') return 1;
  if (i.menunggu) return 2;
  return 3;
}

function gambarTabel() {
  isiTab();
  const tampil = daftarInsiden.filter(FILTER[filter].cocok).filter(cocokCari);
  const tbody = document.getElementById('tabel-insiden');
  const jejak = new Map(daftarInsiden.map(i => [i.id, `${statusTampil(i).teks}|${i.ringkasan}`]));

  if (!tampil.length) {
    tbody.replaceChildren(el('tr', { class: 'kosong' }, el('td', { colSpan: 7, class: 'muted' },
      kataCari ? `Tidak ada insiden yang cocok dengan "${kataCari}".` : FILTER[filter].kosong)));
  } else {
    tbody.replaceChildren(...tampil.map(i => {
      const url = `insiden.html?id=${i.id}`;
      const alur = el('span', { class: 'alur' });
      (i.ringkasan || '').split(' → ').forEach((l, n) => { if (n) alur.append(el('i', {}, '›')); alur.append(l); });
      const berubah = jejakSebelumnya && jejakSebelumnya.get(i.id) !== jejak.get(i.id);

      return el('tr', { class: berubah ? 'sorot' : null, on: { click: () => { location.href = url; } } },
        el('td', {}, sev(i.risiko)),
        el('td', { class: 'utama' }, el('a', { href: url, class: 'mono' }, i.ip)),
        el('td', {}, alur),
        el('td', { title: `${formatTanggal(i.selesai)} ${formatJam(i.selesai)}` },
          formatHariJam(i.selesai), el('span', { class: 'relatif' }, waktuRelatif(i.selesai))),
        el('td', { class: 'kanan mono' }, angka(i.jumlah_request)),
        el('td', {}, labelStatus(i)),
        el('td', { class: 'panah-baris' }, ikon('kanan')),
      );
    }));
  }
  jejakSebelumnya = jejak;

  // Tombol tindakan massal hanya di tab "Perlu tindakan"
  const tombol = document.getElementById('tombol-massal');
  const bisa = filter === 'perlu' ? tampil : [];
  tombol.hidden = !bisa.length || Boolean(konfirmasiMassal);
  tombol.textContent = `Blokir semua (${bisa.length})`;
  tombol.onclick = () => { konfirmasiMassal = bisa.slice(); gambarTabel(); };
  gambarKonfirmasiMassal();
}

function rencanaMassal(daftar) {
  return {
    blokir: daftar.filter(i => i.status === 'baru' && !i.diblokir),
    ulang: daftar.filter(i => i.status === 'serangan_ulang'),
    permanen: daftar.filter(i => i.menunggu && i.status !== 'serangan_ulang'),
  };
}

function gambarKonfirmasiMassal() {
  const wadah = document.getElementById('konfirmasi-massal');
  if (!konfirmasiMassal) {
    if (wadah.querySelector('.konfirmasi')) wadah.replaceChildren();   // pesan hasil dibiarkan sampai hilang sendiri
    return;
  }

  const r = rencanaMassal(konfirmasiMassal);
  const bagian = [];
  if (r.blokir.length) bagian.push(`${r.blokir.length} IP diblokir`);
  if (r.ulang.length) bagian.push(`${r.ulang.length} IP diblokir ulang`);
  if (r.permanen.length) bagian.push(`${r.permanen.length} blokir otomatis dijadikan permanen`);
  const pengintai = r.blokir.filter(i => i.risiko === 'sedang' || i.risiko === 'rendah');
  const berat = konfirmasiMassal.filter(i => !pengintai.includes(i));

  const kirim = async (daftar, b) => {
    b.disabled = true;
    try {
      const res = await kirimJSON('api/block.php', { aksi: 'massal', incident_ids: daftar.map(i => i.id) });
      const h = res.hasil;
      pesanMassal(`Selesai: ${h.blokir} diblokir, ${h.blokir_ulang} diblokir ulang, ${h.permanen} dijadikan permanen. `
        + 'Aturan .htaccess sudah diperbarui.');
      konfirmasiMassal = null;
      await muat();
    } catch (err) {
      b.disabled = false;
      pesanMassal(err.message, true);
    }
  };
  const tombol = (teks, kelas, fn) => {
    const b = el('button', { type: 'button', class: `btn ${kelas}` }, teks);
    b.addEventListener('click', () => fn(b));
    return b;
  };

  wadah.replaceChildren(el('div', { class: 'konfirmasi', 'data-risiko': 'kritis', role: 'alertdialog', 'aria-label': 'Konfirmasi tindakan massal' },
    el('p', {}, el('b', {}, `Tangani ${konfirmasiMassal.length} insiden sekaligus? `), bagian.join(', ') + '.'),
    pengintai.length ? el('p', { class: 'muted' }, `${pengintai.length} di antaranya hanya pengintaian (risiko sedang). `
      + 'Pengintaian bisa berasal dari pemindai resmi; jika ragu, blokir yang kritis dan tinggi saja.') : null,
    el('div', { class: 'konfirmasi-aksi' },
      tombol(`Ya, tangani ${konfirmasiMassal.length} insiden`, 'bahaya', b => kirim(konfirmasiMassal, b)),
      pengintai.length && berat.length ? tombol(`Hanya kritis dan tinggi (${berat.length})`, 'sekunder', b => kirim(berat, b)) : null,
      tombol('Batal', 'hantu', () => { konfirmasiMassal = null; gambarTabel(); }),
    ),
  ));
}

function pesanMassal(teks, gagal = false) {
  const wadah = document.getElementById('konfirmasi-massal');
  wadah.replaceChildren(el('div', { class: `pesan-massal${gagal ? ' gagal' : ''}`, role: 'status' }, teks));
  setTimeout(() => { if (!konfirmasiMassal && wadah.firstChild?.classList?.contains('pesan-massal')) wadah.replaceChildren(); }, 6000);
}

document.getElementById('cari').addEventListener('input', (e) => {
  kataCari = e.target.value.trim().toLowerCase();
  if (!konfirmasiMassal) gambarTabel();
});

function isiTabel(daftar) {
  daftarInsiden = daftar.slice().sort((a, b) => urutanTampil(a) - urutanTampil(b));
  if (filter === null) filter = daftar.some(i => i.perlu_tindakan) ? 'perlu' : 'semua';
  document.getElementById('hitung-insiden').textContent = daftar.length;
  if (konfirmasiMassal) { isiTab(); return; }   // jangan ubah tabel saat operator sedang mengonfirmasi
  gambarTabel();
}

/* ---------- Ekspor aturan (untuk server lain) ---------- */

async function isiAturan() {
  const { daftar, tulis_htaccess: tulis } = await ambilJSON('api/blocklist.php');
  const kunci = daftar.map(b => b.ip).join(',') + tulis;
  if (kunci === aturanSebelumnya) return daftar.length;   // tidak berubah: jangan ganggu tab yang sedang dibaca
  aturanSebelumnya = kunci;

  const wadah = document.getElementById('tile-blokir');
  wadah.hidden = !daftar.length;
  document.getElementById('hitung-aturan').textContent = daftar.length;
  document.getElementById('ekspor-ket').textContent = tulis
    ? 'Website ini sudah terlindungi otomatis lewat .htaccess. Buka hanya jika perlu memasang blokir di server lain.'
    : 'JejakPanel tidak bisa menulis .htaccess di server ini. Salin aturan ke server website secara manual.';
  if (daftar.length) {
    document.getElementById('aturan-semua').replaceChildren(
      el('p', { class: 'muted' }, 'Pilih jenis server, lalu salin. Formatnya: Apache (.htaccess), firewall Linux (iptables), atau nginx.'),
      panelAturan(daftar, tabAturan, k => { tabAturan = k; }),
    );
  }
  return daftar.length;
}

let tickTerakhir = null;

async function muat() {
  // Jalankan siklus otomatis lebih dulu. Saat halaman baru dibuka, data ditampilkan
  // tanpa menunggu siklus yang lama (misalnya setelah website lama tidak dibuka).
  const janjiTick = ambilJSON('api/tick.php').then(t => (tickTerakhir = t));
  if (tickTerakhir) {
    await janjiTick;
  } else {
    await Promise.race([janjiTick.catch(() => {}), new Promise(r => setTimeout(r, 1500))]);
  }
  const tick = tickTerakhir;
  const [ringkas, daftar] = await Promise.all([ambilJSON('api/summary.php'), ambilJSON('api/incidents.php')]);
  bilahStatus(ringkas, daftar);
  isiKpi(ringkas);
  gambarGrafik(ringkas.per_jam);
  isiTabel(daftar);
  const jumlahBlokir = await isiAturan();
  if (tick) panelDemo(tick, jumlahBlokir > 0);
}

// Pemantauan tetap dimulai walau pemuatan pertama gagal, supaya halaman pulih sendiri
muat()
  .catch(err => tampilkanPesan(document.getElementById('status'), err.message))
  .finally(() => pantau(muat, 5));
