<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Tests\Fixtures;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Handlers written for 1.x: scalar parameters typed strictly, API resources as results.
 */
final class LegacyHandlers
{
    public function typed(int $id, string $code, bool $flag): array
    {
        return ['id' => $id, 'code' => $code, 'flag' => $flag];
    }

    public function resource(): JsonResource
    {
        return new JsonResource(['paymentLink' => 'https://pay.example/1']);
    }

    public function collection(): Collection
    {
        return collect(['a' => 1]);
    }
}
