<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Console\Commands;

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Console\Command;

final class ExpireProcessesCommand extends Command
{
    protected $signature = 'delayed:expire
        {--dry-run : Show expired count without modifying}';

    protected $description = 'Mark expired delayed processes as expired';

    public function handle(): int
    {
        $query = DelayedProcess::query()->whereExpired();

        if ($this->option('dry-run')) {
            $count = $query->count();
            $this->info("Would expire {$count} process(es). (dry-run)");

            return self::SUCCESS;
        }

        $affected = $query->update(['status' => ProcessStatus::Expired->value]);

        $this->info("Expired {$affected} process(es).");

        return self::SUCCESS;
    }
}
