<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Tests\Fixtures;

use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * An application's own job — for instance one that carries the client's request context.
 */
final class ContextCarryingJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 60;

    public function __construct(public readonly DelayedProcess $process)
    {
    }

    public function handle(): void
    {
    }
}
