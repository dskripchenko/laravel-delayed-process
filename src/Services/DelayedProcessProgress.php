<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Services;

use Dskripchenko\DelayedProcess\Contracts\ProcessProgressInterface;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

final class DelayedProcessProgress implements ProcessProgressInterface
{
    private ?DelayedProcess $process = null;

    public function setProcess(DelayedProcess $process): void
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
