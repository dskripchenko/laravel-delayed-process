# Test Coverage Report

**Date:** 2026-03-11
**Driver:** PCOV
**PHP:** 8.5.3
**Framework:** Pest PHP

---

## Summary

| Metric | Value |
|--------|-------|
| Tests | 103 |
| Assertions | 244 |
| Duration | 1.71s |
| **Total Coverage** | **88.4%** |

---

## Coverage by File

### 100% Coverage (27 files)

| Component | File | Coverage |
|-----------|------|----------|
| Enums | `ProcessStatus` | 100.0% |
| Models | `DelayedProcess` | 100.0% |
| Contracts | `ProcessFactoryInterface` | 100.0% |
| Contracts | `ProcessLoggerInterface` | 100.0% |
| Contracts | `ProcessObserverInterface` | 100.0% |
| Contracts | `ProcessProgressInterface` | 100.0% |
| Contracts | `ProcessRunnerInterface` | 100.0% |
| Events | `ProcessCreated` | 100.0% |
| Events | `ProcessStarted` | 100.0% |
| Events | `ProcessCompleted` | 100.0% |
| Events | `ProcessFailed` | 100.0% |
| Services | `CallableResolver` | 100.0% |
| Services | `CallbackDispatcher` | 100.0% |
| Services | `DelayedProcessFactory` | 100.0% |
| Services | `DelayedProcessLogger` | 100.0% |
| Services | `DelayedProcessProgress` | 100.0% |
| Services | `DelayedProcessRunner` | 100.0% |
| Services | `EntityConfigResolver` | 100.0% |
| Exceptions | `EntityNotAllowedException` | 100.0% |
| Exceptions | `InvalidParametersException` | 100.0% |
| Resources | `DelayedProcessResource` | 100.0% |
| Commands | `ExpireProcessesCommand` | 100.0% |
| Commands | `UnstuckProcessesCommand` | 100.0% |
| Providers | `DelayedProcessServiceProvider` | 100.0% |

### High Coverage 85–99% (4 files)

| Component | File | Coverage | Uncovered Lines | Reason |
|-----------|------|----------|-----------------|--------|
| Builders | `DelayedProcessBuilder` | 97.9% | L108 | Concurrent race condition (affected=0 in claimForExecution) |
| Jobs | `DelayedProcessJob` | 94.1% | L54 | Unlisten in finally after real dispatch |
| Commands | `ClearOldDelayedProcessCommand` | 93.3% | L21 | Fallback `$days` value from config |
| Commands | `DelayedProcessCommand` | 86.5% | L26, 43, 61–64 | Infinite loop + sleep branches |

### Medium Coverage 50–84% (3 files)

| Component | File | Coverage | Uncovered Lines | Reason |
|-----------|------|----------|-----------------|--------|
| Components | `Events/Dispatcher` | 77.3% | L27–29, 33, 46 | QueuedClosure branches (unused in package) |
| Exceptions | `CallableResolutionException` | 66.7% | L23 | `notResolvable()` method — fallback, never called |
| Commands | `MigrateFromV1Command` | 52.2% | L33–35, 72–157 | MySQL/PG-specific SQL on SQLite |

---

## Test Distribution by File

### Unit Tests (15 files, 82 tests)

| File | Tests | Covers |
|------|-------|--------|
| `BuilderCancelTest` | 4 | `cancel()`, `whereUuids()` |
| `BuilderClaimTest` | 5 | `claimForExecution()` — atomic claim, null cases, ordering |
| `CallableResolverTest` | 7 | `CallableResolver`, mixed allowlist |
| `CallbackDispatcherTest` | 6 | `dispatch()` — disabled, null/empty URL, non-terminal, success, HTTP failure |
| `DelayedProcessFactoryTest` | 10 | `make()`, `makeWithCallback()`, events, validation, TTL, per-entity queue + connection |
| `DelayedProcessJobTest` | 3 | `handle()` with mocked dependencies, finally-guard, config |
| `DelayedProcessLoggerTest` | 7 | `log()`, `flush()`, buffer limit |
| `DelayedProcessProgressTest` | 3 | `setProgress()`, clamping 0–100 |
| `DelayedProcessResourceTest` | 5 | `toArray()` — done, non-terminal, error_message, truncation flag, null error |
| `DelayedProcessRunnerTest` | 16 | `run()` — success, error, retry, race condition, events, timing, truncation, callback, progress, normalizeResult, normalizeParameters |
| `DispatcherTest` | 5 | `listen()`, `unlisten()`, closure type inference, wildcard, multiple listeners |
| `EntityConfigResolverTest` | 6 | `isAllowed()`, `getEntityConfig()` |
| `ProcessStatusTest` | 5 | Enum values, transitions, `isTerminal()`, `isCancellable()` |

### Feature Tests (5 files, 21 tests)

| File | Tests | Covers |
|------|-------|--------|
| `ClearOldDelayedProcessCommandTest` | 4 | `delayed:clear` — terminal, expired, cancelled |
| `DelayedProcessCommandTest` | 3 | `delayed:process` — processing, max-iterations, failing |
| `ExpireProcessesCommandTest` | 4 | `delayed:expire` — expiration, skip no-expiry, skip terminal, dry-run |
| `MigrateFromV1CommandTest` | 6 | `delayed:migrate-v1` — no table, already migrated, schema, confirmation |
| `UnstuckProcessesCommandTest` | 4 | `delayed:unstuck` — reset stuck, skip fresh, dry-run |

---

## Uncoverable Sections

1. **`MigrateFromV1Command` (52.2%)** — `migratePostgres()`, `migrateMysql()`, `addCheckConstraint()` methods contain raw SQL specific to PG/MySQL. These branches are not executed on test SQLite.

2. **`Events/Dispatcher` (77.3%)** — `QueuedClosure` branches (L27–29, 33) are unused in the package.

3. **`CallableResolutionException::notResolvable()` (66.7%)** — fallback method, not called by the current implementation.

4. **`DelayedProcessCommand` sleep/infinite loop (86.5%)** — infinite loop with `sleep()` is not covered in unit tests.
