<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Services;

use Dskripchenko\DelayedProcess\Exceptions\CallableResolutionException;
use Dskripchenko\DelayedProcess\Exceptions\EntityNotAllowedException;

final class CallableResolver
{
    public function __construct(
        private readonly EntityConfigResolver $entityConfigResolver = new EntityConfigResolver(),
    ) {}
    /**
     * Resolve an entity+method pair into a validated callable.
     *
     * @return callable
     */
    public function resolve(string $entity, string $method): callable
    {
        $this->assertAllowed($entity);
        $this->assertClassExists($entity);
        $this->assertMethodExists($entity, $method);

        $instance = app($entity);

        return [$instance, $method];
    }

    /**
     * Validate entity+method without instantiating (used before DB insert).
     */
    public function validate(string $entity, string $method): void
    {
        $this->assertAllowed($entity);
        $this->assertClassExists($entity);
        $this->assertMethodExists($entity, $method);
    }

    private function assertAllowed(string $entity): void
    {
        if (! $this->entityConfigResolver->isAllowed($entity)) {
            throw EntityNotAllowedException::forClass($entity);
        }
    }

    private function assertClassExists(string $entity): void
    {
        if (! class_exists($entity)) {
            throw CallableResolutionException::classNotFound($entity);
        }
    }

    private function assertMethodExists(string $entity, string $method): void
    {
        if (! method_exists($entity, $method)) {
            throw CallableResolutionException::methodNotFound($entity, $method);
        }
    }
}
