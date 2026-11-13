<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings a 1.x `delayed_processes` table to the 2.0 schema.
 *
 * The logic of `delayed:migrate-v1`, callable from an application migration as
 * well — for deployments that only run `migrate`. Must run BEFORE the package
 * migration `2026_03_11_000001_add_extended_columns_to_delayed_processes_table`.
 *
 * Every step checks the current schema first, so the upgrader can be run again
 * after a partial failure and does nothing on a 2.x table:
 *
 *   - adds `error_message` and `error_trace`;
 *   - adds the CHECK constraint on `status` (PostgreSQL, MySQL, MariaDB);
 *   - PostgreSQL: converts `parameters`, `data`, `logs` to JSONB and the
 *     timestamps to TIMESTAMPTZ in ONE `ALTER TABLE` (one table rewrite), only
 *     the columns that still have the old type; a value that is not valid JSON
 *     is kept as a JSON string instead of failing the migration; the naive
 *     timestamps are read in the given time zone — the one the application
 *     wrote them in — rather than in UTC;
 *   - MySQL/MariaDB: quotes invalid JSON, converts the three columns in one
 *     `ALTER TABLE`;
 *   - creates the indexes that are missing;
 *   - SQLite: only the two columns.
 */
final class V1SchemaUpgrader
{
    private const TABLE = 'delayed_processes';

    private const JSON_COLUMNS = ['parameters', 'data', 'logs'];

    private const TIMESTAMP_COLUMNS = ['created_at', 'updated_at'];

    private const CHECK = 'delayed_processes_status_check';

    /**
     * Statuses the CHECK constraint allows: those of 2.0, plus the two that the
     * package migration adds right after — so the order of the two steps does
     * not matter for the data.
     */
    private const STATUSES = ['new', 'wait', 'done', 'error', 'expired', 'cancelled'];

    /**
     * @var list<string>
     */
    private array $done = [];

    public function __construct(private readonly ?Connection $connection = null)
    {
    }

    public function tableExists(): bool
    {
        return Schema::connection($this->connectionName())->hasTable(self::TABLE);
    }

    /**
     * Whether nothing is left to do.
     */
    public function isUpgraded(): bool
    {
        return $this->pendingSteps() === [];
    }

    /**
     * The steps the table still needs, by name.
     *
     * @return list<string>
     */
    public function pendingSteps(): array
    {
        $steps = [];

        if (! $this->hasColumn('error_message') || ! $this->hasColumn('error_trace')) {
            $steps[] = 'error_columns';
        }

        if ($this->supportsCheck() && ! $this->hasCheckConstraint()) {
            $steps[] = 'status_check';
        }

        if ($this->driver() === 'pgsql' && ($this->pgColumnsToConvert() !== [])) {
            $steps[] = 'column_types';
        }

        if ($this->isMysqlLike() && $this->mysqlJsonColumnsToConvert() !== []) {
            $steps[] = 'column_types';
        }

        return $steps;
    }

    /**
     * Runs the pending steps.
     *
     * @param  string|null  $timezone  The zone the 1.x timestamps were written in
     *                                 (usually `config('app.timezone')`); null —
     *                                 the database session's zone.
     * @return list<string> The steps that ran.
     */
    public function upgrade(?string $timezone = null): array
    {
        $this->done = [];

        $this->addErrorColumns();

        $driver = $this->driver();
        if ($driver === 'pgsql') {
            $this->upgradePostgres($timezone);
        } elseif ($this->isMysqlLike()) {
            $this->upgradeMysql();
        }

        if ($this->supportsCheck() && ! $this->hasCheckConstraint()) {
            $this->db()->statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s CHECK (status IN (%s))',
                self::TABLE,
                self::CHECK,
                implode(', ', array_map(static fn (string $s): string => "'{$s}'", self::STATUSES)),
            ));
            $this->done[] = 'status_check';
        }

        return $this->done;
    }

    private function addErrorColumns(): void
    {
        $hasMessage = $this->hasColumn('error_message');
        $hasTrace = $this->hasColumn('error_trace');

        if ($hasMessage && $hasTrace) {
            return;
        }

        Schema::connection($this->connectionName())->table(self::TABLE, static function (Blueprint $table) use ($hasMessage, $hasTrace): void {
            if (! $hasMessage) {
                $table->string('error_message', 1000)->nullable()->comment('Last error message (truncated)');
            }
            if (! $hasTrace) {
                $table->text('error_trace')->nullable()->comment('Last error stack trace (truncated)');
            }
        });

        $this->done[] = 'error_columns';
    }

    private function upgradePostgres(?string $timezone): void
    {
        $columns = $this->pgColumnsToConvert();

        if ($columns !== []) {
            $zone = $timezone === null || $timezone === ''
                ? 'current_setting(\'TimeZone\')'
                : $this->db()->getPdo()->quote($timezone);

            $clauses = [];
            foreach ($columns as $column => $target) {
                if ($target === 'jsonb') {
                    $clauses[] = "ALTER COLUMN \"{$column}\" DROP DEFAULT";
                    $clauses[] = "ALTER COLUMN \"{$column}\" TYPE JSONB USING pg_temp.delayed_safe_jsonb(\"{$column}\"::text)";
                    $clauses[] = "ALTER COLUMN \"{$column}\" SET DEFAULT '[]'";
                } else {
                    $clauses[] = "ALTER COLUMN \"{$column}\" TYPE TIMESTAMPTZ USING \"{$column}\" AT TIME ZONE {$zone}";
                }
            }

            $this->db()->transaction(function () use ($clauses): void {
                // A long rewrite must not queue behind a busy worker for ever.
                $this->db()->statement("SET LOCAL lock_timeout = '10s'");
                // Text that is not valid JSON is kept as a JSON string; an empty one becomes [].
                $this->db()->statement(<<<'SQL'
                    CREATE OR REPLACE FUNCTION pg_temp.delayed_safe_jsonb(value text) RETURNS jsonb
                    LANGUAGE plpgsql IMMUTABLE AS $$
                    BEGIN
                        IF value IS NULL OR btrim(value) = '' THEN
                            RETURN '[]'::jsonb;
                        END IF;
                        RETURN value::jsonb;
                    EXCEPTION WHEN others THEN
                        RETURN to_jsonb(value);
                    END
                    $$
                    SQL);
                $this->db()->statement('ALTER TABLE '.self::TABLE.' '.implode(', ', $clauses));
            });

            $this->done[] = 'column_types';
        }

        foreach ([
            'delayed_processes_status_try_idx' => "ON ".self::TABLE." (status, \"try\") WHERE status = 'new'",
            'delayed_processes_terminal_created_idx' => "ON ".self::TABLE." (created_at) WHERE status IN ('done', 'error', 'expired', 'cancelled')",
            'delayed_processes_stuck_idx' => "ON ".self::TABLE." (updated_at) WHERE status = 'wait'",
        ] as $name => $definition) {
            $this->db()->statement("CREATE INDEX IF NOT EXISTS {$name} {$definition}");
        }
    }

    private function upgradeMysql(): void
    {
        $columns = $this->mysqlJsonColumnsToConvert();

        if ($columns !== []) {
            foreach ($columns as $column) {
                $this->db()->statement("UPDATE ".self::TABLE." SET `{$column}` = '[]' WHERE `{$column}` IS NULL OR TRIM(`{$column}`) = ''");
                $this->db()->statement("UPDATE ".self::TABLE." SET `{$column}` = JSON_QUOTE(`{$column}`) WHERE JSON_VALID(`{$column}`) = 0");
            }

            $this->db()->statement('ALTER TABLE '.self::TABLE.' '.implode(', ', array_map(
                static fn (string $column): string => "MODIFY `{$column}` JSON DEFAULT ('[]')",
                $columns,
            )));

            $this->done[] = 'column_types';
        }

        $existing = array_map(
            static fn (array $index): string => (string) $index['name'],
            Schema::connection($this->connectionName())->getIndexes(self::TABLE),
        );

        Schema::connection($this->connectionName())->table(self::TABLE, static function (Blueprint $table) use ($existing): void {
            foreach ([
                'delayed_processes_status_try_idx' => ['status', 'try'],
                'delayed_processes_terminal_created_idx' => ['status', 'created_at'],
                'delayed_processes_stuck_idx' => ['status', 'updated_at'],
            ] as $name => $columns) {
                if (! in_array($name, $existing, true)) {
                    $table->index($columns, $name);
                }
            }
        });
    }

    /**
     * PostgreSQL columns that still have the 1.x type, with their target type.
     *
     * @return array<string, 'jsonb'|'timestamptz'>
     */
    private function pgColumnsToConvert(): array
    {
        $types = [];
        foreach ($this->db()->select(
            'SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ? AND table_schema = current_schema()',
            [self::TABLE],
        ) as $row) {
            $types[(string) $row->column_name] = (string) $row->data_type;
        }

        $convert = [];
        foreach (self::JSON_COLUMNS as $column) {
            if (isset($types[$column]) && $types[$column] !== 'jsonb') {
                $convert[$column] = 'jsonb';
            }
        }
        foreach (self::TIMESTAMP_COLUMNS as $column) {
            if (isset($types[$column]) && $types[$column] === 'timestamp without time zone') {
                $convert[$column] = 'timestamptz';
            }
        }

        return $convert;
    }

    /**
     * @return list<string>
     */
    private function mysqlJsonColumnsToConvert(): array
    {
        $convert = [];
        foreach (self::JSON_COLUMNS as $column) {
            $type = strtolower((string) Schema::connection($this->connectionName())->getColumnType(self::TABLE, $column));
            if ($type !== 'json') {
                $convert[] = $column;
            }
        }

        return $convert;
    }

    private function hasCheckConstraint(): bool
    {
        if ($this->driver() === 'pgsql') {
            return $this->db()->selectOne(
                'SELECT 1 AS present FROM pg_constraint WHERE conname = ?',
                [self::CHECK],
            ) !== null;
        }

        return $this->db()->selectOne(
            'SELECT 1 AS present FROM information_schema.table_constraints WHERE constraint_name = ? AND table_schema = DATABASE()',
            [self::CHECK],
        ) !== null;
    }

    private function hasColumn(string $column): bool
    {
        return Schema::connection($this->connectionName())->hasColumn(self::TABLE, $column);
    }

    private function supportsCheck(): bool
    {
        return $this->driver() === 'pgsql' || $this->isMysqlLike();
    }

    private function isMysqlLike(): bool
    {
        return in_array($this->driver(), ['mysql', 'mariadb'], true);
    }

    private function driver(): string
    {
        return $this->db()->getDriverName();
    }

    private function db(): Connection
    {
        return $this->connection ?? DB::connection();
    }

    private function connectionName(): ?string
    {
        return $this->connection?->getName();
    }
}
