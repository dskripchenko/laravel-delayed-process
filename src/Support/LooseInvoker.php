<?php

/*
 * This file deliberately has NO `declare(strict_types=1)`.
 *
 * PHP applies the strict typing mode of the file a call is MADE from. The
 * runner is a strict file, so a handler `build(int $id)` called from it with
 * the string "42" — what a JSON body or a form usually carries — failed with a
 * TypeError, while 1.x, which called handlers from a non-strict model, coerced
 * the value. The call is made from here instead: values are coerced the way
 * PHP coerces scalar arguments in its default mode, and correctly typed values
 * pass exactly as before.
 */

namespace Dskripchenko\DelayedProcess\Support;

final class LooseInvoker
{
    /**
     * @param  array<int|string, mixed>  $arguments  positional or named (string keys)
     */
    public static function invoke(callable $callable, array $arguments): mixed
    {
        return $callable(...$arguments);
    }
}
