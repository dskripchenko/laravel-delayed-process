<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

it('resets stuck wait processes to new', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'Entity',
        'method' => 'method',
        'parameters' => [],
    ]);
    $process->status = ProcessStatus::Wait;
    $process->updated_at = now()->subMinutes(120);
    $process->save();

    $this->artisan('delayed:unstuck', ['--timeout' => 60])
        ->assertSuccessful();

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::New);
});

it('does not reset fresh wait processes', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'Entity',
        'method' => 'method',
        'parameters' => [],
    ]);
    $process->status = ProcessStatus::Wait;
    $process->save();

    $this->artisan('delayed:unstuck', ['--timeout' => 60])
        ->assertSuccessful();

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Wait);
});

it('dry-run does not modify processes', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'Entity',
        'method' => 'method',
        'parameters' => [],
    ]);
    $process->status = ProcessStatus::Wait;
    $process->updated_at = now()->subMinutes(120);
    $process->save();

    $this->artisan('delayed:unstuck', ['--timeout' => 60, '--dry-run' => true])
        ->assertSuccessful();

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Wait);
});

it('reports no stuck processes found', function (): void {
    $this->artisan('delayed:unstuck')
        ->expectsOutput('No stuck processes found.')
        ->assertSuccessful();
});
