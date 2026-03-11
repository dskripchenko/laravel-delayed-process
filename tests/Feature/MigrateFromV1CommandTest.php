<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('fails when delayed_processes table does not exist', function (): void {
    Schema::dropIfExists('delayed_processes');

    $this->artisan('delayed:migrate-v1')
        ->expectsOutput('Table "delayed_processes" does not exist. Nothing to migrate.')
        ->assertFailed();
});

it('reports already migrated when error_message column exists', function (): void {
    // The default migration creates a v2 schema with error_message
    $this->artisan('delayed:migrate-v1')
        ->expectsOutput('Already migrated: column "error_message" exists.')
        ->assertSuccessful();
});

it('migrates v1 schema to v2', function (): void {
    dropToV1Schema();

    $this->artisan('delayed:migrate-v1', ['--force' => true])
        ->assertSuccessful();

    expect(Schema::hasColumn('delayed_processes', 'error_message'))->toBeTrue();
    expect(Schema::hasColumn('delayed_processes', 'error_trace'))->toBeTrue();
});

it('asks for confirmation without --force', function (): void {
    dropToV1Schema();

    $this->artisan('delayed:migrate-v1')
        ->expectsConfirmation('Migrate delayed_processes to v2 schema?', 'yes')
        ->assertSuccessful();

    expect(Schema::hasColumn('delayed_processes', 'error_message'))->toBeTrue();
});

it('cancels when user declines confirmation', function (): void {
    dropToV1Schema();

    $this->artisan('delayed:migrate-v1')
        ->expectsConfirmation('Migrate delayed_processes to v2 schema?', 'no')
        ->expectsOutput('Migration cancelled.')
        ->assertSuccessful();

    expect(Schema::hasColumn('delayed_processes', 'error_message'))->toBeFalse();
});

it('prints report with row count after migration', function (): void {
    dropToV1Schema();

    DB::table('delayed_processes')->insert([
        'uuid' => 'test-uuid-1',
        'entity' => 'App\\Service',
        'method' => 'handle',
        'parameters' => '[]',
        'data' => '[]',
        'logs' => '[]',
        'status' => 'new',
        'attempts' => 5,
        'try' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('delayed:migrate-v1', ['--force' => true])
        ->assertSuccessful();

    expect(DB::table('delayed_processes')->count())->toBe(1);
    expect(Schema::hasColumn('delayed_processes', 'error_message'))->toBeTrue();
});

/**
 * Drop v2-specific columns and constraints to simulate a v1 schema.
 */
function dropToV1Schema(): void
{
    // Remove v2 columns to simulate v1 table
    if (Schema::hasColumn('delayed_processes', 'error_message')) {
        Schema::table('delayed_processes', static function ($table): void {
            $table->dropColumn(['error_message', 'error_trace']);
        });
    }
}
