# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries for releases published before this file existed were reconstructed from
the tagged commit history.

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
