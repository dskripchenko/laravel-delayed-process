<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Dskripchenko\DelayedProcess\Resources\DelayedProcessResource;

it('transforms done process to array', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $process->status = ProcessStatus::Done;
    $process->data = ['url' => '/report.pdf'];
    $process->progress = 100;
    $process->started_at = now()->subSeconds(5);
    $process->duration_ms = 5000;
    $process->save();
    $process->refresh();

    $resource = (new DelayedProcessResource($process))->toArray(request());

    expect($resource)
        ->toHaveKeys(['uuid', 'status', 'data', 'progress', 'started_at', 'duration_ms', 'attempts', 'current_try', 'created_at', 'updated_at'])
        ->and($resource['uuid'])->toBe($process->uuid)
        ->and($resource['status'])->toBe('done')
        ->and($resource['data'])->toBe(['url' => '/report.pdf'])
        ->and($resource['progress'])->toBe(100)
        ->and($resource['duration_ms'])->toBe(5000)
        ->and($resource['started_at'])->not->toBeNull();
});

it('hides data for non-terminal process', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);
    $process->refresh();

    $json = (new DelayedProcessResource($process))->response()->getData(true);

    expect($json['data']['status'])->toBe('new')
        ->and($json['data'])->not->toHaveKey('data');
});

it('includes error_message and is_error_truncated when present', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $process->status = ProcessStatus::Error;
    $process->error_message = 'Something went wrong... [truncated]';
    $process->save();
    $process->refresh();

    $resource = (new DelayedProcessResource($process))->toArray(request());

    expect($resource['error_message'])->toBe('Something went wrong... [truncated]')
        ->and($resource['is_error_truncated'])->toBeTrue();
});

it('sets is_error_truncated to false for short error', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $process->status = ProcessStatus::Error;
    $process->error_message = 'Short error';
    $process->save();
    $process->refresh();

    $resource = (new DelayedProcessResource($process))->toArray(request());

    expect($resource['error_message'])->toBe('Short error')
        ->and($resource['is_error_truncated'])->toBeFalse();
});

it('excludes error_message when null', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $process->status = ProcessStatus::Done;
    $process->data = ['ok' => true];
    $process->save();
    $process->refresh();

    $json = (new DelayedProcessResource($process))->response()->getData(true);

    expect($json['data'])->not->toHaveKey('error_message')
        ->and($json['data'])->not->toHaveKey('is_error_truncated');
});
