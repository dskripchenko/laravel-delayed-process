<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Console\Commands;

use Dskripchenko\DelayedProcess\Schema\V1SchemaUpgrader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class MigrateFromV1Command extends Command
{
    protected $signature = 'delayed:migrate-v1
        {--force : Force the operation to run in production}
        {--timezone= : The time zone the 1.x timestamps were written in (default: app.timezone)}';

    protected $description = 'Migrate delayed_processes table from v1 schema to v2';

    public function handle(V1SchemaUpgrader $upgrader): int
    {
        if (! $upgrader->tableExists()) {
            $this->error('Table "delayed_processes" does not exist. Nothing to migrate.');

            return self::FAILURE;
        }

        $pending = $upgrader->pendingSteps();
        if ($pending === []) {
            $this->warn('Already migrated: the table has the 2.x schema.');

            return self::SUCCESS;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Use --force to run this command in production.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Migrate delayed_processes to v2 schema?')) {
            $this->info('Migration cancelled.');

            return self::SUCCESS;
        }

        $timezone = (string) ($this->option('timezone') ?: config('app.timezone', 'UTC'));
        $this->info('Pending: '.implode(', ', $pending).'; timestamps are read in '.$timezone.'.');

        $done = $upgrader->upgrade($timezone);

        $this->newLine();
        $this->info('Migration complete!');
        $this->table(
            ['Step', 'Status'],
            [
                ['error_message / error_trace', in_array('error_columns', $done, true) ? 'Added' : 'Present'],
                ['CHECK constraint on status', in_array('status_check', $done, true) ? 'Added' : 'Present or unsupported'],
                ['JSON and timestamp columns', in_array('column_types', $done, true) ? 'Converted' : 'Unchanged'],
                ['Existing rows', (string) DB::table('delayed_processes')->count()],
            ],
        );

        return self::SUCCESS;
    }
}
