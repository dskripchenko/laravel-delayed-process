<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Contracts;

use Dskripchenko\DelayedProcess\Models\DelayedProcess;

interface ProcessObserverInterface
{
    public function onCreated(DelayedProcess $process): void;

    public function onStarted(DelayedProcess $process): void;

    public function onCompleted(DelayedProcess $process): void;

    public function onFailed(DelayedProcess $process, \Throwable $exception): void;
}
