<?php

namespace Dskripchenko\DelayedProcess\Jobs;

use Dskripchenko\DelayedProcess\Components\Events\Dispatcher;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use ReflectionException;


class DelayedProcessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var DelayedProcess
     */
    public DelayedProcess $process;


    /**
     * @param DelayedProcess $process
     */
    public function __construct(DelayedProcess $process)
    {
        $this->process = $process;
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    public function handle(): void
    {
        /**
         * @var Dispatcher $dispatcher
         */
        $dispatcher = app(Dispatcher::class);
        $originalDispatcher = Log::getEventDispatcher();
        Log::setEventDispatcher($dispatcher);

        $listenerIds = $dispatcher->listen(MessageLogged::class, function (MessageLogged $event){
            $this->process->log($event->level, $event->message, $event->context);
        });

        $this->process->run();

        $dispatcher->unlisten(MessageLogged::class, $listenerIds);

        Log::setEventDispatcher($originalDispatcher);
    }
}
