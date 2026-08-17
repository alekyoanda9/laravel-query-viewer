<?php

namespace Sd1\QueryViewer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sd1\QueryViewer\Exceptions\QueryDebugException;
use Sd1\QueryViewer\Http\Concerns\RespondsWithJson;
use Sd1\QueryViewer\Services\TraceService;
use Sd1\QueryViewer\Support\Context;
use Sd1\QueryViewer\Support\TraceStore;

class TraceController extends Controller
{
    use RespondsWithJson;

    /** @var TraceService */
    private $service;

    public function __construct(TraceService $service)
    {
        $this->service = $service;
    }

    /**
     * Dipanggil panel (multipart) saat support menekan "Ambil Kasus".
     * payload = JSON (deskripsi, kategori, prpk, curated), files[] = lampiran.
     */
    public function capture(Request $request)
    {
        try {
            $payload = json_decode((string) $request->input('payload', ''), true);
            if (! is_array($payload)) {
                throw new QueryDebugException('Payload tidak valid.', 422);
            }

            // Kode dibuat lebih dulu supaya lampiran bisa langsung ditaruh di
            // folder milik trace ini sebelum file JSON-nya ditulis.
            $code        = TraceStore::newCode();
            $attachments = $this->storeAttachments($request, $code);

            $trace = $this->service->captureWithCode(
                $code,
                Context::identity(),
                $payload,
                $attachments
            );

            return $this->ok([
                'code'  => $trace['code'],
                'url'   => url(config('querydebug.route_prefix', 'dev/query-debug') . '/trace/' . $trace['code']),
                'steps' => count($trace['steps']),
            ], 'Trace tersimpan');
        } catch (QueryDebugException $e) {
            return $this->fail($e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * Validasi + simpan lampiran. Ditolak kalau melebihi jumlah, ukuran, atau
     * mime yang diizinkan — batasan yang sama dengan yang dicek panel, tapi di
     * sini yang MENENTUKAN (panel bisa di-bypass).
     */
    private function storeAttachments(Request $request, string $code): array
    {
        $files = $request->file('files', []);
        if (! is_array($files)) {
            $files = [$files];
        }
        $files = array_filter($files);

        if (empty($files)) {
            return [];
        }

        $maxCount = (int) config('querydebug.trace.max_attachments', 6);
        $maxKb    = (int) config('querydebug.trace.max_upload_kb', 5120);
        $allowed  = (array) config('querydebug.trace.allowed_upload_mime', []);

        if (count($files) > $maxCount) {
            throw new QueryDebugException('Maksimal ' . $maxCount . ' lampiran.', 422);
        }

        $out = [];
        $i   = 0;
        foreach ($files as $file) {
            if (! $file->isValid()) {
                throw new QueryDebugException('Ada lampiran yang gagal di-upload.', 422);
            }

            $mime = (string) $file->getMimeType();
            if (! empty($allowed) && ! in_array($mime, $allowed, true)) {
                throw new QueryDebugException('Tipe file "' . $mime . '" tidak diizinkan.', 422);
            }

            if ($file->getSize() > $maxKb * 1024) {
                throw new QueryDebugException('Ada lampiran melebihi ' . round($maxKb / 1024, 1) . ' MB.', 422);
            }

            $i++;
            $ext  = preg_replace('/[^a-z0-9]/i', '', (string) $file->guessExtension()) ?: 'bin';
            $name = 'lampiran-' . $i . '.' . $ext;

            $stored = TraceStore::putAttachment($code, $name, file_get_contents($file->getRealPath()));

            $out[] = [
                'type' => strpos($mime, 'video/') === 0 ? 'video' : 'image',
                'kind' => 'file',
                'src'  => $stored,       // path internal disk, disajikan lewat route serve
                'name' => $name,
                'idx'  => $i,
            ];
        }

        return $out;
    }

    public function show($code)
    {
        try {
            $trace = $this->service->show((string) $code);
        } catch (QueryDebugException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        return view('querydebug::trace', ['trace' => $trace]);
    }

    public function json($code)
    {
        try {
            return $this->ok($this->service->show((string) $code));
        } catch (QueryDebugException $e) {
            return $this->fail($e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * Menyajikan file lampiran. Divalidasi ketat: kode harus valid DAN path
     * yang diminta harus benar-benar salah satu attachment yang tercatat di
     * trace itu — mencegah path traversal atau menembak file lain di disk.
     */
    public function attachment($code, $idx)
    {
        try {
            $trace = $this->service->show((string) $code);
        } catch (QueryDebugException $e) {
            abort($e->getStatusCode());
        }

        $target = null;
        foreach ((isset($trace['attachments']) ? $trace['attachments'] : []) as $a) {
            if (isset($a['idx']) && (string) $a['idx'] === (string) $idx && ($a['kind'] ?? '') === 'file') {
                $target = $a;
                break;
            }
        }

        if ($target === null || ! TraceStore::attachmentExists($target['src'])) {
            abort(404);
        }

        $stream = TraceStore::attachmentStream($target['src']);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type'        => $target['type'] === 'video' ? 'video/mp4' : 'image/*',
            'Content-Disposition' => 'inline; filename="' . $target['name'] . '"',
        ]);
    }

    public function index()
    {
        return view('querydebug::traces', ['traces' => $this->service->recent(50)]);
    }

    /**
     * Hapus satu trace. Dipanggil dari tombol Hapus di halaman daftar maupun
     * halaman detail — keduanya form POST biasa (bukan fetch), jadi cukup
     * redirect back dengan flash message.
     */
    public function delete(Request $request, $code)
    {
        try {
            $this->service->delete((string) $code);

            return redirect($request->input('back') ?: $this->indexUrl())
                ->with('querydebug_status', 'Trace ' . $code . ' dihapus.');
        } catch (QueryDebugException $e) {
            return redirect($request->input('back') ?: $this->indexUrl())
                ->with('querydebug_error', $e->getMessage());
        }
    }

    /**
     * Bersihkan trace lebih lama dari N hari, dari form di halaman daftar.
     */
    public function prune(Request $request)
    {
        try {
            $days = (int) $request->input('days');
            $count = $this->service->prune($days);

            return redirect($this->indexUrl())
                ->with('querydebug_status', $count . ' trace lebih lama dari ' . $days . ' hari dihapus.');
        } catch (QueryDebugException $e) {
            return redirect($this->indexUrl())
                ->with('querydebug_error', $e->getMessage());
        }
    }

    private function indexUrl(): string
    {
        return url(config('querydebug.route_prefix', 'dev/query-debug') . '/trace');
    }
}