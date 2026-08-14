<?php

namespace Sd1\QueryViewer\Support;

/**
 * Penampung query untuk satu request. Diinstansiasi lokal di middleware
 * (satu instance per request), lalu isinya di-flush ke QueryDebugStore
 * setelah request selesai.
 *
 * TIDAK BERUBAH dari versi sebelumnya — disertakan supaya bundle-nya lengkap.
 */
class QueryCollector
{
    /** @var array<int,array> */
    private $queries = [];

    public function record(array $entry): void
    {
        $this->queries[] = $entry;
    }

    /** @return array<int,array> */
    public function all(): array
    {
        return $this->queries;
    }

    public function count(): int
    {
        return count($this->queries);
    }
}
