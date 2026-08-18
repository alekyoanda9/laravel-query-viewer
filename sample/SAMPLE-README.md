# Sample app — uji coba lokal `laravel-query-viewer`

Folder ini adalah project Laravel 5.8 kosong yang sudah di-wiring untuk
memuat package `sd1/laravel-query-viewer` **langsung dari folder di
sebelahnya** lewat Composer `path` repository — jadi setiap kamu ubah kode
di `src/`, cukup refresh browser, **tidak perlu commit/push/tag dulu**.

## 0. Taruh di mana

Naruh folder `sample/` ini **sejajar** dengan root package (yang isinya
`src/`, `composer.json`, dll), karena `composer.json` sample menunjuk
`"url": "../"`:

```
laravel-query-viewer/        <- root package (repo kamu)
├── src/
├── config/
├── composer.json            <- punya package (name: sd1/laravel-query-viewer)
└── sample/                  <- taruh isi zip ini di sini
    ├── app/
    ├── composer.json        <- punya sample app, path repo -> "../"
    └── ...
```

Kalau strukturmu beda, tinggal ubah `"url"` di `sample/composer.json` →
`repositories[0].url` supaya menunjuk ke folder root package yang benar.

## 1. Install

```bash
cd sample
composer install
```

> **Catatan:** saya generate & susun semua file ini di sandbox, tapi sandbox
> ini **tidak punya akses ke Packagist** (hanya beberapa domain yang
> di-whitelist), jadi `composer install` belum pernah benar-benar dijalankan
> di sini. Jalankan di mesinmu sendiri ya — kalau ada bentrok versi paket,
> paling gampang cek dulu versi PHP lokal (`php -v`, harus >= 7.1) dan
> composer (`composer -V`).

Karena pakai `type: path` + `symlink: true`, Composer akan **symlink**
`vendor/sd1/laravel-query-viewer` ke folder package asli (bukan copy) — jadi
perubahan kode langsung kepakai tanpa `composer update` ulang. Kalau di
platform kamu symlink bermasalah (mis. Windows tanpa izin), hapus baris
`"options": { "symlink": true }` supaya Composer copy biasa (tapi jadi harus
`composer update sd1/laravel-query-viewer` tiap ganti kode).

## 2. Setup DB & app key

```bash
touch database/database.sqlite
php artisan key:generate
php artisan migrate --seed
```

(`.env` sudah diarahkan ke SQLite supaya nggak perlu setup MySQL/Postgres.
Kalau mau tes fitur **EXPLAIN**, itu cuma jalan di koneksi PostgreSQL — README
package menyebutkan ini, jadi kalau mau coba tombol EXPLAIN, ganti
`DB_CONNECTION` ke koneksi Postgres kamu sendiri.)

## 3. Publish asset panel (JS-nya, wajib tiap update package)

```bash
php artisan vendor:publish --tag=query-viewer-assets
php artisan vendor:publish --tag=query-viewer-config
```

## 4. Jalankan

```bash
php artisan serve
```

Buka `http://127.0.0.1:8000`:

1. Pilih cabang + isi user → ini yang ngisi `session('kdigr')`,
   `session('usid')`, `session('connection')` — persis pola yang dibaca
   `QueryViewer::connectionUsing()/identifyUsing()/contextUsing()` di
   `app/Providers/AppServiceProvider.php` (sudah disalin dari spek kamu).
2. Klik FAB `</>` di kanan-bawah → masukkan key dari `.env`
   (`QUERY_DEBUG_KEY=local-dev-key`) → panel mulai merekam.
3. Buka **"orders (N+1 sengaja)"** vs **"orders (eager load)"** → bandingkan
   jumlah query & temuan di tab Insight.
4. Coba **"export orders"** — nama route-nya mengandung `export`, cocok
   dengan `download_patterns` di config, buat lihat bagaimana panel menandai
   request semacam itu.
5. Coba alur **Trace**: lakukan beberapa langkah (login → orders → export),
   lalu di panel klik **Ambil Kasus** untuk lihat fitur capture trace-nya.

Setiap kali kamu edit file di `src/` package (route, middleware, view panel,
JS), tinggal refresh browser (untuk PHP) atau
`php artisan vendor:publish --tag=query-viewer-assets --force` lagi kalau
yang berubah `query-debug.js`.

## Soal daftar package di `composer.json`

Saya **tidak** menyalin 1:1 semua dependency dari spek `composer.json`
IAS-PHP yang kamu kasih (aws-sdk, dompdf, oci8/pdo-oci8, maatwebsite/excel,
dst) — itu semua nggak relevan untuk sekadar uji coba panel query viewer,
dan beberapa (terutama `yajra/laravel-oci8` + `yajra/laravel-pdo-via-oci8`)
butuh extension PHP `oci8` yang jarang ada di mesin dev biasa, jadi malah
bikin `composer install` gagal. Yang saya pertahankan: **PHP `^7.1.3`** dan
**`laravel/framework: 5.8.*`**, karena itu yang menentukan kompatibilitas
package-nya.

Kalau kamu tetap mau app sample ini identik dengan `composer.json` IAS-PHP
(misal karena mau tes interaksi dengan `yajra/laravel-datatables-oracle` atau
`maatwebsite/excel`), tinggal bilang — saya tambahkan sesuai kebutuhan.
