// JejakPanel - halaman kronologi satu insiden (diperbarui otomatis setiap 5 detik)

const idInsiden = new URLSearchParams(location.search).get('id');
let dataSebelumnya = '';
let sedangKonfirmasi = false;   // saat operator sedang mengonfirmasi, halaman tidak digambar ulang

/** Peringatan sebelum memblokir manual, sesuai tingkat risiko insiden. */
const PERINGATAN_BLOKIR = {
  kritis: 'Penyerang sudah berhasil login. Blokir sekarang, lalu segera ganti kata sandi akun admin dan keluarkan semua sesi login.',
  tinggi: 'IP ini mencoba menebak kata sandi. Memblokir akan menolak semua permintaannya di server website.',
  sedang: 'Insiden ini hanya pengintaian. Pengintaian belum tentu serangan (bisa mesin pencari atau pemindai resmi). '
    + 'Disarankan pantau dulu, dan blokir jika berlanjut ke percobaan login.',
  rendah: 'Risiko insiden ini rendah. Pastikan IP ini memang perlu diblokir.',
};
let tabAturan = 'htaccess';

function hurufKecil(t) { return /^(Hari|Kemarin)/.test(t) ? t[0].toLowerCase() + t.slice(1) : t; }

function bagianKepala(d) {
  return el('div', { class: 'kepala-insiden' },
    el('div', {},
      sev(d.risiko),
      el('h1', {}, d.ip),
      el('div', { class: 'ket' }, d.ringkasan),
    ),
  );
}

function bagianFakta(d) {
  const total = d.tahap.reduce((n, t) => n + t.jumlah_request, 0);
  const item = (label, nilai) => el('div', {}, el('dt', {}, label), el('dd', {}, nilai));
  return el('dl', { class: 'fakta' },
    item('Waktu', `${formatHariJam(d.mulai)} – ${formatJam(d.selesai).slice(0, 5)}`),
    item('Lama serangan', formatDurasi(d.mulai, d.selesai)),
    item('Permintaan', angka(total)),
    item('Status', labelStatus(d)),
  );
}

function barisTahap(t) {
  const risiko = RISIKO_TAHAP[t.jenis] || 'rendah';
  const rentang = t.mulai === t.selesai ? formatJam(t.mulai) : `${formatJam(t.mulai)}–${formatJam(t.selesai)}`;
  const lama = detikAntara(t.mulai, t.selesai);

  return el('li', { 'data-risiko': risiko },
    el('div', { class: 'rel-waktu' }, formatJam(t.mulai)),
    el('div', { class: 'rel-garis' }, el('span', { class: 'rel-titik' })),
    el('article', { class: 'tahap' },
      el('div', { class: 'tahap-judul' }, LABEL_TAHAP[t.jenis] || t.jenis, el('small', {}, `tahap ${t.urutan}`)),
      el('div', { class: 'label-baris' },
        el('span', { class: 'label' }, rentang),
        lama > 0 ? el('span', { class: 'label' }, formatDetik(lama)) : null,
        el('span', { class: 'label' }, `${angka(t.jumlah_request)} permintaan`),
        el('a', { class: 'label', href: urlAttack(t.teknik_attack), target: '_blank', rel: 'noopener',
          title: 'Buka penjelasan teknik di situs MITRE ATT&CK' }, `ATT&CK ${t.teknik_attack}`),
      ),
      el('p', {}, t.penjelasan),
      el('div', { class: 'saran' }, el('b', {}, 'Yang harus dilakukan'), t.rekomendasi),
    ),
  );
}

function barisKejadian(waktu, teks, jenis = '') {
  return el('li', {},
    el('div', { class: 'rel-waktu' }, waktu ? formatJam(waktu) : ''),
    el('div', { class: 'rel-garis' }),
    el('div', { class: `kejadian ${jenis}` }, teks),
  );
}

/** Menyusun tahap serangan dan kejadian operator dalam satu urutan waktu. */
function bagianKronologi(d) {
  const item = d.tahap.map(t => ({ waktu: t.mulai, selesai: t.selesai, tahap: true, buat: () => barisTahap(t) }));
  if (d.diblokir) {
    const teks = (d.cara_blokir === 'otomatis'
      ? 'Diblokir otomatis oleh sistem (serangan sedang berlangsung)'
      : 'IP diblokir oleh operator')
      + (d.diterapkan_pada ? ' · aturan ditulis ke .htaccess server' : '');
    item.push({ waktu: d.diblokir_pada, selesai: d.diblokir_pada, buat: () => barisKejadian(d.diblokir_pada, teks, 'penting') });
    if (d.cara_blokir === 'otomatis' && d.permanen_pada) {
      item.push({ waktu: d.permanen_pada, selesai: d.permanen_pada,
        buat: () => barisKejadian(d.permanen_pada, 'Dikonfirmasi operator: blokir dijadikan permanen', 'penting') });
    }
  }
  if (d.aman) {
    item.push({ waktu: d.aman.dibuat_pada, selesai: d.aman.dibuat_pada,
      buat: () => barisKejadian(d.aman.dibuat_pada, 'Blokir dibatalkan; IP ditandai aman oleh operator (salah deteksi)') });
  }
  if (d.blokir_ulang_pada) {
    item.push({ waktu: d.blokir_ulang_pada, selesai: d.blokir_ulang_pada,
      buat: () => barisKejadian(d.blokir_ulang_pada, 'Blokir ulang: operator menyatakan aturan sudah dipasang di server', 'penting') });
  }
  if (d.ditolak > 0) {
    item.push({ waktu: d.ditolak_terakhir, selesai: d.ditolak_terakhir,
      buat: () => barisKejadian(d.ditolak_terakhir,
        `${angka(d.ditolak)} permintaan sesudah diblokir ditolak server (403): blokir bekerja`, 'penting') });
  }
  item.sort((a, b) => a.waktu.localeCompare(b.waktu));

  const rel = el('ol', { class: 'rel' });
  item.forEach((it, i) => {
    if (i && it.tahap && item[i - 1].tahap) {   // jeda hanya ditampilkan di antara dua tahap serangan
      const jeda = detikAntara(item[i - 1].selesai, it.waktu);
      if (jeda >= 30) rel.append(barisKejadian('', `Jeda ${formatDetik(jeda)}`));
    }
    rel.append(it.buat());
  });
  return el('section', {}, el('h2', {}, 'Kronologi'), rel);
}

function bagianTindakan(d) {
  const isi = el('div', { class: 'tile-isi' });
  const tombol = (teks, kelas, aksi, ikonNama, konfirmasi) => {
    const b = el('button', { class: `btn ${kelas}`, type: 'button' }, teks, ikonNama ? ikon(ikonNama) : null);
    b.addEventListener('click', () => (konfirmasi ? mintaKonfirmasi(b, aksi, konfirmasi) : ubahBlokir(aksi, b)));
    return b;
  };
  const konfirmasiBlokir = { teks: PERINGATAN_BLOKIR[d.risiko] || PERINGATAN_BLOKIR.rendah, ya: `Ya, blokir ${d.ip}`, risiko: d.risiko };
  const konfirmasiBatal = {
    teks: 'IP ini akan dihapus dari daftar blokir dan ditandai aman, sehingga tidak diblokir otomatis lagi. '
      + 'Lakukan hanya jika yakin ini bukan serangan, misalnya IP kantor sendiri.',
    ya: 'Ya, batalkan blokir', risiko: 'sedang',
  };
  // Aturan siap salin dilipat: .htaccess website ini sudah ditulis otomatis, salinan hanya untuk server lain
  const aturan = (buka = false) => el('details', { class: 'ekspor kecil', open: buka },
    el('summary', {}, el('span', { class: 'ekspor-judul' }, buka ? 'Aturan blokir untuk dipasang' : 'Aturan untuk server lain')),
    el('div', { class: 'ekspor-isi' }, panelAturan([{ ip: d.ip, incident_id: d.id }], tabAturan, k => { tabAturan = k; })));
  const ketHtaccess = d.diterapkan_pada
    ? ' Aturan sudah ditulis otomatis ke .htaccess website ini.'
    : ' Salin aturan di bawah ke server website agar IP ini benar-benar tertolak.';
  const permanen = Boolean(d.permanen_pada);

  if (d.status === 'serangan_ulang') {
    isi.append(
      el('p', {}, `IP ini sudah diblokir pada ${hurufKecil(formatHariJam(d.blokir_ulang_pada || d.diblokir_pada))}, tetapi server masih menerima permintaannya. `
        + 'Kemungkinan .htaccess tertimpa atau aturan belum aktif. Klik Blokir ulang agar aturan ditulis lagi, lalu pastikan permintaan berikutnya berstatus 403.'),
      aturan(!d.diterapkan_pada),
      el('div', { class: 'btn-baris' }, tombol('Blokir ulang', 'bahaya', 'blokir', 'blokir')),
    );
  } else if (d.diblokir && d.cara_blokir === 'otomatis' && !permanen) {
    isi.append(
      el('p', {}, `Sistem memblokir IP ini otomatis pada ${hurufKecil(formatHariJam(d.diblokir_pada))} karena serangan berisiko ${d.risiko} sedang berlangsung. `
        + 'Tinjau kronologi: jika benar serangan, jadikan permanen. Jika salah deteksi (misal IP kantor sendiri), batalkan.'),
      el('div', { class: 'btn-baris' },
        tombol('Jadikan permanen', '', 'permanen'),
        tombol('Batalkan, tandai aman', 'hantu', 'batal', null, konfirmasiBatal),
      ),
      el('p', { class: 'muted jarak-atas' }, ketHtaccess.trim()),
      aturan(!d.diterapkan_pada),
    );
  } else if (d.diblokir) {
    isi.append(
      el('p', {}, `Diblokir permanen sejak ${hurufKecil(formatHariJam(d.permanen_pada || d.diblokir_pada))}.` + ketHtaccess
        + ' Jika IP ini masih bisa mengakses website, JejakPanel akan memberi peringatan.'),
      aturan(!d.diterapkan_pada),
      el('div', { class: 'btn-baris' }, tombol('Batalkan blokir, tandai aman', 'hantu', 'batal', null, konfirmasiBatal)),
    );
  } else if (d.aman) {
    isi.append(
      el('p', {}, 'IP ini ditandai aman oleh operator, sehingga tidak akan diblokir otomatis. Insiden tetap dicatat untuk ditinjau.'),
      el('div', { class: 'btn-baris' },
        tombol(`Blokir ${d.ip}`, 'bahaya', 'blokir', 'blokir', konfirmasiBlokir),
        tombol('Hapus tanda aman', 'hantu', 'hapus_aman'),
      ),
    );
  } else {
    isi.append(
      el('p', {}, 'IP ini belum diblokir. Memblokir akan menandai insiden sebagai ditangani dan menulis aturan blokir ke server website.'),
      el('div', { class: 'btn-baris' }, tombol(`Blokir ${d.ip}`, 'bahaya', 'blokir', 'blokir', konfirmasiBlokir)),
    );
  }

  const deteksi = el('div', { class: 'tile-isi' },
    el('p', {}, 'Insiden disusun dari aturan berikut. Ambang batas bisa diubah di app/detektor.php.'),
    el('ul', { class: 'daftar-aturan' },
      el('li', {}, 'Pengintaian: ≥ 10 alamat berbeda tidak ditemukan (404/403)'),
      el('li', {}, 'Login paksa: ≥ 20 percobaan login gagal'),
      el('li', {}, 'Login berhasil: ≤ 10 menit setelah login paksa'),
      el('li', {}, 'Rangkaian baru jika jeda > 5 menit'),
      el('li', {}, 'Blokir otomatis: insiden kritis atau tinggi dengan aktivitas ≤ 30 menit terakhir, kecuali IP yang ditandai aman'),
      el('li', {}, 'Serangan setelah diblokir: ada permintaan yang tetap diterima server (bukan 403)'),
    ),
  );

  return el('aside', { class: 'aside' },
    el('section', { class: 'tile' }, el('div', { class: 'tile-kepala' }, el('h2', {}, 'Tindakan')), isi),
    el('section', { class: 'tile' }, el('div', { class: 'tile-kepala' }, el('h2', {}, 'Aturan deteksi')), deteksi),
  );
}

/** Mengganti baris tombol dengan kotak konfirmasi: peringatan + "Ya" / "Batal". */
function mintaKonfirmasi(tombol, aksi, k) {
  const baris = tombol.closest('.btn-baris');
  const asal = [...baris.childNodes];
  sedangKonfirmasi = true;

  const ya = el('button', { class: `btn ${aksi === 'batal' ? 'sekunder' : 'bahaya'}`, type: 'button' }, k.ya);
  const tidak = el('button', { class: 'btn hantu', type: 'button' }, 'Batal');
  const kotak = el('div', { class: 'konfirmasi', 'data-risiko': k.risiko, role: 'alertdialog', 'aria-label': 'Konfirmasi tindakan' },
    el('p', {}, k.teks),
    el('div', { class: 'konfirmasi-aksi' }, ya, tidak),
  );
  ya.addEventListener('click', () => { sedangKonfirmasi = false; ubahBlokir(aksi, ya); });
  tidak.addEventListener('click', () => { sedangKonfirmasi = false; baris.replaceChildren(...asal); });
  baris.replaceChildren(kotak);
  ya.focus();
}

async function ubahBlokir(aksi, tombol) {
  tombol.disabled = true;
  try {
    await kirimJSON('api/block.php', { incident_id: Number(idInsiden), aksi });
    dataSebelumnya = '';
    await muat();
  } catch (err) {
    tombol.disabled = false;
    tombol.after(el('p', { class: 'pesan-aksi' }, err.message));
  }
}

async function muat() {
  const isi = document.getElementById('isi');
  if (!idInsiden) { tampilkanPesan(isi, 'Insiden tidak dipilih.'); return; }

  let d;
  // Siklus otomatis dijalankan lebih dulu; kalau lambat atau gagal, kronologi tetap dimuat
  await Promise.race([ambilJSON('api/tick.php').catch(() => {}), new Promise(r => setTimeout(r, 3000))]);
  try {
    d = await ambilJSON(`api/incident.php?id=${encodeURIComponent(idInsiden)}`);
  } catch (err) {
    if (dataSebelumnya) throw err;   // isi lama tetap tampil, indikator di header yang memberi tahu
    tampilkanPesan(isi, err.message);
    return;
  }
  const json = JSON.stringify(d);
  if (json === dataSebelumnya || sedangKonfirmasi) return;   // tidak berubah, atau operator sedang mengonfirmasi
  dataSebelumnya = json;

  document.title = `JejakPanel - ${d.ip}`;
  document.getElementById('remah-insiden').textContent = `Insiden #${d.id} · ${formatTanggal(d.mulai)}`;
  isiSitus(d.situs);
  isi.replaceChildren(
    bagianKepala(d),
    bagianFakta(d),
    el('div', { class: 'tata' }, bagianKronologi(d), bagianTindakan(d)),
  );
}

muat().catch(() => {}).finally(() => pantau(muat, 5));
