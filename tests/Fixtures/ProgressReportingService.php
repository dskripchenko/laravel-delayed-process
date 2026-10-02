<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Tests\Fixtures;

use Dskripchenko\DelayedProcess\Contracts\ProcessProgressInterface;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Support\Facades\Log;

/**
 * A handler that reports progress the two ways a user handler can get hold
 * of the progress tracker: constructor injection and resolving it inside the
 * method. It returns what the database held right after each report.
 */
final class ProgressReportingService
{
    public function __construct(
        private readonly ProcessProgressInterface $progress,
    ) {}

    public function viaConstructor(string $uuid): array
    {
        $this->progress->setProgress(30);

        return ['seen' => self::stored($uuid)];
    }

    public function viaContainer(string $uuid): array
    {
        app(ProcessProgressInterface::class)->setProgress(60);

        return ['seen' => self::stored($uuid)];
    }

    public function thenFails(string $uuid): void
    {
        $this->progress->setProgress(40);

        throw new \RuntimeException('Failed after reporting progress');
    }

    public function logs(): void
    {
        Log::info('handler log line');
    }

    private static function stored(string $uuid): int
    {
        return (int) DelayedProcess::query()->where('uuid', $uuid)->value('progress');
    }
}
