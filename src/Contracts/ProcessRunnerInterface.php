<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Contracts;

use Dskripchenko\DelayedProcess\Models\DelayedProcess;

interface ProcessRunnerInterface
{
    public function run(DelayedProcess $process): void;
}
