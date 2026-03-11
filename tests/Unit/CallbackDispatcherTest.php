<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Dskripchenko\DelayedProcess\Services\CallbackDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('does not dispatch when callback is disabled', function (): void {
    config()->set('delayed-process.callback.enabled', false);
    Http::fake();

    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
        'callback_url' => 'https://example.com/webhook',
    ]);
    $process->status = ProcessStatus::Done;
    $process->save();

    $dispatcher = new CallbackDispatcher();
    $dispatcher->dispatch($process);

    Http::assertNothingSent();
});

it('does not dispatch when callback_url is null', function (): void {
    config()->set('delayed-process.callback.enabled', true);
    Http::fake();

    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);
    $process->status = ProcessStatus::Done;
    $process->save();

    $dispatcher = new CallbackDispatcher();
    $dispatcher->dispatch($process);

    Http::assertNothingSent();
});

it('does not dispatch for non-terminal status', function (): void {
    config()->set('delayed-process.callback.enabled', true);
    Http::fake();

    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
        'callback_url' => 'https://example.com/webhook',
    ]);

    $dispatcher = new CallbackDispatcher();
    $dispatcher->dispatch($process);

    Http::assertNothingSent();
});

it('sends POST on terminal status with callback_url', function (): void {
    config()->set('delayed-process.callback.enabled', true);
    Http::fake();

    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
        'callback_url' => 'https://example.com/webhook',
    ]);
    $process->status = ProcessStatus::Done;
    $process->data = ['key' => 'value'];
    $process->save();

    $dispatcher = new CallbackDispatcher();
    $dispatcher->dispatch($process);

    Http::assertSentCount(1);
});

it('logs warning on HTTP failure', function (): void {
    config()->set('delayed-process.callback.enabled', true);
    Http::fake(fn () => throw new \RuntimeException('Connection refused'));
    Log::spy();

    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
        'callback_url' => 'https://example.com/webhook',
    ]);
    $process->status = ProcessStatus::Error;
    $process->save();

    $dispatcher = new CallbackDispatcher();
    $dispatcher->dispatch($process);

    Log::shouldHaveReceived('warning')->once();
});

it('does not dispatch when callback_url is empty string', function (): void {
    config()->set('delayed-process.callback.enabled', true);
    Http::fake();

    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
        'callback_url' => '',
    ]);
    $process->status = ProcessStatus::Done;
    $process->save();

    $dispatcher = new CallbackDispatcher();
    $dispatcher->dispatch($process);

    Http::assertNothingSent();
});
