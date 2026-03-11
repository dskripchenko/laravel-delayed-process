<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Contracts;

interface ProcessProgressInterface
{
    public function setProgress(int $percent): void;
}
