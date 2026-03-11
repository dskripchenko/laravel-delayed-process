<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Exceptions\CallableResolutionException;
use Dskripchenko\DelayedProcess\Exceptions\EntityNotAllowedException;
use Dskripchenko\DelayedProcess\Services\CallableResolver;
use Dskripchenko\DelayedProcess\Tests\Fixtures\AllowedService;

beforeEach(function (): void {
    config()->set('delayed-process.allowed_entities', [
        AllowedService::class,
    ]);
});

it('rejects entity not in allowlist', function (): void {
    $resolver = new CallableResolver();

    $resolver->resolve('App\\NotAllowed', 'handle');
})->throws(EntityNotAllowedException::class);

it('rejects non-existent class', function (): void {
    config()->set('delayed-process.allowed_entities', ['NonExistentClass']);
    $resolver = new CallableResolver();

    $resolver->resolve('NonExistentClass', 'handle');
})->throws(CallableResolutionException::class, 'does not exist');

it('rejects non-existent method', function (): void {
    $resolver = new CallableResolver();

    $resolver->resolve(AllowedService::class, 'nonExistentMethod');
})->throws(CallableResolutionException::class, 'does not exist on');

it('resolves valid entity and method', function (): void {
    $resolver = new CallableResolver();

    $callable = $resolver->resolve(AllowedService::class, 'handle');

    expect($callable)->toBeArray()
        ->and($callable[0])->toBeInstanceOf(AllowedService::class)
        ->and($callable[1])->toBe('handle');
});

it('validates without instantiation', function (): void {
    $resolver = new CallableResolver();

    $resolver->validate(AllowedService::class, 'handle');

    // Should not throw
    expect(true)->toBeTrue();
});

it('validate rejects not-allowed entity', function (): void {
    $resolver = new CallableResolver();

    $resolver->validate('App\\NotAllowed', 'handle');
})->throws(EntityNotAllowedException::class);

it('accepts mixed allowlist format with keyed arrays', function (): void {
    config()->set('delayed-process.allowed_entities', [
        'App\\Other',
        AllowedService::class => ['queue' => 'heavy'],
    ]);

    $resolver = new CallableResolver();

    $callable = $resolver->resolve(AllowedService::class, 'handle');

    expect($callable)->toBeArray()
        ->and($callable[0])->toBeInstanceOf(AllowedService::class);
});
