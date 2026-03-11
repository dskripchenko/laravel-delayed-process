<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Contracts;

use Dskripchenko\DelayedProcess\Models\DelayedProcess;

interface ProcessLoggerInterface
{
    public function setProcess(DelayedProcess $process): void;

    public function log(string $level, string $message, array $context = []): void;

    public function flush(): void;
}
