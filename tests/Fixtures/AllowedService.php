<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Tests\Fixtures;

use Dskripchenko\DelayedProcess\Contracts\ProcessProgressInterface;

final class AllowedService
{
    public function handle(string $input = 'default'): array
    {
        return ['result' => $input];
    }

    public function failing(): void
    {
        throw new \RuntimeException('Intentional test failure');
    }

    public function withProgress(): array
    {
        $progress = app(ProcessProgressInterface::class);
        $progress->setProgress(50);

        return ['progress' => 'done'];
    }

    public function longErrorMessage(): void
    {
        throw new \RuntimeException(str_repeat('E', 2000));
    }

    public function returnsNull(): void
    {
        // Returns null (void)
    }

    public function returnsScalar(): string
    {
        return 'scalar-value';
    }

    public function withAssocParams(array $params): array
    {
        return $params;
    }
}
