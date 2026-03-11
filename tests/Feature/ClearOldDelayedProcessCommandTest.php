<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

it('clears old terminal processes', function (): void {
    // Create an old done process
    $old = DelayedProcess::create([
        'entity' => 'OldEntity',
        'method' => 'oldMethod',
        'parameters' => [],
    ]);
    $old->status = ProcessStatus::Done;
    $old->created_at = now()->subDays(60);
    $old->save();

    // Create a recent done process
    $recent = DelayedProcess::create([
        'entity' => 'RecentEntity',
        'method' => 'recentMethod',
        'parameters' => [],
    ]);
    $recent->status = ProcessStatus::Done;
    $recent->save();

    // Create an old new process (should not be deleted)
    $active = DelayedProcess::create([
        'entity' => 'ActiveEntity',
        'method' => 'activeMethod',
        'parameters' => [],
    ]);
    $active->created_at = now()->subDays(60);
    $active->save();

    $this->artisan('delayed:clear', ['--days' => 30])
        ->assertSuccessful();

    expect(DelayedProcess::query()->count())->toBe(2)
        ->and(DelayedProcess::query()->find($old->id))->toBeNull()
        ->and(DelayedProcess::query()->find($recent->id))->not->toBeNull()
        ->and(DelayedProcess::query()->find($active->id))->not->toBeNull();
});

it('respects custom days option', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'Entity',
        'method' => 'method',
        'parameters' => [],
    ]);
    $process->status = ProcessStatus::Error;
    $process->created_at = now()->subDays(5);
    $process->save();

    $this->artisan('delayed:clear', ['--days' => 3])
        ->assertSuccessful();

    expect(DelayedProcess::query()->find($process->id))->toBeNull();
});

it('clears old expired processes', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'Entity',
        'method' => 'method',
        'parameters' => [],
    ]);
    $process->status = ProcessStatus::Expired;
    $process->created_at = now()->subDays(40);
    $process->save();

    $this->artisan('delayed:clear', ['--days' => 30])
        ->assertSuccessful();

    expect(DelayedProcess::query()->find($process->id))->toBeNull();
});

it('clears old cancelled processes', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'Entity',
        'method' => 'method',
        'parameters' => [],
    ]);
    $process->status = ProcessStatus::Cancelled;
    $process->created_at = now()->subDays(40);
    $process->save();

    $this->artisan('delayed:clear', ['--days' => 30])
        ->assertSuccessful();

    expect(DelayedProcess::query()->find($process->id))->toBeNull();
});
