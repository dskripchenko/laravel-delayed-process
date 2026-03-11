<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Exceptions;

final class InvalidParametersException extends \RuntimeException
{
    public static function notSerializable(): self
    {
        return new self('Parameters are not JSON-serializable.');
    }
}
