<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Services;

use Dskripchenko\DelayedProcess\Contracts\ProcessProgressInterface;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

/**
 * Writes the progress of the running process.
 *
 * The service provider binds it as a scoped instance, so the runner and the
 * handler it calls share one tracker: the runner attaches the process before
 * the handler starts and detaches it once the run is over. Outside a run,
 * setProgress() does nothing.
 */
final class DelayedProcessProgress implements ProcessProgressInterface
{
    private ?DelayedProcess $process = null;

    public function setProcess(?DelayedProcess $process): void
    {
        $this->process = $process;
    }

    public function setProgress(int $percent): void
    {
        if ($this->process === null) {
            return;
        }

        $this->process->progress = max(0, min(100, $percent));
        $this->process->save();
    }
}
