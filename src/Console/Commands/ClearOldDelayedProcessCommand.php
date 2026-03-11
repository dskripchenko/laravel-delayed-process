<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Console\Commands;

use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Console\Command;

final class ClearOldDelayedProcessCommand extends Command
{
    protected $signature = 'delayed:clear
        {--days=0 : Days to keep (0 = use config)}
        {--chunk=0 : Chunk size (0 = use config)}';

    protected $description = 'Remove old terminal delayed processes';

    public function handle(): int
    {
        $days = (int) $this->option('days')
            ?: (int) config('delayed-process.clear_after_days', 30);

        $chunk = (int) $this->option('chunk')
            ?: (int) config('delayed-process.clear_chunk_size', 500);

        $total = 0;

        DelayedProcess::query()
            ->whereTerminal()
            ->olderThanDays($days)
            ->chunkById($chunk, function ($processes) use (&$total): void {
                $ids = $processes->pluck('id');
                DelayedProcess::query()->whereIn('id', $ids)->delete();
                $total += $ids->count();
            });

        $this->info("Cleared {$total} process(es) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
