# Peta perubahan: dari modul in-app → package

Ringkasan apa yang berubah saat modul in-app IAS-PHP dijadikan package, supaya
kamu bisa mencocokkan dengan versi lamamu.

## Pindah tanpa perubahan logika (hanya namespace)

| Lama (in-app)                                   | Package                                 |
|-------------------------------------------------|-----------------------------------------|
| `App\Support\QueryDebug\QueryCollector`         | `Sd1\QueryViewer\Support\QueryCollector`   |
| `App\Support\QueryDebug\QueryDebugSql`          | `Sd1\QueryViewer\Support\QueryDebugSql`    |
| `App\Support\QueryDebug\QueryDebugStore`        | `Sd1\QueryViewer\Support\QueryDebugStore`  |
| `App\Support\QueryDebug\QueryDebugInsight`      | `Sd1\QueryViewer\Support\QueryDebugInsight`|

## Di-decouple (logika inti sama, ketergantungan app dilepas)

| Lama                                            | Perubahan                                                                 |
|-------------------------------------------------|---------------------------------------------------------------------------|
| `LogQueryDebug` (baca `session('connection'/'usid'/'kdigr')`) | Sekarang lewat `Context::connectionName()/identity()/ticketMeta()` |
| `QueryDebugController extends BaseController`    | Extends `Illuminate\Routing\Controller` + trait `RespondsWithJson`         |
| `QueryDebugService` throw `AppException`         | Throw `Sd1\QueryViewer\Exceptions\QueryDebugException`, ditangkap di controller |
| `QueryDebugRepository extends BaseRepository`    | Resolve koneksi sendiri via `Context::connectionName()`                    |
| Payload batch `kdigr` + `extra` (khas IAS)      | Digeneralkan jadi `context` (list label/value) + `conn`                    |

## Baru (khusus package)

- `QueryViewer` — API `connectionUsing/identifyUsing/contextUsing/activeUsing`.
- `Support\Context` — registrar + default; satu-satunya titik perbedaan antar-app.
- `Http\Concerns\RespondsWithJson` — envelope `{data,message}` sendiri.
- `Exceptions\QueryDebugException` — pengganti `AppException`.
- `Http\Middleware\VerifyQueryDebugKey` — gate host+key (dulu `querydebug.gate`
  didaftarkan manual di app; sekarang otomatis oleh service provider).
- `Http\Middleware\InjectQueryViewer` — suntik panel otomatis (dulu `@include`
  manual di layout).
- `QueryViewerServiceProvider` — daftar config/route/view/middleware + publish.

## Yang TIDAK lagi kamu lakukan manual di app

- Daftar route `dev/query-debug/*` → otomatis dari package.
- Daftar alias `querydebug.gate` → otomatis.
- Push `LogQueryDebug` ke grup `web` di `Kernel.php` → otomatis.
- `@include` panel di layout → otomatis (kecuali `auto_inject=false`).

## Yang tetap perlu kamu lakukan di IAS-PHP

- `composer require --dev`, publish config + assets.
- Isi `.env`.
- Daftarkan 3 closure di `AppServiceProvider::boot()` (connection/identity/
  context) — lihat README §3. Ini yang menggantikan pembacaan session yang dulu
  hardcode di middleware.
