# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries for releases published before this file existed were reconstructed from
the tagged commit history.

## [Unreleased]

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
  process lifecycle, a real test suite and full documentation.

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
