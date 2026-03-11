<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Contracts;

use Dskripchenko\DelayedProcess\Models\DelayedProcess;

interface ProcessFactoryInterface
{
    public function make(string $entity, string $method, mixed ...$parameters): DelayedProcess;
}
