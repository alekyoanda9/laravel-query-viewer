<?php

namespace Sd1\QueryViewer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sd1\QueryViewer\Exceptions\QueryDebugException;
use Sd1\QueryViewer\Http\Concerns\RespondsWithJson;
use Sd1\QueryViewer\Services\QueryDebugService;
use Sd1\QueryViewer\Support\Context;
use Sd1\QueryViewer\Support\QueryDebugStore;

/**
 * Extends base controller framework (bukan BaseController aplikasi) dan
 * membawa response JSON + penanganan error-nya sendiri, jadi package tidak
 * bergantung pada trait/Handler milik app tempat ia dipasang.
 */
class QueryDebugController extends Controller
{
    use RespondsWithJson;

    /** @var QueryDebugService */
    private $service;

    public function __construct(QueryDebugService $service)
    {
        $this->service = $service;
    }

    public function recent()
    {
        return $this->ok($this->service->recent(Context::identity()));
    }

    public function clear()
    {
        $this->service->clear(Context::identity());

        return $this->ok(null, 'Cleared');
    }

    public function explain(Request $request)
    {
        try {
            $result = $this->service->explain(
                Context::identity(),
                (int) $request->input('batch'),
                (int) $request->input('query'),
                (string) $request->input('id'),
                filter_var($request->input('analyze'), FILTER_VALIDATE_BOOLEAN)
            );

            return $this->ok($result);
        } catch (QueryDebugException $e) {
            return $this->fail($e->getMessage(), $e->getStatusCode());
        }
    }

    public function unlock()
    {
        Context::markActive();

        return $this->ok([
            'insight_enabled' => (bool) config('querydebug.insight.enabled', false),
        ], 'Unlocked');
    }

    public function lock()
    {
        Context::markInactive();
        QueryDebugStore::clearFor(Context::identity());

        return $this->ok(null, 'Locked');
    }
}
