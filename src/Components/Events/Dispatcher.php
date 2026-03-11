<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Components\Events;

use Closure;
use Illuminate\Events\QueuedClosure;
use Illuminate\Support\Str;

final class Dispatcher extends \Illuminate\Events\Dispatcher
{
    /**
     * @param  string|Closure|QueuedClosure  $events
     * @param  mixed  $listener
     * @return string[]
     */
    public function listen($events, $listener = null): array
    {
        if ($events instanceof Closure) {
            return collect($this->firstClosureParameterTypes($events))
                ->flatMap(fn (string $event): array => $this->listen($event, $events))
                ->all();
        }

        if ($events instanceof QueuedClosure) {
            return collect($this->firstClosureParameterTypes($events->closure))
                ->flatMap(fn (string $event): array => $this->listen($event, $events->resolve()))
                ->all();
        }

        if ($listener instanceof QueuedClosure) {
            $listener = $listener->resolve();
        }

        $listenerIds = [];

        foreach ((array) $events as $event) {
            if (str_contains($event, '*')) {
                $this->setupWildcardListen($event, $listener);
            } else {
                $listenerId = Str::uuid7()->toString();
                $listenerIds[] = $listenerId;

                if (! method_exists($this, 'prepareListeners')) {
                    $listener = $this->makeListener($listener);
                }

                $this->listeners[$event][$listenerId] = $listener;
            }
        }

        return $listenerIds;
    }

    /**
     * @param  string[]  $ids
     */
    public function unlisten(string $event, array $ids = []): void
    {
        foreach ($ids as $id) {
            unset($this->listeners[$event][$id]);
        }
    }
}
