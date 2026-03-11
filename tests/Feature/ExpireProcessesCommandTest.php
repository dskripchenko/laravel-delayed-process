<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

it('marks expired processes', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);
    $process->expires_at = now()->subMinutes(5);
    $process->save();

    $this->artisan('delayed:expire')
        ->assertSuccessful();

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Expired);
});

it('skips processes without expires_at', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $this->artisan('delayed:expire')
        ->assertSuccessful();

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::New);
});

it('skips terminal processes', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);
    $process->status = ProcessStatus::Done;
    $process->expires_at = now()->subMinutes(5);
    $process->save();

    $this->artisan('delayed:expire')
        ->assertSuccessful();

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Done);
});

it('supports dry-run mode', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);
    $process->expires_at = now()->subMinutes(5);
    $process->save();

    $this->artisan('delayed:expire', ['--dry-run' => true])
        ->assertSuccessful();

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::New);
});
