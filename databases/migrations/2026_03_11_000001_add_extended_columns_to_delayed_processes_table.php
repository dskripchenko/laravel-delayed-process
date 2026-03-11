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
        Schema::table('delayed_processes', static function (Blueprint $table): void {
            $table->timestampTz('started_at')
                ->nullable()
                ->after('error_trace')
                ->comment('Execution start time');

            $table->unsignedBigInteger('duration_ms')
                ->nullable()
                ->after('started_at')
                ->comment('Execution duration in milliseconds');

            $table->string('callback_url', 2048)
                ->nullable()
                ->after('duration_ms')
                ->comment('Webhook URL for terminal status notification');

            $table->unsignedTinyInteger('progress')
                ->default(0)
                ->after('callback_url')
                ->comment('Execution progress 0-100');

            $table->timestampTz('expires_at')
                ->nullable()
                ->after('progress')
                ->comment('Process expiration time (TTL)');
        });

        $driver = DB::getDriverName();
        $isMysqlLike = in_array($driver, ['mysql', 'mariadb'], true);

        if ($driver === 'pgsql' || $isMysqlLike) {
            DB::statement('ALTER TABLE delayed_processes DROP CONSTRAINT IF EXISTS delayed_processes_status_check');
            DB::statement("
                ALTER TABLE delayed_processes
                ADD CONSTRAINT delayed_processes_status_check
                CHECK (status IN ('new', 'wait', 'done', 'error', 'expired', 'cancelled'))
            ");
        }

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS delayed_processes_terminal_created_idx');
            DB::statement("
                CREATE INDEX delayed_processes_terminal_created_idx
                ON delayed_processes (created_at)
                WHERE status IN ('done', 'error', 'expired', 'cancelled')
            ");

            DB::statement("
                CREATE INDEX delayed_processes_expires_at_idx
                ON delayed_processes (expires_at)
                WHERE status IN ('new', 'wait') AND expires_at IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        $isMysqlLike = in_array($driver, ['mysql', 'mariadb'], true);

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS delayed_processes_expires_at_idx');
            DB::statement('DROP INDEX IF EXISTS delayed_processes_terminal_created_idx');
            DB::statement("
                CREATE INDEX delayed_processes_terminal_created_idx
                ON delayed_processes (created_at)
                WHERE status IN ('done', 'error')
            ");
        }

        if ($driver === 'pgsql' || $isMysqlLike) {
            DB::statement('ALTER TABLE delayed_processes DROP CONSTRAINT IF EXISTS delayed_processes_status_check');
            DB::statement("
                ALTER TABLE delayed_processes
                ADD CONSTRAINT delayed_processes_status_check
                CHECK (status IN ('new', 'wait', 'done', 'error'))
            ");
        }

        Schema::table('delayed_processes', static function (Blueprint $table): void {
            $table->dropColumn([
                'started_at',
                'duration_ms',
                'callback_url',
                'progress',
                'expires_at',
            ]);
        });
    }
};
