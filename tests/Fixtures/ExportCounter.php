<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Tests\Fixtures;

final class ExportCounter
{
    public function label(): string
    {
        return 'from-container';
    }
}
