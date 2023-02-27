<?php

namespace Dskripchenko\DelayedProcess\Console\Commands;

use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Console\Command;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

class DelayedProcessCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'delayed:process';

    /**
     * @var string
     */
    protected $description = 'Потоковая обработка отложенных процессов';

    public function handle(): void
    {
        /**
         * @var DelayedProcess|null $activeProcess
         */
        $activeProcess = null;

        Log::listen(function (MessageLogged $event) use (&$activeProcess){
            if ($activeProcess) {
                $activeProcess->log($event->level, $event->message, $event->context);
            };
        });

        $query = DelayedProcess::query()
            ->where('status', DelayedProcess::STATUS_NEW)
            ->orderBy('try', 'asc');
        /**
         * @var DelayedProcess|null $process
         */
        while ($process = $query->first()) {
            $activeProcess = $process;
            $process->run();
        }
    }
}
