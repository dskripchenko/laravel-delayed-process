<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Jobs;

use Dskripchenko\DelayedProcess\Components\Events\Dispatcher;
use Dskripchenko\DelayedProcess\Contracts\ProcessLoggerInterface;
use Dskripchenko\DelayedProcess\Contracts\ProcessRunnerInterface;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class DelayedProcessJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout;
    public int $tries;

    /** @var int[] */
    public array $backoff;

    public function __construct(
        public readonly DelayedProcess $process,
    ) {
        $this->timeout = (int) config('delayed-process.job.timeout', 300);
        $this->tries = (int) config('delayed-process.job.tries', 1);
        $this->backoff = (array) config('delayed-process.job.backoff', [30, 60, 120]);
    }

    public function handle(ProcessRunnerInterface $runner, ProcessLoggerInterface $logger): void
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = app(Dispatcher::class);
        $originalDispatcher = Log::getEventDispatcher();
        Log::setEventDispatcher($dispatcher);
        $listenerIds = [];

        try {
            $logger->setProcess($this->process);

            $listenerIds = $dispatcher->listen(
                MessageLogged::class,
                function (MessageLogged $event) use ($logger): void {
                    $logger->log($event->level, $event->message, $event->context);
                },
            );

            $runner->run($this->process);
        } finally {
            $dispatcher->unlisten(MessageLogged::class, $listenerIds);
            Log::setEventDispatcher($originalDispatcher);
        }
    }
}
