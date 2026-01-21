<?php

namespace Dskripchenko\DelayedProcess\Console\Commands;

use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Console\Command;

class ClearOldDelayedProcessCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'delayed:clear';

    /**
     * @var int
     */
    protected int $days = 30;

    /**
     * @var string
     */
    protected $description = 'Удаление старых отложенных процессов';

    public function handle(): void
    {
        DelayedProcess::query()
            ->whereNotIn('status', [DelayedProcess::STATUS_NEW, DelayedProcess::STATUS_WAIT])
            ->whereDate('created_at', '<=', now()->subDays($this->days))
            ->delete();
    }
}
