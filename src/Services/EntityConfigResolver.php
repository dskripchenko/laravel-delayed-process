<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Services;

final class EntityConfigResolver
{
    public function isAllowed(string $entity): bool
    {
        /** @var array<int|string, string|array> $allowed */
        $allowed = config('delayed-process.allowed_entities', []);

        foreach ($allowed as $key => $value) {
            if (is_int($key) && $value === $entity) {
                return true;
            }

            if (is_string($key) && $key === $entity) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{queue?: string, connection?: string, timeout?: int}
     */
    public function getEntityConfig(string $entity): array
    {
        /** @var array<int|string, string|array> $allowed */
        $allowed = config('delayed-process.allowed_entities', []);

        foreach ($allowed as $key => $value) {
            if (is_string($key) && $key === $entity && is_array($value)) {
                return $value;
            }
        }

        return [];
    }
}
