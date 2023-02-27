<?php

namespace Dskripchenko\DelayedProcess\Components\Events;

use Closure;
use Illuminate\Events\QueuedClosure;
use Illuminate\Support\Str;
use ReflectionException;

class Dispatcher extends \Illuminate\Events\Dispatcher
{
    /**
     * @param $events
     * @param $listener
     * @return array
     * @throws ReflectionException
     */
    public function listen($events, $listener = null): array
    {
        if ($events instanceof Closure) {
            return collect($this->firstClosureParameterTypes($events))
                ->each(function ($event) use ($events) {
                    return $this->listen($event, $events);
                })->flatten()->toArray();
        }

        if ($events instanceof QueuedClosure) {
            return collect($this->firstClosureParameterTypes($events->closure))
                ->each(function ($event) use ($events) {
                    return $this->listen($event, $events->resolve());
                })->flatten()->toArray();
        }

        if ($listener instanceof QueuedClosure) {
            $listener = $listener->resolve();
        }

        $listenerIds = [];

        foreach ((array) $events as $event) {
            if (Str::contains($event, '*')) {
                $this->setupWildcardListen($event, $listener);
            } else {
                $listenerId = uniqid("listener_id_", true);
                $listenerIds[] = $listenerId;
                $this->listeners[$event][$listenerId] = $this->makeListener($listener);
            }
        }

        return $listenerIds;
    }

    /**
     * @param string $event
     * @param array $ids
     * @return void
     */
    public function unlisten(string $event, array $ids = []): void
    {
        foreach ($ids as $id) {
            if (isset($this->listeners[$event][$id])) {
                unset($this->listeners[$event][$id]);
            }
        }
    }
}
