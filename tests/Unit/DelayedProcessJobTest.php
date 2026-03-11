<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Contracts\ProcessLoggerInterface;
use Dskripchenko\DelayedProcess\Contracts\ProcessRunnerInterface;
use Dskripchenko\DelayedProcess\Jobs\DelayedProcessJob;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

it('calls runner and logger in handle', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $runner = Mockery::mock(ProcessRunnerInterface::class);
    $runner->shouldReceive('run')->once()->with($process);

    $logger = Mockery::mock(ProcessLoggerInterface::class);
    $logger->shouldReceive('setProcess')->once()->with($process);
    $logger->shouldReceive('log')->zeroOrMoreTimes();

    $job = new DelayedProcessJob($process);
    $job->handle($runner, $logger);
});

it('restores original dispatcher even on exception', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $runner = Mockery::mock(ProcessRunnerInterface::class);
    $runner->shouldReceive('run')->once()->andThrow(new RuntimeException('Test failure'));

    $logger = Mockery::mock(ProcessLoggerInterface::class);
    $logger->shouldReceive('setProcess')->once();
    $logger->shouldReceive('log')->zeroOrMoreTimes();

    $originalDispatcher = Illuminate\Support\Facades\Log::getEventDispatcher();

    $job = new DelayedProcessJob($process);

    try {
        $job->handle($runner, $logger);
    } catch (RuntimeException) {
        // expected
    }

    expect(Illuminate\Support\Facades\Log::getEventDispatcher())->toBe($originalDispatcher);
});

it('reads timeout and tries from config', function (): void {
    config()->set('delayed-process.job.timeout', 120);
    config()->set('delayed-process.job.tries', 3);
    config()->set('delayed-process.job.backoff', [10, 20]);

    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $job = new DelayedProcessJob($process);

    expect($job->timeout)->toBe(120)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 20]);
});
