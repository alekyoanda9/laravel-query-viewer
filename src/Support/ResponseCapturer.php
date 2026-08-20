<?php

namespace Sd1\QueryViewer\Support;

/**
 * Menangkap RESPONSE sebuah request jadi bentuk aman-simpan untuk dashboard
 * (/viewer) & trace. SATU tempat semua rambu §3.4 supaya mudah diaudit:
 *
 *  - download/cetak (BinaryFileResponse/StreamedResponse) -> METADATA saja,
 *    body TIDAK pernah dibaca (paling sering berat & tak ada gunanya di viewer);
 *  - hanya application/json & text/* yang di-tangkap body-nya;
 *  - dibatasi ukuran (response.max_kb) -> lebih dari itu dipotong + truncated;
 *  - JSON dilewatkan redaksi yang SAMA dengan input (StepRedactor) sebelum
 *    disimpan — sensitivitas response bisa lebih tinggi dari request.
 *
 * Mengembalikan array siap-simpan atau null (tidak ada yang perlu/boleh
 * ditangkap).
 */
class ResponseCapturer
{
    /**
     * @param  mixed  $response  hasil $next($request)
     * @return array|null {content_type,status,kind,body?,truncated?,size,filename?}
     */
    public static function capture($response)
    {
        if (! (bool) config('querydebug.response.enabled', true)) {
            return null;
        }

        if (! is_object($response) || ! method_exists($response, 'headers')) {
            // Bukan Symfony Response (mis. string mentah) -> lewati saja.
        }

        $status      = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : null;
        $contentType = self::contentType($response);

        // --- download / stream / binary: METADATA saja, jangan baca body -----
        if (self::isBinaryOrStream($response)) {
            return [
                'content_type' => $contentType,
                'status'       => $status,
                'kind'         => 'binary',
                'size'         => self::declaredSize($response),
                'filename'     => self::filename($response),
                'body'         => null,
                'truncated'    => false,
            ];
        }

        // --- hanya json / text/* yang body-nya ditangkap ---------------------
        if (! self::isCapturableType($contentType)) {
            return [
                'content_type' => $contentType,
                'status'       => $status,
                'kind'         => 'skipped',
                'size'         => self::declaredSize($response),
                'body'         => null,
                'truncated'    => false,
            ];
        }

        $maxBytes = max(1, (int) config('querydebug.response.max_kb', 64)) * 1024;

        try {
            $content = method_exists($response, 'getContent') ? (string) $response->getContent() : '';
        } catch (\Throwable $e) {
            return [
                'content_type' => $contentType,
                'status'       => $status,
                'kind'         => 'skipped',
                'size'         => null,
                'body'         => null,
                'truncated'    => false,
            ];
        }

        $size = strlen($content);

        // Redaksi JSON (kalau body memang JSON valid) lewat StepRedactor yang
        // sama dengan input. Untuk text/* biasa tidak ada redaksi berbasis key.
        $isJson = stripos((string) $contentType, 'json') !== false;
        if ($isJson) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $redacted = StepRedactor::input($decoded);
                $content  = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                $content  = $content === false ? '' : $content;
            }
        }

        $truncated = false;
        if (strlen($content) > $maxBytes) {
            $content   = substr($content, 0, $maxBytes);
            $truncated = true;
        }

        return [
            'content_type' => $contentType,
            'status'       => $status,
            'kind'         => 'body',
            'body'         => $content,
            'truncated'    => $truncated,
            'size'         => $size, // ukuran ASLI sebelum dipotong
        ];
    }

    /**
     * Ringkasan ringan untuk dikirim di /recent (TANPA body) — supaya dashboard
     * tahu ada response + tipe/ukuran/status, lalu mengambil body-nya lazy.
     */
    public static function meta(array $response): array
    {
        return [
            'content_type' => isset($response['content_type']) ? $response['content_type'] : null,
            'status'       => isset($response['status']) ? $response['status'] : null,
            'kind'         => isset($response['kind']) ? $response['kind'] : null,
            'size'         => isset($response['size']) ? $response['size'] : null,
            'truncated'    => ! empty($response['truncated']),
            'filename'     => isset($response['filename']) ? $response['filename'] : null,
            'has_body'     => isset($response['body']) && $response['body'] !== null && $response['body'] !== '',
        ];
    }

    private static function contentType($response)
    {
        if (is_object($response) && isset($response->headers) && method_exists($response->headers, 'get')) {
            $ct = $response->headers->get('Content-Type');

            return $ct !== null && $ct !== '' ? $ct : null;
        }

        return null;
    }

    private static function isBinaryOrStream($response): bool
    {
        return $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            || $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse;
    }

    private static function isCapturableType($contentType): bool
    {
        if (! is_string($contentType) || $contentType === '') {
            return false;
        }

        $ct = strtolower($contentType);
        foreach ((array) config('querydebug.response.capture_types', []) as $needle) {
            $needle = strtolower((string) $needle);
            // Cocok sebagai AWALAN: 'text/' -> text/*, 'application/json' ->
            // "application/json; charset=utf-8". Cukup & predictable.
            if ($needle !== '' && strpos($ct, $needle) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function declaredSize($response)
    {
        if (is_object($response) && isset($response->headers) && method_exists($response->headers, 'get')) {
            $len = $response->headers->get('Content-Length');
            if ($len !== null && $len !== '') {
                return (int) $len;
            }
        }

        return null;
    }

    private static function filename($response)
    {
        if ($response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            try {
                $file = $response->getFile();

                return $file ? $file->getFilename() : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Coba dari header Content-Disposition.
        if (is_object($response) && isset($response->headers) && method_exists($response->headers, 'get')) {
            $cd = $response->headers->get('Content-Disposition');
            if (is_string($cd) && preg_match('/filename="?([^"]+)"?/i', $cd, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
