<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Services\EntityConfigResolver;
use Dskripchenko\DelayedProcess\Tests\Fixtures\AllowedService;

it('allows entity listed as string value', function (): void {
    config()->set('delayed-process.allowed_entities', [
        AllowedService::class,
    ]);

    $resolver = new EntityConfigResolver();

    expect($resolver->isAllowed(AllowedService::class))->toBeTrue();
});

it('allows entity listed as keyed array', function (): void {
    config()->set('delayed-process.allowed_entities', [
        AllowedService::class => ['queue' => 'heavy'],
    ]);

    $resolver = new EntityConfigResolver();

    expect($resolver->isAllowed(AllowedService::class))->toBeTrue();
});

it('allows entity in mixed format', function (): void {
    config()->set('delayed-process.allowed_entities', [
        'App\\Other',
        AllowedService::class => ['queue' => 'default'],
    ]);

    $resolver = new EntityConfigResolver();

    expect($resolver->isAllowed(AllowedService::class))->toBeTrue()
        ->and($resolver->isAllowed('App\\Other'))->toBeTrue();
});

it('rejects entity not in allowlist', function (): void {
    config()->set('delayed-process.allowed_entities', [
        AllowedService::class,
    ]);

    $resolver = new EntityConfigResolver();

    expect($resolver->isAllowed('App\\NotAllowed'))->toBeFalse();
});

it('returns entity config for keyed entry', function (): void {
    config()->set('delayed-process.allowed_entities', [
        AllowedService::class => ['queue' => 'heavy', 'connection' => 'redis', 'timeout' => 600],
    ]);

    $resolver = new EntityConfigResolver();
    $entityConfig = $resolver->getEntityConfig(AllowedService::class);

    expect($entityConfig)->toBe(['queue' => 'heavy', 'connection' => 'redis', 'timeout' => 600]);
});

it('returns empty config for string-listed entity', function (): void {
    config()->set('delayed-process.allowed_entities', [
        AllowedService::class,
    ]);

    $resolver = new EntityConfigResolver();

    expect($resolver->getEntityConfig(AllowedService::class))->toBe([]);
});
