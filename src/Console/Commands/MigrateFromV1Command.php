<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MigrateFromV1Command extends Command
{
    protected $signature = 'delayed:migrate-v1
        {--force : Force the operation to run in production}';

    protected $description = 'Migrate delayed_processes table from v1 schema to v2';

    public function handle(): int
    {
        if (! Schema::hasTable('delayed_processes')) {
            $this->error('Table "delayed_processes" does not exist. Nothing to migrate.');

            return self::FAILURE;
        }

        if (Schema::hasColumn('delayed_processes', 'error_message')) {
            $this->warn('Already migrated: column "error_message" exists.');

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

        $this->migrateSchema();
        $this->printReport();

        return self::SUCCESS;
    }

    private function migrateSchema(): void
    {
        $this->info('Adding error_message and error_trace columns...');

        Schema::table('delayed_processes', static function ($table): void {
            $table->string('error_message', 1000)
                ->nullable()
                ->comment('Last error message (truncated)')
                ->after('try');

            $table->text('error_trace')
                ->nullable()
                ->comment('Last error stack trace (truncated)')
                ->after('error_message');
        });

        $driver = DB::getDriverName();
        $isMysqlLike = in_array($driver, ['mysql', 'mariadb'], true);

        $this->addCheckConstraint($driver, $isMysqlLike);

        if ($driver === 'pgsql') {
            $this->migratePostgres();
        } elseif ($isMysqlLike) {
            $this->migrateMysql();
        }
    }

    private function addCheckConstraint(
        string $driver,
        bool $isMysqlLike,
    ): void {
        if ($driver !== 'pgsql' && ! $isMysqlLike) {
            return;
        }

        $this->info('Adding CHECK constraint on status...');

        DB::statement("
            ALTER TABLE delayed_processes
            ADD CONSTRAINT delayed_processes_status_check
            CHECK (status IN ('new', 'wait', 'done', 'error'))
        ");
    }

    private function migratePostgres(): void
    {
        $this->info('Converting text columns to JSONB...');

        DB::statement("ALTER TABLE delayed_processes ALTER COLUMN parameters TYPE JSONB USING parameters::jsonb");
        DB::statement("ALTER TABLE delayed_processes ALTER COLUMN parameters SET DEFAULT '[]'");
        DB::statement("ALTER TABLE delayed_processes ALTER COLUMN data TYPE JSONB USING data::jsonb");
        DB::statement("ALTER TABLE delayed_processes ALTER COLUMN data SET DEFAULT '[]'");
        DB::statement("ALTER TABLE delayed_processes ALTER COLUMN logs TYPE JSONB USING logs::jsonb");
        DB::statement("ALTER TABLE delayed_processes ALTER COLUMN logs SET DEFAULT '[]'");

        $this->info('Converting timestamps to TIMESTAMPTZ...');

        DB::statement("ALTER TABLE delayed_processes ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE 'UTC'");
        DB::statement("ALTER TABLE delayed_processes ALTER COLUMN updated_at TYPE TIMESTAMPTZ USING updated_at AT TIME ZONE 'UTC'");

        $this->info('Creating partial indexes...');

        DB::statement("
            CREATE INDEX IF NOT EXISTS delayed_processes_status_try_idx
            ON delayed_processes (status, \"try\")
            WHERE status = 'new'
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS delayed_processes_terminal_created_idx
            ON delayed_processes (created_at)
            WHERE status IN ('done', 'error')
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS delayed_processes_stuck_idx
            ON delayed_processes (updated_at)
            WHERE status = 'wait'
        ");

        $this->info('Adding column comments...');

        DB::statement("COMMENT ON COLUMN delayed_processes.uuid IS 'Unique process identifier (UUIDv7)'");
        DB::statement("COMMENT ON COLUMN delayed_processes.entity IS 'FQCN of the handler class'");
        DB::statement("COMMENT ON COLUMN delayed_processes.method IS 'Method name to invoke on the entity'");
        DB::statement("COMMENT ON COLUMN delayed_processes.parameters IS 'Serialized invocation arguments'");
        DB::statement("COMMENT ON COLUMN delayed_processes.data IS 'Execution result payload'");
        DB::statement("COMMENT ON COLUMN delayed_processes.logs IS 'Captured log entries during execution'");
        DB::statement("COMMENT ON COLUMN delayed_processes.status IS 'Process status: new, wait, done, error'");
        DB::statement("COMMENT ON COLUMN delayed_processes.attempts IS 'Maximum retry attempts'");
        DB::statement("COMMENT ON COLUMN delayed_processes.\"try\" IS 'Current attempt number'");
    }

    private function migrateMysql(): void
    {
        $this->info('Converting text columns to JSON...');

        DB::statement("ALTER TABLE delayed_processes MODIFY parameters JSON DEFAULT ('[]')");
        DB::statement("ALTER TABLE delayed_processes MODIFY data JSON DEFAULT ('[]')");
        DB::statement("ALTER TABLE delayed_processes MODIFY logs JSON DEFAULT ('[]')");

        $this->info('Creating composite indexes...');

        Schema::table('delayed_processes', static function ($table): void {
            $table->index(['status', 'try'], 'delayed_processes_status_try_idx');
            $table->index(['status', 'created_at'], 'delayed_processes_terminal_created_idx');
            $table->index(['status', 'updated_at'], 'delayed_processes_stuck_idx');
        });
    }

    private function printReport(): void
    {
        $count = DB::table('delayed_processes')->count();
        $driver = DB::getDriverName();

        $this->newLine();
        $this->info('Migration complete!');
        $this->table(
            ['Item', 'Status'],
            [
                ['error_message column', 'Added'],
                ['error_trace column', 'Added'],
                ['CHECK constraint', 'Added'],
                ['JSON columns', $driver === 'pgsql' ? 'Converted to JSONB' : ($this->isMysqlLike($driver) ? 'Converted to JSON' : 'Unchanged')],
                ['Timestamps', $driver === 'pgsql' ? 'Converted to TIMESTAMPTZ' : 'Unchanged'],
                ['Indexes', $driver === 'pgsql' ? 'Partial indexes created' : ($this->isMysqlLike($driver) ? 'Composite indexes created' : 'Unchanged')],
                ['Existing rows', (string) $count],
            ],
        );
    }

    private function isMysqlLike(string $driver): bool
    {
        return in_array($driver, ['mysql', 'mariadb'], true);
    }
}
