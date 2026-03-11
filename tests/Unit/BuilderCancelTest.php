<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

it('cancels a new process', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $result = DelayedProcess::query()->cancel($process->uuid);

    expect($result)->toBeTrue();

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Cancelled);
});

it('cancels a wait process', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);
    $process->status = ProcessStatus::Wait;
    $process->save();

    $result = DelayedProcess::query()->cancel($process->uuid);

    expect($result)->toBeTrue();

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Cancelled);
});

it('fails to cancel a terminal process', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);
    $process->status = ProcessStatus::Done;
    $process->save();

    $result = DelayedProcess::query()->cancel($process->uuid);

    expect($result)->toBeFalse();

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Done);
});

it('filters by multiple UUIDs with whereUuids', function (): void {
    $p1 = DelayedProcess::create(['entity' => 'E', 'method' => 'm', 'parameters' => []]);
    $p2 = DelayedProcess::create(['entity' => 'E', 'method' => 'm', 'parameters' => []]);
    $p3 = DelayedProcess::create(['entity' => 'E', 'method' => 'm', 'parameters' => []]);

    $results = DelayedProcess::query()->whereUuids([$p1->uuid, $p3->uuid])->pluck('uuid')->toArray();

    expect($results)->toHaveCount(2)
        ->and($results)->toContain($p1->uuid)
        ->and($results)->toContain($p3->uuid)
        ->and($results)->not->toContain($p2->uuid);
});
