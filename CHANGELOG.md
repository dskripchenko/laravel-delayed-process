# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries for releases published before this file existed were reconstructed from
the tagged commit history.

## [Unreleased]

## [2.3.0] - 2026-11-13

Makes the 1.x → 2.x upgrade possible for applications that relied on 1.x
behaviour, and lets a deployment that only runs `migrate` do it.

### Added
- `Schema\V1SchemaUpgrader`: the logic of `delayed:migrate-v1` as a class an
  application migration can call (`app(V1SchemaUpgrader::class)->upgrade(config('app.timezone'))`).
- `delayed:migrate-v1 --timezone=…`: the zone the 1.x timestamps were written in;
  defaults to `app.timezone`.
- `delayed-process.job.class`: the job class the factory dispatches, for an
  application that carries its own request context into the worker. It takes the
  process as its only constructor argument.
- Upgrade guide (`docs/{en,ru,de,zh}/upgrade.md`): every change from 1.x to 2.x
  that needs action, in the order to apply it, plus the changes between 2.x
  minor versions. Linked from the installation section of the README.

### Fixed
- Handlers are called from a non-strict file (`Support\LooseInvoker`): a handler
  `build(int $id)` given the string `"42"` — what a JSON body or a form usually
  carries — failed with a `TypeError`, because the runner is a strict file. 1.x
  coerced the value. Correctly typed arguments pass exactly as before.
- A handler returning an API resource, an `Arrayable` or a `JsonSerializable`
  stored it wrapped in a list (`[{...}]`); it is now stored as its array, as 1.x did.
- `delayed:migrate-v1`:
  - read the naive 1.x timestamps as UTC (`AT TIME ZONE 'UTC'`), shifting them by
    the application's offset; they are now read in `--timezone`;
  - rewrote the table five times on PostgreSQL; the column conversion is now one
    `ALTER TABLE`, with `lock_timeout`;
  - failed on the first value that was not valid JSON; such a value is kept as a
    JSON string (an empty one becomes `[]`);
  - said «Already migrated» as soon as `error_message` existed, even when the
    columns were still text; every step now checks the schema and the command is
    safe to run again after a partial failure;
  - added a CHECK constraint that the 2.1 migration had to replace; it now allows
    `expired` and `cancelled` from the start.
- The README listed PHP ^8.5 and Laravel 12 as requirements; since 2.1.0 the
  package supports PHP ^8.2 with Laravel 11, 12 and 13.

## [2.2.0] - 2026-10-02

### Fixed
- A process created with named parameters, such as `make($entity, $method, ...['model' => $class])`,
  failed with a `TypeError`: the runner passed the whole associative array as the handler's
  first argument instead of binding `$model`. A string-keyed parameter set whose every key
  names a handler parameter is now passed by name: key order does not matter, parameters
  left out take their defaults, and a required class- or interface-typed parameter is
  resolved from the container. An empty parameter set is bound the same way. A list is
  still passed positionally, and any other associative array (a key the handler does not
  declare, or mixed integer and string keys) is still passed whole as one argument, so
  handlers declared as `handle(array $params)` keep working.

## [2.1.2] - 2026-10-02

### Fixed
- A handler could not report progress: the runner kept a private progress tracker and the
  container handed handlers a fresh one with no process attached, so `setProgress()` was a
  no-op and pollers saw only 0 and then 100. The tracker is now a scoped binding shared by
  the runner and the handler, attached for the duration of the run and detached afterwards.
- Log lines a handler writes while running through `DelayedProcessJob` are stored on the
  process again: the job and the runner now share one scoped logger instead of two.

### Changed
- `DelayedProcessRunner` takes an optional `ProcessProgressInterface` (defaulting to the
  container binding) instead of a `DelayedProcessProgress`, and
  `DelayedProcessProgress::setProcess()` accepts `null` to detach.

## [2.1.1] - 2026-07-20

### Added
- GitHub Actions pipeline: PHP 8.2-8.5 against Laravel 11, 12 and 13.

### Removed
- `roave/security-advisories` from the development dependencies: it refuses to install
  alongside Laravel 11, which is still supported here.

## [2.1.0] - 2026-07-20

### Changed
- Supported versions moved to the canonical matrix: PHP ^8.2 with Laravel 11, 12 and 13,
  and Pest 3 or 4, which is what makes the Laravel 13 test run possible.

## [2.0.0] - 2026-03-11

### Changed
- The package was reworked end to end: new architecture, an event model around the
  process lifecycle, a real test suite and full documentation. Breaking for 1.x
  users; see the [upgrade guide](docs/en/upgrade.md#1x--2x).

### Added
- Frontend integration guide, also in the Russian and Chinese documentation.

## [1.1.3] - 2026-01-21

### Changed
- Storage queries moved from raw SQL to Eloquent.

## [1.1.2] - 2025-03-12

### Added
- Laravel 12 support.

## [1.1.1] - 2024-07-09

### Added
- Laravel 11 support.

## [1.1.0] - 2023-10-04

### Added
- Laravel 10 support.

## [1.0.3] - 2023-04-07

### Fixed
- Restored compatibility with Laravel 9.

## [1.0.2] - 2023-04-07

### Fixed
- Corrected the namespace of the queued job.

## [1.0.1] - 2023-04-06

### Fixed
- Corrected the published migration.

## [1.0.0] - 2023-02-27

### Added
- First release: long-running work handed to a background process and tracked through the database.
