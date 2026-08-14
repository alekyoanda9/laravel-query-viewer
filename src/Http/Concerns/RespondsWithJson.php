<?php

namespace Sd1\QueryViewer\Http\Concerns;

/**
 * Envelope response { data, message } — dibawa package sendiri supaya tidak
 * bergantung pada ApiResponseTrait milik aplikasi tertentu. Bentuknya harus
 * tetap { data, ... } karena panel JS membaca payload dari json.data.
 */
trait RespondsWithJson
{
    protected function ok($data = null, string $message = 'OK', int $code = 200)
    {
        return response()->json([
            'data'    => $data,
            'message' => $message,
        ], $code);
    }

    protected function fail(string $message, int $code = 400)
    {
        return response()->json([
            'data'    => null,
            'message' => $message,
        ], $code);
    }
}
