<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

it('claims process atomically', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $claimed = DelayedProcess::query()->claimForExecution();

    expect($claimed)->not->toBeNull()
        ->and($claimed->id)->toBe($process->id)
        ->and($claimed->status)->toBe(ProcessStatus::Wait)
        ->and($claimed->try)->toBe(1);
});

it('returns null when no new processes exist', function (): void {
    $claimed = DelayedProcess::query()->claimForExecution();

    expect($claimed)->toBeNull();
});

it('returns null when process is already claimed by another', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    // Simulate another worker claiming it first
    DelayedProcess::query()
        ->where('id', $process->id)
        ->update(['status' => ProcessStatus::Wait->value]);

    $claimed = DelayedProcess::query()->claimForExecution();

    expect($claimed)->toBeNull();
});

it('claims oldest try first', function (): void {
    $old = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $new = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    // Give $new a higher try count
    $new->try = 2;
    $new->save();

    $claimed = DelayedProcess::query()->claimForExecution();

    expect($claimed->id)->toBe($old->id);
});

it('increments try on claim', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    expect($process->try)->toBe(0);

    $claimed = DelayedProcess::query()->claimForExecution();

    expect($claimed->try)->toBe(1);
});
