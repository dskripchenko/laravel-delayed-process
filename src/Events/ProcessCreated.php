<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Events;

use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Foundation\Events\Dispatchable;

final class ProcessCreated
{
    use Dispatchable;

    public function __construct(
        public readonly DelayedProcess $process,
    ) {}
}
