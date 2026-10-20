# Upgrade Guide

Available in: [English](upgrade.md) | [Русский](../ru/upgrade.md) | [Deutsch](../de/upgrade.md) | [中文](../zh/upgrade.md)

Each section lists what changed between two versions and what to do about it,
newest first. Upgrading across several versions means applying every section
in between, from the bottom up.

- [2.1 → 2.2](#21--22)
- [2.0 → 2.1](#20--21)
- [1.x → 2.x](#1x--2x)

---

## 2.1 → 2.2

No schema or configuration changes.

**Named parameters are bound by name.** Before 2.2 a string-keyed parameter
set was always passed to the handler as one array argument. Now, when every
key names a parameter of the handler method, the keys are bound by name:

```php
$factory->make(ReportService::class, 'build', ...['from' => $from, 'to' => $to]);

// 2.1: build(['from' => ..., 'to' => ...])
// 2.2: build(from: ..., to: ...)
```

A handler declared as `handle(array $params)` keeps receiving the whole
array, unless the keys happen to match its parameter names: a parameter set
`['params' => ...]` sent to `handle(array $params)` is now bound by name.
A list is passed positionally, as before.

**2.1.2: `DelayedProcessRunner` constructor.** The fourth argument is now an
optional `ProcessProgressInterface` instead of a `DelayedProcessProgress`.
This matters only if you construct the runner yourself; resolving it from
the container needs no change.

---

## 2.0 → 2.1

Nothing to change in the code.

2.0.0 required PHP ^8.5 and Laravel 12. From 2.1.0 the package supports
PHP ^8.2 with Laravel 11, 12 and 13.

---

## 1.x → 2.x

2.0 is a rewrite. The table name, the job class and the Artisan commands kept
their names, but almost everything around them changed: the model lost its
behaviour to services, statuses became an enum, only allowlisted classes can
run, and the table gained columns, JSON types and indexes.

### Requirements

| | 1.x | 2.x |
|---|---|---|
| PHP | any the Laravel version allows | ^8.2 (2.0.0: ^8.5) |
| Laravel | 6 – 12 | 11, 12, 13 (2.0.0: 12 only) |
| `dskripchenko/php-array-helper` | required | not required |

The package no longer pulls in `dskripchenko/php-array-helper`. If your code
calls `array_is_associative()`, `array_merge_deep()` or other helpers from it,
require that package directly:

```bash
composer require dskripchenko/php-array-helper
```

### Step 1. Drain the queue and stop the workers

Jobs queued by 1.x reach a runner that checks the allowlist (step 3) and
writes columns that do not exist until step 4. Before deploying:

1. Stop creating new processes, or put the application into maintenance mode.
2. Let the queue workers and `delayed:process` finish what is in flight.
3. Stop the workers and the scheduler.

Processes left in `new` are picked up again after the upgrade. Processes
left in `wait` are reset by `php artisan delayed:unstuck` once the
upgrade is done.

### Step 2. Update the package

```bash
composer require dskripchenko/laravel-delayed-process:^2.2
```

### Step 3. Publish the configuration and fill in the allowlist

```bash
php artisan vendor:publish --tag=delayed-process-config
```

**This step is required.** In 2.x only classes listed in
`allowed_entities` can be run. The list is empty by default, so without it
every `make()` throws `EntityNotAllowedException`.

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\ReportService::class,
    // per-class queue settings
    \App\Services\HeavyExport::class => ['queue' => 'heavy', 'timeout' => 600],
],
```

To find the classes you use, search for `DelayedProcess::make(` in your code
and look at the `entity` column of existing rows:

```sql
SELECT DISTINCT entity, method FROM delayed_processes;
```

The handler must be a class with a public method. 1.x also accepted an empty
entity with a global function name, or a class without a method (an invokable).
2.x accepts neither: wrap such code in a class method.

Other settings (attempts, cleanup age, stuck timeout, job timeout and so on)
are described in the [configuration reference](../../README.md#configuration-reference).

### Step 4. Upgrade the table

**Order matters: run `delayed:migrate-v1` before `migrate`.**

```bash
php artisan delayed:migrate-v1 --force
php artisan migrate --force
```

`delayed:migrate-v1` brings the 1.x table to the 2.0 schema:

- adds `error_message` and `error_trace`;
- adds a CHECK constraint on `status` (PostgreSQL, MySQL 8.0.16+, MariaDB);
- PostgreSQL: converts `parameters`, `data`, `logs` to `jsonb` and
  `created_at`, `updated_at` to `timestamptz`, creates partial indexes;
- MySQL/MariaDB: converts the three columns to `json`, creates composite indexes;
- SQLite: adds the two columns only.

`migrate` then runs the package migration
`2026_03_11_000001_add_extended_columns_to_delayed_processes_table`, which
adds `started_at`, `duration_ms`, `callback_url`, `progress`, `expires_at` and
widens the CHECK constraint to `expired` and `cancelled`.

In the reverse order the upgrade breaks:

- **MySQL/MariaDB:** the migration places its columns after `error_trace`,
  which does not exist yet, and fails with "Unknown column".
- **PostgreSQL:** the migration succeeds, then `delayed:migrate-v1` adds the
  two columns and fails on the CHECK constraint the migration already created.
  Run again, it reports "Already migrated" because `error_message` now exists,
  and the `jsonb` conversion and the indexes are never applied.

If the deployment runs `migrate` on its own, run `delayed:migrate-v1`
before that step.

Check before running it on production data:

- **Time zone (PostgreSQL).** Timestamps are converted as UTC
  (`AT TIME ZONE 'UTC'`). If the application stored local time in
  `created_at`/`updated_at`, the converted values shift by the offset.
- **Invalid JSON.** The conversion fails on a row whose `parameters`,
  `data` or `logs` is not valid JSON. 1.x always wrote JSON there, but rows
  edited by hand are worth checking first.
- **Statuses.** The CHECK constraint accepts `new`, `wait`, `done`, `error`.
  Rows with any other status must be fixed before the upgrade.
- **Backup.** On MySQL the schema changes are not transactional: a failure
  halfway leaves the table partly upgraded. Back up the table first.

The 1.x create migration and the 2.x one have the same name, so Laravel
considers it already run and does not create the table again.

### Step 5. Update the code

#### Creating a process

`DelayedProcess::make()` is gone. Processes are created through
`ProcessFactoryInterface`, with the same arguments:

```php
// 1.x
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

$process = DelayedProcess::make(ReportService::class, 'build', $from, $to);

// 2.x
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;

$process = app(ProcessFactoryInterface::class)->make(ReportService::class, 'build', $from, $to);
```

Inject `ProcessFactoryInterface` in a constructor or controller method
instead of calling `app()` where you can. The factory checks the allowlist
and the method before saving, so a typo now fails at creation, not in the
worker.

`DelayedProcessFactory::makeWithCallback($entity, $method, $url, ...$params)`
also sends a webhook when the process finishes; see the README.

#### Removed model methods

| 1.x | 2.x |
|---|---|
| `DelayedProcess::make($entity, $method, ...$params)` | `ProcessFactoryInterface::make()` |
| `$process->run()` | `app(ProcessRunnerInterface::class)->run($process)` |
| `$process->log($level, $message, $context)` | write through `Log::...()` inside the handler; the job collects the lines |
| `$process->toResponse()` | see [Responses](#responses) |
| `DelayedProcess::STATUS_NEW` … `STATUS_ERROR` | `ProcessStatus::New` … `ProcessStatus::Error` |
| `DelayedProcess::DEFAULT_ATTEMPTS` | `config('delayed-process.default_attempts')` |

#### Status is an enum

`$process->status` is now a `ProcessStatus` enum, not a string. Code that
compares or prints it as a string stops working:

```php
// 1.x
if ($process->status === DelayedProcess::STATUS_ERROR) { ... }
strtoupper($process->status);

// 2.x
use Dskripchenko\DelayedProcess\Enums\ProcessStatus;

if ($process->status === ProcessStatus::Error) { ... }
strtoupper($process->status->value);
```

Database queries can keep using the string values (`where('status', 'new')`),
or the builder scopes: `whereNew()`, `whereTerminal()`, `whereStuck()`,
`whereExpired()`, `whereUuids()`, `olderThanDays()`.

Two statuses were added, `expired` and `cancelled`. Both are terminal: code
that treats "not `done` and not `error`" as "still running" has to use
`$process->status->isTerminal()` instead.

#### Responses

`Resources\DelayedProcess` was replaced by `Resources\DelayedProcessResource`
with a different shape. 1.x `toResponse()` returned:

```json
{ "delayed": { "uuid": "..." } }
```

To keep this response for an endpoint that starts a process, build it
yourself. The bundled frontend interceptors look for `delayed.uuid`:

```php
return response()->json([
    'delayed' => ['uuid' => $process->uuid, 'status' => $process->status->value],
]);
```

`DelayedProcessResource` is meant for the status endpoint and returns
`uuid`, `status`, `data` (only for terminal statuses), `error_message`,
`progress`, `started_at`, `duration_ms`, `attempts`, `current_try`,
`created_at`, `updated_at`.

#### Extending the model

A subclass of `DelayedProcess` keeps working for queries and admin screens,
with these differences:

- the factory creates the base `DelayedProcess`, not your subclass, so model
  events and accessors defined on the subclass do not run on creation;
- casts are declared in `casts()`. A `$casts` property on the subclass is
  merged with them, it does not replace the `status` enum cast;
- `$fillable` is now `entity`, `method`, `parameters`, `callback_url`.
  `uuid`, `status`, `data`, `logs`, `attempts`, `try` are no longer
  mass-assignable: `create([... 'status' => ...])` silently drops them.

### Behaviour changes

These need no code changes but can change what you observe.

| | 1.x | 2.x |
|---|---|---|
| Errors caught | `Exception` only; an `Error` (`TypeError` and so on) left the process in `wait` | any `Throwable`; the message and trace go to `error_message`/`error_trace` |
| Running a finished process again | `run()` on a `done`/`error` process ran it again | finished processes are skipped |
| Two workers, one process | could both run it | the process is claimed with an atomic `UPDATE` |
| `uuid` | UUID v4 | UUID v7 (old values stay valid) |
| Log entries | keyed by time, `Level`/`Date`/`Message`/`Context` | a list of `level`/`date`/`message`, written when the run ends |
| Log context | always stored | dropped unless `log_sensitive_context` is `true` |
| `created_at` in JSON | `Y-m-d H:i:s` | ISO 8601 (the model's default serialization) |
| Job retries | the worker's `--tries` | `job.tries` in the config, 1 by default |

Rows written by 1.x keep their log entries in the old format. Code that
reads `logs` has to accept both.

#### `delayed:process` no longer exits

In 1.x `delayed:process` processed every `new` process and exited. In 2.x it
runs until stopped, sleeping `command.sleep` seconds when the queue is empty.

If the scheduler runs it every minute, each run now stays alive and the
copies accumulate. Choose one:

```php
// keep it scheduled: exit once nothing is left
$schedule->command('delayed:process --max-iterations=1000')
    ->everyMinute()
    ->withoutOverlapping();
```

or run `php artisan delayed:process` as a long-lived process under
Supervisor or systemd and remove it from the scheduler.

#### New commands

Add the ones you need to the scheduler:

```php
$schedule->command('delayed:clear')->daily();      // as in 1.x
$schedule->command('delayed:unstuck')->everyTenMinutes();
$schedule->command('delayed:expire')->everyMinute(); // only with default_ttl_minutes
```

`delayed:clear` now takes `--days` and `--chunk` and deletes in chunks; the
default age is `clear_after_days` (30, as in 1.x).

### Checklist

- [ ] queue drained, workers stopped
- [ ] `composer require dskripchenko/laravel-delayed-process:^2.2`
- [ ] `dskripchenko/php-array-helper` required directly, if its helpers are used
- [ ] config published, every handler class in `allowed_entities`
- [ ] table backed up
- [ ] `delayed:migrate-v1 --force`, **then** `migrate --force`
- [ ] `DelayedProcess::make()` → `ProcessFactoryInterface::make()`
- [ ] `STATUS_*` constants and string comparisons → `ProcessStatus`
- [ ] `toResponse()` → explicit `delayed.uuid` response
- [ ] code reading `logs` handles both formats
- [ ] `delayed:process` scheduled with `--max-iterations` or run as a daemon
- [ ] `delayed:unstuck` run once after the deploy, then scheduled
