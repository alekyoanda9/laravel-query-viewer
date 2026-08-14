# Laravel Query Viewer (internal)

Floating panel untuk melihat raw SQL query per halaman di **server testing**:
grouping per menu, deteksi N+1/redundan, EXPLAIN on-demand (PostgreSQL), dan
export tiket bug berformat Markdown. Diturunkan dari fitur internal IAS-PHP,
lalu dilepas dari konvensi app-nya supaya bisa dipasang di banyak aplikasi
Laravel tim.

> **Hanya untuk lingkungan testing/terkontrol.** Panel menampilkan raw SQL
> beserta nilai binding. Jangan pernah `QUERY_DEBUG_ENABLED=true` di production.

---

## 1. Pasang

Package ini privat (tidak di Packagist publik). Pilih salah satu cara
distribusi (lihat §6), lalu di aplikasi konsumen:

```bash
composer require --dev sd1/laravel-query-viewer
```

`--dev` karena ini alat testing, tidak ikut ke build production.

Publikasikan config + asset JS:

```bash
php artisan vendor:publish --tag=query-viewer-config
php artisan vendor:publish --tag=query-viewer-assets
# opsional, kalau mau meng-override tampilan panel:
# php artisan vendor:publish --tag=query-viewer-views
```

`--tag=query-viewer-assets` menyalin `query-debug.js` ke
`public/vendor/query-viewer/`. **Ulangi perintah asset ini setiap kali
package di-update**, atau otomatiskan lewat script composer `post-update-cmd`.

---

## 2. Konfigurasi `.env`

```dotenv
QUERY_DEBUG_ENABLED=true
QUERY_DEBUG_HOST=172.20.28.34        # host server testing; kosongkan = semua host (hati-hati)
QUERY_DEBUG_KEY=key-acak-panjang     # untuk unlock panel

QUERY_DEBUG_INSIGHT=true             # ringkasan N+1/redundan
QUERY_DEBUG_EXPLAIN=true             # tombol EXPLAIN (PostgreSQL)
QUERY_DEBUG_EXPLAIN_ANALYZE=false    # EXPLAIN ANALYZE mengeksekusi query — biarkan false
```

Semua opsi lain (slow_ms, ttl, threshold, dsb) ada di `config/querydebug.php`.

---

## 3. Sambungkan ke aplikasi (opsional, tapi penting untuk app non-standar)

Package punya default yang jalan untuk app "biasa" (koneksi tunggal + auth
Laravel standar). Untuk app dengan koneksi per-session / konvensi user sendiri
(seperti IAS-PHP), daftarkan closure di `AppServiceProvider::boot()`:

```php
use Sd1\QueryViewer\QueryViewer;

public function boot()
{
    // Koneksi DB mana yang query-nya didengarkan (null = default app).
    QueryViewer::connectionUsing(function () {
        return session('connection');
    });

    // Kunci pemilik store, supaya batch antar-user tidak tercampur.
    QueryViewer::identifyUsing(function () {
        return session('usid') ?: 'guest';
    });

    // Metadata yang muncul di header tiket export.
    QueryViewer::contextUsing(function () {
        return [
            ['label' => 'Cabang (IGR)', 'value' => session('kdigr')],
            ['label' => 'User',         'value' => session('usid')],
        ];
    });
}
```

> **PHP 7.1 (IAS-PHP):** gunakan `function () { return ...; }`, **bukan**
> arrow function `fn () => ...` (baru ada di PHP 7.4).

Closure sengaja **tidak** ditaruh di file config: closure tidak bisa
di-serialize, jadi menaruhnya di config akan membuat `php artisan config:cache`
gagal. Menaruhnya di service provider aman terhadap config cache.

Kalau app menyimpan metadata di session dan tidak butuh logika, cukup isi
`config('querydebug.export.extra_session')` tanpa menulis closure.

---

## 4. Menampilkan panel

Default: `auto_inject` menyisipkan panel otomatis sebelum `</body>` pada tiap
response HTML — **tim tidak perlu menyentuh layout**. Matikan dengan
`QUERY_DEBUG_AUTO_INJECT=false` kalau mau memasang sendiri:

```blade
{{-- sebelum </body> di layout utama --}}
@include('querydebug::panel')
```

---

## 5. Cara pakai (QA/support)

1. Klik FAB `</>` di kanan-bawah (muncul hanya di host testing).
2. Masukkan API key → panel mulai mengumpulkan query untuk sesi ini.
3. Buka/jalankan menu; query mengelompok per halaman.
4. Export ke tiket: tombol **MD** di query (satu query), header request (satu
   request), atau header grup (seluruh alur halaman). **Explain** dulu bila
   ingin query plan ikut ke tiket.
5. **Lock** untuk berhenti mengumpulkan + membersihkan.

---

## 5b. Trace — perekam langkah support

Modul ini menjawab masalah: support menemukan bug, lalu harus **mengetik ulang
dari ingatan** urutan langkahnya ke developer ("tadi saya pilih cabang 44, terus
ke Master > OMI, terus..."). Sering ada yang kelewat, dan dev gagal reproduce.

### Cara kerja

Perekaman **sudah jalan terus** sejak sesi di-unlock (pola *flight recorder*) —
tidak ada tombol "mulai rekam" yang harus diingat, karena support tidak pernah
tahu bug akan muncul sebelum bug itu muncul.

Yang dipakai sebagai buffer langkah adalah ring buffer yang memang sudah ada:
`LogQueryDebug` mem-push satu batch per HTTP request, lengkap dengan
`method`, `path`, `route`, `conn`, `context`, `error`, dan `queries` — itu
persis satu langkah. Modul trace menambahkan `input`, `status`, dan `dur_ms`,
lalu menyediakan mekanisme mempromosikannya jadi file permanen.

### Alur

1. Support bekerja normal (unlock panel seperti biasa).
2. Menemukan hasil yang salah → buka panel → **Ambil Kasus**.
3. Isi catatan singkat + pilih langkah yang dicurigai (default: langkah terakhir).
4. Dapat kode `TRC-YYYYMMDD-XXXX` → kirim ke developer.
5. Developer buka `/{prefix}/trace/{kode}` → timeline lengkap.

### Fase 1 — capture ter-kurasi (baru)

Layar "Ambil Kasus" bukan lagi satu kotak catatan, tapi layar review:

- **Kategori** — error / hasil tidak sesuai / aksi tidak terjadi / lambat. Dibedakan karena penanganan devnya beda; untuk bug non-error, tidak ada exception yang menandai lokasi masalah, jadi dev bersandar pada urutan step + lampiran.
- **Deskripsi** multiline + **No. PRPK/Memo** opsional (dibersihkan dari karakter liar sebelum disimpan).
- **Lampiran** gambar/video (upload, dibatasi jumlah/ukuran/mime; video besar sebaiknya link di deskripsi). Disajikan lewat route ber-gate, divalidasi harus benar-benar milik trace itu (anti path-traversal).
- **Pilih langkah** yang disertakan (checkbox). Langkah yang di-exclude TIDAK dibuang — tetap tersimpan & tampil terlipat di viewer, karena "kukira noise ternyata itu bug-nya" sering terjadi.
- **Grup** langkah, default per halaman asal (reuse `origin`), bisa di-relabel, bisa **dipisah manual** di tengah satu halaman (untuk bug seperti ganti-supplier-tanpa-refresh yang terjadi di satu URL), dan ditandai **"gagal di bagian ini"**.
- **Titik gagal** — tandai satu langkah tempat seharusnya berhasil tapi gagal.

Snapshot langkah **dibekukan di klien** saat layar dibuka, lalu dikirim balik saat submit — bukan dibaca ulang dari buffer. Karena buffer terus merekam di latar, membaca ulang saat submit berisiko mengambil kondisi yang sudah bergeser dari yang dilihat support.

Buffer juga kini **mengabaikan request milik panel sendiri** (polling /recent, explain, capture, trace/*) supaya tidak cepat penuh oleh panggilan panel dan langkah setup yang lama tidak keburu tergeser keluar.

Hasil capture memberi **link lengkap yang bisa diklik** (tombol Copy link), bukan cuma kode.

### Yang dilihat developer

Header trace menonjolkan **koneksi/cabang** — penyebab nomor satu "kok di saya
tidak bisa reproduce". Lalu tiap langkah menampilkan waktu, method + path,
input request (sudah ter-redact), status & durasi, error kalau ada, dan
SQL mentah siap copy ke DBeaver. Langkah yang ditandai support diberi border
merah dan query-nya otomatis terbuka.

Tersedia juga `/{prefix}/trace/{kode}/json` untuk dilampirkan ke tiket PRPK/memo,
dan `/{prefix}/trace` untuk daftar trace terbaru.

### Akses halaman viewer

Halaman trace dibuka lewat **navigasi browser biasa**, yang tidak bisa mengirim
header `X-Query-Debug-Key`. Karena itu halaman ini memakai gate terpisah
(`querydebug.trace`): buka **sekali** dengan `?key=<API key>`, middleware
menandai session lalu redirect ke URL bersih. Efeknya kode trace aman dibagikan
lewat chat tanpa ikut membawa API key.

### Penyimpanan

Trace disimpan sebagai file JSON di `storage/app/querydebug/traces/YYYY-MM/`,
**bukan tabel DB**. Alasannya: koneksi DB aplikasi ini ikut cabang terpilih,
jadi trace yang disimpan di tabel tidak akan bisa dibuka developer yang sedang
login di cabang berbeda — persis masalah yang mau dihilangkan. Bonus: tanpa
migration, tidak menyentuh skema DB sama sekali.

### Batasan (penting, jangan dijanjikan lebih)

Trace ini **panduan reproduksi**, bukan mesin waktu. State data saat capture
sudah berbeda dengan saat dev membuka trace. Tidak ada replay otomatis, dan
memang sengaja: me-replay request tulis (POST/PUT) ke database nyata jauh lebih
berbahaya daripada manfaatnya. Yang didapat dev adalah peta presisi — cabang,
urutan, input, SQL, hasil — bukan pengembalian kondisi.

## 6. Distribusi (pilih salah satu)

**a. VCS repo privat (paling umum).** Push package ke GitLab/GitHub internal,
lalu di composer.json app konsumen:

```json
{
  "repositories": [
    { "type": "vcs", "url": "git@gitlab.internal:tim/laravel-query-viewer.git" }
  ]
}
```

Beri versi lewat git tag (`git tag v1.0.0 && git push --tags`), lalu
`composer require --dev sd1/laravel-query-viewer:^1.0`.

**b. Private Packagist / Satis.** Kalau tim punya banyak package internal,
jalankan Satis atau langganan Private Packagist supaya `composer require` jalan
tanpa blok `repositories` di tiap app.

**c. Path repo (untuk dev lokal antar-folder bersebelahan).**

```json
{
  "repositories": [
    { "type": "path", "url": "../laravel-query-viewer" }
  ]
}
```

---

## 7. Kompatibilitas

- PHP `>= 7.1` (kode tidak memakai fitur > 7.1).
- Laravel `5.8` sampai `11` secara sintaks; **tes di tiap versi yang benar-benar
  kamu pakai** sebelum menyebar luas — API framework yang dipakai
  (`pushMiddlewareToGroup`, `aliasMiddleware`, `loadRoutesFrom`) stabil di
  rentang itu, tapi verifikasi tetap perlu.
- EXPLAIN hanya untuk koneksi **PostgreSQL**.
