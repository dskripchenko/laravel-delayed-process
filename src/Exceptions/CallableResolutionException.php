<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Exceptions;

use RuntimeException;

final class CallableResolutionException extends RuntimeException
{
    public static function classNotFound(string $class): self
    {
        return new self("Entity class [{$class}] does not exist.");
    }

    public static function methodNotFound(string $class, string $method): self
    {
        return new self("Method [{$method}] does not exist on [{$class}].");
    }

    public static function notResolvable(string $entity, string $method): self
    {
        return new self("Cannot resolve callable from entity [{$entity}] and method [{$method}].");
    }
}
