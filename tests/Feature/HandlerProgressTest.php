<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Contracts\ProcessProgressInterface;
use Dskripchenko\DelayedProcess\Contracts\ProcessRunnerInterface;
use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Jobs\DelayedProcessJob;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Dskripchenko\DelayedProcess\Services\DelayedProcessProgress;
use Dskripchenko\DelayedProcess\Tests\Fixtures\ProgressReportingService;

beforeEach(function (): void {
    config()->set('delayed-process.allowed_entities', [
        ProgressReportingService::class,
    ]);
});

function progressProcess(string $method): DelayedProcess
{
    $process = DelayedProcess::create([
        'entity' => ProgressReportingService::class,
        'method' => $method,
        'parameters' => [],
    ]);
    $process->parameters = [$process->uuid];
    $process->save();

    return $process;
}

it('persists progress reported by a handler that injects the tracker', function (): void {
    $process = progressProcess('viaConstructor');

    app(ProcessRunnerInterface::class)->run($process);

    $process->refresh();
    expect($process->data)->toBe(['seen' => 30])
        ->and($process->status)->toBe(ProcessStatus::Done)
        ->and($process->progress)->toBe(100);
});

it('persists progress reported by a handler that resolves the tracker', function (): void {
    $process = progressProcess('viaContainer');

    app(ProcessRunnerInterface::class)->run($process);

    $process->refresh();
    expect($process->data)->toBe(['seen' => 60]);
});

it('persists progress when the process runs through the queue job', function (): void {
    $process = progressProcess('viaConstructor');

    DelayedProcessJob::dispatchSync($process);

    $process->refresh();
    expect($process->data)->toBe(['seen' => 30])
        ->and($process->status)->toBe(ProcessStatus::Done);
});

it('keeps the last reported progress when the handler fails', function (): void {
    $process = progressProcess('thenFails');
    $process->attempts = 1;
    $process->save();

    app(ProcessRunnerInterface::class)->run($process);

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Error)
        ->and($process->progress)->toBe(40);
});

it('detaches the tracker from the process once the run is over', function (): void {
    $process = progressProcess('viaConstructor');

    app(ProcessRunnerInterface::class)->run($process);

    app(ProcessProgressInterface::class)->setProgress(10);

    $process->refresh();
    expect($process->progress)->toBe(100);
});

it('does not leak a process into the next run', function (): void {
    $first = progressProcess('viaConstructor');
    $second = progressProcess('viaContainer');

    app(ProcessRunnerInterface::class)->run($first);
    app(ProcessRunnerInterface::class)->run($second);

    expect($first->refresh()->progress)->toBe(100)
        ->and($second->refresh()->data)->toBe(['seen' => 60]);
});

it('shares one tracker between the interface and the concrete class', function (): void {
    expect(app(ProcessProgressInterface::class))
        ->toBe(app(DelayedProcessProgress::class));
});

it('stores log lines a handler writes while running through the queue job', function (): void {
    $process = progressProcess('logs');
    $process->parameters = [];
    $process->save();

    DelayedProcessJob::dispatchSync($process);

    $process->refresh();
    $messages = array_column($process->logs ?? [], 'message');
    expect($messages)->toContain('handler log line');
});
