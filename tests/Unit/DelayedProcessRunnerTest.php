<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Events\ProcessCompleted;
use Dskripchenko\DelayedProcess\Events\ProcessFailed;
use Dskripchenko\DelayedProcess\Events\ProcessStarted;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Dskripchenko\DelayedProcess\Services\CallableResolver;
use Dskripchenko\DelayedProcess\Services\CallbackDispatcher;
use Dskripchenko\DelayedProcess\Services\DelayedProcessLogger;
use Dskripchenko\DelayedProcess\Services\DelayedProcessProgress;
use Dskripchenko\DelayedProcess\Services\DelayedProcessRunner;
use Dskripchenko\DelayedProcess\Tests\Fixtures\AllowedService;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config()->set('delayed-process.allowed_entities', [
        AllowedService::class,
    ]);
});

it('runs process successfully and sets done status', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'handle',
        'parameters' => ['test-input'],
    ]);

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
    );

    $runner->run($process);

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe(['result' => 'test-input'])
        ->and($process->try)->toBe(1);
});

it('catches throwable and sets error status when attempts exhausted', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'failing',
        'parameters' => [],
    ]);
    $process->attempts = 1;
    $process->save();

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
    );

    $runner->run($process);

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Error)
        ->and($process->error_message)->toContain('Intentional test failure')
        ->and($process->error_trace)->not->toBeNull();
});

it('resets to new when retries remain', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'failing',
        'parameters' => [],
    ]);

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
    );

    $runner->run($process);

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::New)
        ->and($process->try)->toBe(1)
        ->and($process->error_message)->toContain('Intentional test failure');
});

it('prevents race condition by not running already claimed process', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'handle',
        'parameters' => [],
    ]);

    // Manually set to wait to simulate already claimed
    $process->status = ProcessStatus::Wait;
    $process->save();

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
    );

    $runner->run($process);

    $process->refresh();
    // Should remain wait — not processed
    expect($process->status)->toBe(ProcessStatus::Wait)
        ->and($process->data)->toBe([]);
});

it('flushes logger even on exception', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'failing',
        'parameters' => [],
    ]);

    $logger = new DelayedProcessLogger();
    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        $logger,
    );

    $runner->run($process);

    $process->refresh();
    // Logger flush should have been called in finally block
    expect($process->error_message)->not->toBeNull();
});

it('records started_at and duration_ms on success', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'handle',
        'parameters' => [],
    ]);

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
    );

    $runner->run($process);

    $process->refresh();
    expect($process->started_at)->not->toBeNull()
        ->and($process->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('records started_at and duration_ms on failure', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'failing',
        'parameters' => [],
    ]);

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
    );

    $runner->run($process);

    $process->refresh();
    expect($process->started_at)->not->toBeNull()
        ->and($process->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('fires ProcessStarted and ProcessCompleted events on success', function (): void {
    Event::fake([ProcessStarted::class, ProcessCompleted::class]);

    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'handle',
        'parameters' => [],
    ]);

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
    );

    $runner->run($process);

    Event::assertDispatched(ProcessStarted::class);
    Event::assertDispatched(ProcessCompleted::class);
});

it('fires ProcessStarted and ProcessFailed events on failure', function (): void {
    Event::fake([ProcessStarted::class, ProcessFailed::class]);

    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'failing',
        'parameters' => [],
    ]);

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
    );

    $runner->run($process);

    Event::assertDispatched(ProcessStarted::class);
    Event::assertDispatched(ProcessFailed::class);
});

it('skips execution for cancelled process', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'handle',
        'parameters' => [],
    ]);
    $process->status = ProcessStatus::Cancelled;
    $process->save();

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
    );

    $runner->run($process);

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Cancelled)
        ->and($process->data)->toBe([]);
});

it('truncates long error messages with indicator', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'longErrorMessage',
        'parameters' => [],
    ]);
    $process->attempts = 1;
    $process->save();

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
    );

    $runner->run($process);

    $process->refresh();
    expect($process->error_message)->toEndWith('... [truncated]')
        ->and(mb_strlen($process->error_message))->toBeLessThanOrEqual(1000);
});

it('sets progress to 100 on success', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'handle',
        'parameters' => [],
    ]);

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
        new CallbackDispatcher(),
        new DelayedProcessProgress(),
    );

    $runner->run($process);

    $process->refresh();
    expect($process->progress)->toBe(100);
});

it('normalizes null result to empty array', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'returnsNull',
        'parameters' => [],
    ]);

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
    );

    $runner->run($process);

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe([]);
});

it('wraps scalar result in array', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'returnsScalar',
        'parameters' => [],
    ]);

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
    );

    $runner->run($process);

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe(['scalar-value']);
});

it('wraps associative parameters in array', function (): void {
    $params = ['key' => 'value', 'foo' => 'bar'];

    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'withAssocParams',
        'parameters' => $params,
    ]);

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
    );

    $runner->run($process);

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe($params);
});

it('dispatches callback on terminal status', function (): void {
    config()->set('delayed-process.callback.enabled', true);
    \Illuminate\Support\Facades\Http::fake();

    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'handle',
        'parameters' => [],
        'callback_url' => 'https://example.com/callback',
    ]);

    $runner = new DelayedProcessRunner(
        new CallableResolver(),
        new DelayedProcessLogger(),
        new CallbackDispatcher(),
        new DelayedProcessProgress(),
    );

    $runner->run($process);

    \Illuminate\Support\Facades\Http::assertSentCount(1);
});
