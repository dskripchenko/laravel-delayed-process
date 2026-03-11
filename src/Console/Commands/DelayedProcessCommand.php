<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Console\Commands;

use Dskripchenko\DelayedProcess\Components\Events\Dispatcher;
use Dskripchenko\DelayedProcess\Contracts\ProcessLoggerInterface;
use Dskripchenko\DelayedProcess\Contracts\ProcessRunnerInterface;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Console\Command;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

final class DelayedProcessCommand extends Command
{
    protected $signature = 'delayed:process
        {--max-iterations=0 : Maximum iterations (0 = infinite)}
        {--sleep=0 : Seconds to sleep when no processes found}';

    protected $description = 'Process delayed processes synchronously';

    public function handle(ProcessRunnerInterface $runner, ProcessLoggerInterface $logger): int
    {
        $maxIterations = (int) $this->option('max-iterations')
            ?: (int) config('delayed-process.command.max_iterations', 0);

        $sleep = (int) $this->option('sleep')
            ?: (int) config('delayed-process.command.sleep', 5);

        $throttle = (int) config('delayed-process.command.throttle', 100_000);

        /** @var Dispatcher $dispatcher */
        $dispatcher = app(Dispatcher::class);
        $originalDispatcher = Log::getEventDispatcher();
        Log::setEventDispatcher($dispatcher);
        $listenerIds = [];

        try {
            $listenerIds = $dispatcher->listen(
                MessageLogged::class,
                function (MessageLogged $event) use ($logger): void {
                    $logger->log($event->level, $event->message, $event->context);
                },
            );

            $iterations = 0;

            while ($maxIterations === 0 || $iterations < $maxIterations) {
                $process = DelayedProcess::query()
                    ->whereNew()
                    ->orderBy('try', 'asc')
                    ->orderBy('id', 'asc')
                    ->first();

                if ($process === null) {
                    if ($maxIterations > 0) {
                        break;
                    }

                    $this->info('No processes found, sleeping...');
                    sleep($sleep);

                    continue;
                }

                $logger->setProcess($process);
                $this->info("Processing [{$process->uuid}] (try {$process->try})...");

                $runner->run($process);

                $iterations++;
                usleep($throttle);
            }
        } finally {
            $dispatcher->unlisten(MessageLogged::class, $listenerIds);
            Log::setEventDispatcher($originalDispatcher);
        }

        $this->info("Done. Processed {$iterations} item(s).");

        return self::SUCCESS;
    }
}
