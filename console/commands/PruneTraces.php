<?php

namespace Sd1\QueryViewer\Console\Commands;

use Illuminate\Console\Command;
use Sd1\QueryViewer\Services\TraceService;

/**
 * Versi command-line dari form "bersihkan lebih lama dari N hari" di halaman
 * daftar trace. Package ini TIDAK mendaftarkan schedule apa pun sendiri —
 * kalau mau otomatis, tambahkan baris berikut di App\Console\Kernel::schedule():
 *
 *   $schedule->command('querydebug:prune-traces')->weekly();
 */
class PruneTraces extends Command
{
    protected $signature = 'querydebug:prune-traces {--days= : Hapus trace lebih tua dari N hari (default: config querydebug.trace.retention_days)}';

    protected $description = 'Hapus trace (perekam langkah support) yang lebih lama dari N hari';

    public function handle(TraceService $service): int
    {
        $days = $this->option('days');
        $days = $days !== null ? (int) $days : (int) config('querydebug.trace.retention_days', 90);

        if ($days < 1) {
            $this->error('Jumlah hari tidak valid: ' . $days);

            return self::FAILURE;
        }

        $count = $service->prune($days);

        $this->info($count . ' trace lebih lama dari ' . $days . ' hari dihapus.');

        return self::SUCCESS;
    }
}