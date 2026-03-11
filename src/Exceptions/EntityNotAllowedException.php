<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Exceptions;

use RuntimeException;

final class EntityNotAllowedException extends RuntimeException
{
    public static function forClass(string $class): self
    {
        return new self("Entity [{$class}] is not in the allowed_entities list.");
    }
}
