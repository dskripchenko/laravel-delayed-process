<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Console\Commands;

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Console\Command;

final class UnstuckProcessesCommand extends Command
{
    protected $signature = 'delayed:unstuck
        {--timeout=0 : Stuck timeout in minutes (0 = use config)}
        {--dry-run : Show stuck processes without resetting}';

    protected $description = 'Reset stuck wait-processes back to new';

    public function handle(): int
    {
        $timeout = (int) $this->option('timeout')
            ?: (int) config('delayed-process.stuck_timeout_minutes', 60);

        $dryRun = (bool) $this->option('dry-run');

        $query = DelayedProcess::query()->whereStuck($timeout);
        $count = $query->count();

        if ($count === 0) {
            $this->info('No stuck processes found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("Found {$count} stuck process(es) (dry-run, no changes made).");

            $query->each(function (DelayedProcess $p): void {
                $this->line("  - [{$p->uuid}] stuck since {$p->updated_at}");
            });

            return self::SUCCESS;
        }

        $affected = $query->update([
            'status' => ProcessStatus::New->value,
        ]);

        $this->info("Reset {$affected} stuck process(es) back to new.");

        return self::SUCCESS;
    }
}
