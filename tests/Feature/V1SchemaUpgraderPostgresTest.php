<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Schema\V1SchemaUpgrader;
use Illuminate\Support\Facades\DB;

/*
 * Runs against a real PostgreSQL: the JSONB/TIMESTAMPTZ conversion cannot be
 * checked on SQLite. Set DELAYED_PGSQL_DATABASE (and _HOST, _PORT, _USERNAME,
 * _PASSWORD) to an EMPTY scratch database to run it; skipped otherwise.
 */

beforeEach(function (): void {
    $database = getenv('DELAYED_PGSQL_DATABASE');
    if ($database === false || $database === '') {
        $this->markTestSkipped('DELAYED_PGSQL_DATABASE is not set');
    }

    config(['database.connections.pgsql_upgrade' => [
        'driver' => 'pgsql',
        'host' => getenv('DELAYED_PGSQL_HOST') ?: '127.0.0.1',
        'port' => getenv('DELAYED_PGSQL_PORT') ?: '5432',
        'database' => $database,
        'username' => getenv('DELAYED_PGSQL_USERNAME') ?: 'postgres',
        'password' => getenv('DELAYED_PGSQL_PASSWORD') ?: '',
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ]]);

    $db = DB::connection('pgsql_upgrade');
    $db->statement('DROP TABLE IF EXISTS delayed_processes');
    // The 1.x table as applications created it: text columns, naive timestamps.
    $db->statement(<<<'SQL'
        CREATE TABLE delayed_processes (
            id bigserial PRIMARY KEY,
            uuid varchar(255) NOT NULL UNIQUE,
            entity varchar(255) NULL,
            method varchar(255) NOT NULL,
            parameters text NOT NULL,
            data text NOT NULL,
            logs text NOT NULL,
            status varchar(255) NOT NULL DEFAULT 'new',
            attempts smallint NOT NULL DEFAULT 5,
            "try" smallint NOT NULL DEFAULT 0,
            created_at timestamp(0) without time zone NULL,
            updated_at timestamp(0) without time zone NULL
        )
        SQL);
    $db->table('delayed_processes')->insert([
        ['uuid' => 'a', 'entity' => 'App\\S', 'method' => 'm', 'parameters' => '[1,"2"]', 'data' => '{"paymentLink":"https://x"}',
            'logs' => '[]', 'status' => 'done', 'created_at' => '2026-11-13 12:00:00', 'updated_at' => '2026-11-13 12:00:05'],
        ['uuid' => 'b', 'entity' => 'App\\S', 'method' => 'm', 'parameters' => 'not json', 'data' => '',
            'logs' => '{"broken":', 'status' => 'new', 'created_at' => '2026-11-13 12:00:00', 'updated_at' => null],
    ]);
});

afterEach(function (): void {
    if (getenv('DELAYED_PGSQL_DATABASE')) {
        DB::connection('pgsql_upgrade')->statement('DROP TABLE IF EXISTS delayed_processes');
    }
});

it('converts a 1.x table in one pass and keeps every row', function (): void {
    $db = DB::connection('pgsql_upgrade');
    $upgrader = new V1SchemaUpgrader($db);

    expect($upgrader->pendingSteps())->toBe(['error_columns', 'status_check', 'column_types']);

    $done = $upgrader->upgrade('Europe/Moscow');
    expect($done)->toEqualCanonicalizing(['error_columns', 'column_types', 'status_check']);

    $types = collect($db->select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'delayed_processes'"))
        ->pluck('data_type', 'column_name');
    expect($types['parameters'])->toBe('jsonb')
        ->and($types['data'])->toBe('jsonb')
        ->and($types['logs'])->toBe('jsonb')
        ->and($types['created_at'])->toBe('timestamp with time zone')
        ->and($types['error_message'])->toBe('character varying');

    $a = $db->selectOne("SELECT parameters::text AS p, data::text AS d, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS c FROM delayed_processes WHERE uuid = 'a'");
    expect($a->p)->toBe('[1, "2"]')
        ->and($a->d)->toBe('{"paymentLink": "https://x"}')
        // 12:00 Moscow time is 09:00 UTC — not shifted by reading it as UTC
        ->and($a->c)->toBe('2026-11-13 09:00:00');

    // Invalid JSON survives as a JSON string, an empty value becomes []
    $b = $db->selectOne("SELECT parameters::text AS p, data::text AS d, logs::text AS l FROM delayed_processes WHERE uuid = 'b'");
    expect($b->p)->toBe('"not json"')
        ->and($b->d)->toBe('[]')
        ->and($b->l)->toBe('"{\"broken\":"');

    // The CHECK constraint already allows the statuses of 2.1+
    $db->table('delayed_processes')->where('uuid', 'b')->update(['status' => 'cancelled']);
    expect(fn () => $db->table('delayed_processes')->where('uuid', 'b')->update(['status' => 'bogus']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('is idempotent: a second run does nothing', function (): void {
    $db = DB::connection('pgsql_upgrade');
    $upgrader = new V1SchemaUpgrader($db);

    $upgrader->upgrade('UTC');

    expect($upgrader->isUpgraded())->toBeTrue()
        ->and($upgrader->upgrade('UTC'))->toBe([]);
});

it('lets the package migration run after it', function (): void {
    $db = DB::connection('pgsql_upgrade');
    (new V1SchemaUpgrader($db))->upgrade('UTC');

    $migration = require dirname(__DIR__, 2).'/databases/migrations/2026_03_11_000001_add_extended_columns_to_delayed_processes_table.php';
    $default = config('database.default');
    config(['database.default' => 'pgsql_upgrade']);
    DB::purge('pgsql_upgrade');

    try {
        $migration->up();
    } finally {
        config(['database.default' => $default]);
    }

    $columns = collect(DB::connection('pgsql_upgrade')->select("SELECT column_name FROM information_schema.columns WHERE table_name = 'delayed_processes'"))
        ->pluck('column_name')->all();
    expect($columns)->toContain('started_at', 'expires_at', 'progress');
});
