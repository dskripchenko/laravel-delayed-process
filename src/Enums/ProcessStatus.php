<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Enums;

enum ProcessStatus: string
{
    case New = 'new';
    case Wait = 'wait';
    case Done = 'done';
    case Error = 'error';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function canTransitionToWait(): bool
    {
        return $this === self::New;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Done,
            self::Error,
            self::Expired,
            self::Cancelled,
        ], true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    public function isCancellable(): bool
    {
        return $this === self::New || $this === self::Wait;
    }
}
