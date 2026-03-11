<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delayed_processes', static function (Blueprint $table): void {
            $table->id();

            $table->string('uuid')
                ->unique()
                ->comment('Unique process identifier (UUIDv7)');

            $table->string('entity')
                ->nullable()
                ->comment('FQCN of the handler class');

            $table->string('method')
                ->comment('Method name to invoke on the entity');

            $table->jsonb('parameters')
                ->default('[]')
                ->comment('Serialized invocation arguments');

            $table->jsonb('data')
                ->default('[]')
                ->comment('Execution result payload');

            $table->jsonb('logs')
                ->default('[]')
                ->comment('Captured log entries during execution');

            $table->string('status')
                ->default('new')
                ->comment('Process status: new, wait, done, error');

            $table->unsignedTinyInteger('attempts')
                ->default(5)
                ->comment('Maximum retry attempts');

            $table->unsignedTinyInteger('try')
                ->default(0)
                ->comment('Current attempt number');

            $table->string('error_message', 1000)
                ->nullable()
                ->comment('Last error message (truncated)');

            $table->text('error_trace')
                ->nullable()
                ->comment('Last error stack trace (truncated)');

            $table->timestampsTz();
        });

        $driver = DB::getDriverName();
        $isMysqlLike = in_array($driver, ['mysql', 'mariadb'], true);

        // CHECK constraint: PostgreSQL, MySQL 8.0.16+, MariaDB 10.2.1+
        if ($driver === 'pgsql' || $isMysqlLike) {
            DB::statement("
                ALTER TABLE delayed_processes
                ADD CONSTRAINT delayed_processes_status_check
                CHECK (status IN ('new', 'wait', 'done', 'error'))
            ");
        }

        if ($driver === 'pgsql') {
            // Partial indexes (PostgreSQL-only)
            DB::statement("
                CREATE INDEX delayed_processes_status_try_idx
                ON delayed_processes (status, \"try\")
                WHERE status = 'new'
            ");

            DB::statement("
                CREATE INDEX delayed_processes_terminal_created_idx
                ON delayed_processes (created_at)
                WHERE status IN ('done', 'error')
            ");

            DB::statement("
                CREATE INDEX delayed_processes_stuck_idx
                ON delayed_processes (updated_at)
                WHERE status = 'wait'
            ");
        } elseif ($isMysqlLike) {
            // Regular composite indexes (MySQL/MariaDB don't support partial indexes)
            Schema::table('delayed_processes', static function (Blueprint $table): void {
                $table->index(['status', 'try'], 'delayed_processes_status_try_idx');
                $table->index(['status', 'created_at'], 'delayed_processes_terminal_created_idx');
                $table->index(['status', 'updated_at'], 'delayed_processes_stuck_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delayed_processes');
    }
};
