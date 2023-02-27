<?php

namespace Dskripchenko\DelayedProcess\Console\Commands;

use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
        $sql = <<<RAW_SQL
DELETE FROM delayed_processes
WHERE status NOT IN (:status_new, :status_wait)
    AND DATE(created_at) <= DATE(DATE_SUB(NOW(), INTERVAL :days DAY))
RAW_SQL;

        DB::statement($sql, [
            ':status_new' => DelayedProcess::STATUS_NEW,
            ':status_wait' => DelayedProcess::STATUS_WAIT,
            ':days' => $this->days
        ]);
    }
}
