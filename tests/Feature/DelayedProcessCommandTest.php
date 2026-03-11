<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Dskripchenko\DelayedProcess\Tests\Fixtures\AllowedService;

beforeEach(function (): void {
    config()->set('delayed-process.allowed_entities', [
        AllowedService::class,
    ]);
});

it('processes new delayed processes', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'handle',
        'parameters' => ['test'],
    ]);

    $this->artisan('delayed:process', ['--max-iterations' => 1])
        ->assertSuccessful();

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe(['result' => 'test']);
});

it('exits when no processes and max-iterations set', function (): void {
    $this->artisan('delayed:process', ['--max-iterations' => 1])
        ->assertSuccessful();
});

it('handles failing process gracefully', function (): void {
    $process = DelayedProcess::create([
        'entity' => AllowedService::class,
        'method' => 'failing',
        'parameters' => [],
    ]);

    $this->artisan('delayed:process', ['--max-iterations' => 1])
        ->assertSuccessful();

    $process->refresh();
    // Has retries left — back to new
    expect($process->status)->toBe(ProcessStatus::New)
        ->and($process->error_message)->toContain('Intentional test failure');
});
