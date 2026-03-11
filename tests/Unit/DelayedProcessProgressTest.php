<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Dskripchenko\DelayedProcess\Services\DelayedProcessProgress;

it('updates model progress', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $progress = new DelayedProcessProgress();
    $progress->setProcess($process);
    $progress->setProgress(42);

    $process->refresh();
    expect($process->progress)->toBe(42);
});

it('clamps progress to 0-100 range', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $progress = new DelayedProcessProgress();
    $progress->setProcess($process);

    $progress->setProgress(-10);
    $process->refresh();
    expect($process->progress)->toBe(0);

    $progress->setProgress(150);
    $process->refresh();
    expect($process->progress)->toBe(100);
});

it('does nothing when process is not set', function (): void {
    $progress = new DelayedProcessProgress();
    $progress->setProgress(50);

    expect(true)->toBeTrue();
});
