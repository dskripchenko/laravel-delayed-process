<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Events\ProcessCreated;
use Dskripchenko\DelayedProcess\Exceptions\EntityNotAllowedException;
use Dskripchenko\DelayedProcess\Exceptions\InvalidParametersException;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Dskripchenko\DelayedProcess\Services\CallableResolver;
use Dskripchenko\DelayedProcess\Services\DelayedProcessFactory;
use Dskripchenko\DelayedProcess\Services\EntityConfigResolver;
use Dskripchenko\DelayedProcess\Tests\Fixtures\AllowedService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('delayed-process.allowed_entities', [
        AllowedService::class,
    ]);
});

it('creates process and dispatches job in transaction', function (): void {
    Queue::fake();

    $factory = new DelayedProcessFactory(new CallableResolver());
    $process = $factory->make(AllowedService::class, 'handle', 'arg1', 'arg2');

    expect($process->exists)->toBeTrue()
        ->and($process->entity)->toBe(AllowedService::class)
        ->and($process->method)->toBe('handle')
        ->and($process->parameters)->toBe(['arg1', 'arg2'])
        ->and($process->status)->toBe(ProcessStatus::New)
        ->and($process->uuid)->not->toBeEmpty();

    Queue::assertPushed(\Dskripchenko\DelayedProcess\Jobs\DelayedProcessJob::class);
});

it('validates entity before creating', function (): void {
    Queue::fake();

    $factory = new DelayedProcessFactory(new CallableResolver());

    $factory->make('App\\NotAllowed', 'handle');
})->throws(EntityNotAllowedException::class);

it('does not create record when validation fails', function (): void {
    Queue::fake();

    $factory = new DelayedProcessFactory(new CallableResolver());

    try {
        $factory->make('App\\NotAllowed', 'handle');
    } catch (EntityNotAllowedException) {
        // expected
    }

    expect(DelayedProcess::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('fires ProcessCreated event', function (): void {
    Queue::fake();
    Event::fake([ProcessCreated::class]);

    $factory = new DelayedProcessFactory(new CallableResolver());
    $factory->make(AllowedService::class, 'handle');

    Event::assertDispatched(ProcessCreated::class);
});

it('throws InvalidParametersException for non-serializable params', function (): void {
    Queue::fake();

    $factory = new DelayedProcessFactory(new CallableResolver());

    // Create a resource that cannot be JSON-encoded
    $resource = fopen('php://memory', 'r');

    try {
        $factory->make(AllowedService::class, 'handle', $resource);
    } finally {
        fclose($resource);
    }
})->throws(InvalidParametersException::class);

it('sets expires_at from config TTL', function (): void {
    Queue::fake();
    config()->set('delayed-process.default_ttl_minutes', 30);

    $factory = new DelayedProcessFactory(new CallableResolver());
    $process = $factory->make(AllowedService::class, 'handle');

    expect($process->expires_at)->not->toBeNull()
        ->and($process->expires_at->diffInMinutes(now()))->toBeLessThanOrEqual(31);
});

it('does not set expires_at when TTL is null', function (): void {
    Queue::fake();
    config()->set('delayed-process.default_ttl_minutes', null);

    $factory = new DelayedProcessFactory(new CallableResolver());
    $process = $factory->make(AllowedService::class, 'handle');

    expect($process->expires_at)->toBeNull();
});

it('creates process with callback URL via makeWithCallback', function (): void {
    Queue::fake();

    $factory = new DelayedProcessFactory(new CallableResolver());
    $process = $factory->makeWithCallback(
        AllowedService::class,
        'handle',
        'https://example.com/webhook',
        'arg1',
    );

    expect($process->callback_url)->toBe('https://example.com/webhook')
        ->and($process->parameters)->toBe(['arg1']);
});

it('configures job queue from per-entity config', function (): void {
    Queue::fake();
    config()->set('delayed-process.allowed_entities', [
        AllowedService::class => ['queue' => 'heavy', 'timeout' => 600],
    ]);

    $factory = new DelayedProcessFactory(
        new CallableResolver(new EntityConfigResolver()),
        new EntityConfigResolver(),
    );
    $factory->make(AllowedService::class, 'handle');

    Queue::assertPushed(\Dskripchenko\DelayedProcess\Jobs\DelayedProcessJob::class, function ($job) {
        return $job->queue === 'heavy' && $job->timeout === 600;
    });
});

it('configures job connection from per-entity config', function (): void {
    Queue::fake();
    config()->set('delayed-process.allowed_entities', [
        AllowedService::class => ['queue' => 'reports', 'connection' => 'redis'],
    ]);

    $factory = new DelayedProcessFactory(
        new CallableResolver(new EntityConfigResolver()),
        new EntityConfigResolver(),
    );
    $factory->make(AllowedService::class, 'handle');

    Queue::assertPushed(\Dskripchenko\DelayedProcess\Jobs\DelayedProcessJob::class, function ($job) {
        return $job->queue === 'reports' && $job->connection === 'redis';
    });
});
