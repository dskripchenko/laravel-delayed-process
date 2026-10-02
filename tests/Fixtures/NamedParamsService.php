<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Tests\Fixtures;

final class NamedParamsService
{
    public function handle(string $model): array
    {
        return ['model' => $model];
    }

    public function export(string $model, int $limit = 10, ?string $format = null): array
    {
        return ['model' => $model, 'limit' => $limit, 'format' => $format];
    }

    public function withDependency(string $model, ExportCounter $counter): array
    {
        return ['model' => $model, 'counter' => $counter->label()];
    }

    public function onlyDependency(ExportCounter $counter): array
    {
        return ['counter' => $counter->label()];
    }

    public function rows(array $ids, string $scope = 'all'): array
    {
        return ['ids' => $ids, 'scope' => $scope];
    }

    public function whole(array $params): array
    {
        return ['params' => $params];
    }

    public function variadic(string $prefix, string ...$rest): array
    {
        return ['prefix' => $prefix, 'rest' => $rest];
    }
}
