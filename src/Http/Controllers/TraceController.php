<?php

namespace Sd1\QueryViewer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sd1\QueryViewer\Exceptions\QueryDebugException;
use Sd1\QueryViewer\Http\Concerns\RespondsWithJson;
use Sd1\QueryViewer\Services\TraceService;
use Sd1\QueryViewer\Support\Context;

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
     * Dipanggil panel saat support menekan "Ambil Kasus Ini".
     */
    public function capture(Request $request)
    {
        try {
            $trace = $this->service->capture(
                Context::identity(),
                trim((string) $request->input('note', '')),
                (int) $request->input('limit', 0),
                (int) $request->input('suspect', -1)
            );

            return $this->ok([
                'code' => $trace['code'],
                'url'  => url(config('querydebug.route_prefix', 'dev/query-debug') . '/trace/' . $trace['code']),
                'steps' => count($trace['steps']),
            ], 'Trace tersimpan');
        } catch (QueryDebugException $e) {
            return $this->fail($e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * Halaman timeline untuk dev. Ini yang dibuka dari kode trace.
     */
    public function show($code)
    {
        try {
            $trace = $this->service->show((string) $code);
        } catch (QueryDebugException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        return view('querydebug::trace', ['trace' => $trace]);
    }

    /**
     * Bentuk JSON — untuk dilampirkan ke tiket PRPK/memo, atau diproses lagi.
     */
    public function json($code)
    {
        try {
            return $this->ok($this->service->show((string) $code));
        } catch (QueryDebugException $e) {
            return $this->fail($e->getMessage(), $e->getStatusCode());
        }
    }

    public function index()
    {
        return view('querydebug::traces', ['traces' => $this->service->recent(50)]);
    }
}
