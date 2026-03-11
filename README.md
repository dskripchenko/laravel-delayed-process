# Laravel Delayed Process

[![Packagist Version](https://img.shields.io/packagist/v/dskripchenko/laravel-delayed-process)](https://packagist.org/packages/dskripchenko/laravel-delayed-process)
[![License](https://img.shields.io/packagist/l/dskripchenko/laravel-delayed-process)](LICENSE.md)
[![PHP Version](https://img.shields.io/packagist/dependency-v/dskripchenko/laravel-delayed-process/php)](composer.json)
[![Laravel Version](https://img.shields.io/packagist/dependency-v/dskripchenko/laravel-delayed-process/laravel/framework)](composer.json)

**Language:** [English](README.md) | [Русский](docs/README.ru.md) | [Deutsch](docs/README.de.md) | [中文](docs/README.zh.md)

Asynchronous execution of long-running operations in Laravel with UUID-based tracking, automatic retry, security allowlist, and transparent frontend interceptors for Axios, Fetch, and XHR.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Architecture](#architecture)
- [Process Lifecycle](#process-lifecycle)
- [Project Structure](#project-structure)
- [Backend API](#backend-api)
- [Frontend Interceptors](#frontend-interceptors)
- [Configuration Reference](#configuration-reference)
- [Database Schema](#database-schema)
- [Security](#security)
- [Cookbook](#cookbook)
- [License](#license)

---

## Features

- **Async Processing** — offload heavy operations to a queue, return UUID immediately
- **UUID Tracking** — every process gets a UUIDv7 for status polling
- **Automatic Retry** — configurable max attempts with error capture on final failure
- **Security Allowlist** — only explicitly allowed entity classes can be executed
- **Frontend Interceptors** — transparent Axios, Fetch, and XHR interceptors that auto-poll until completion
- **Batch Polling** — `BatchPoller` class for polling multiple UUIDs in a single request
- **Loop Prevention** — `X-Delayed-Process-Poll` header prevents interceptors from re-intercepting poll requests
- **Lifecycle Events** — `ProcessCreated`, `ProcessStarted`, `ProcessCompleted`, `ProcessFailed` events for observability
- **Progress Tracking** — 0-100% progress updates via `ProcessProgressInterface`
- **Webhook Callbacks** — HTTP POST notifications to `callback_url` on terminal status
- **TTL / Expiration** — automatic process expiration via `expires_at` + `delayed:expire` command
- **Cancellation** — cancel processes in `new`/`wait` status via builder
- **Per-entity Queue Config** — configure queue, connection, and timeout per entity class
- **Artisan Commands** — `delayed:process`, `delayed:clear`, `delayed:unstuck`, `delayed:expire`, `delayed:migrate-v1` (legacy migration)
- **Structured Logging** — captures all `MessageLogged` events during execution, configurable buffer limit
- **Atomic Claiming** — race-condition-safe process claiming via atomic UPDATE
- **PostgreSQL Optimized** — partial indexes, JSONB columns, TIMESTAMPTZ; MySQL/MariaDB also supported

---

## Requirements

| Dependency | Version |
|------------|---------|
| PHP | ^8.5 |
| Laravel | ^12.0 |
| Database | PostgreSQL (recommended) or MySQL/MariaDB |

---

## Installation

```bash
composer require dskripchenko/laravel-delayed-process
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=delayed-process-config
```

Run the migration:

```bash
php artisan migrate
```

Register allowed entities in `config/delayed-process.php`:

```php
'allowed_entities' => [
    \App\Services\ReportService::class,
    \App\Services\ExportService::class,
],
```

---

## Quick Start

### 1. Create a Handler

```php
<?php

declare(strict_types=1);

namespace App\Services;

final class ReportService
{
    public function generate(int $userId, string $format): array
    {
        // Long-running operation (30+ seconds)
        $data = $this->buildReport($userId, $format);

        return ['url' => $data['url'], 'rows' => $data['count']];
    }
}
```

### 2. Trigger a Delayed Process (Backend)

```php
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;

final class ReportController extends ApiController
{
    public function generate(
        Request $request,
        ProcessFactoryInterface $factory,
    ): JsonResponse {
        $process = $factory->make(
            ReportService::class,
            'generate',
            $request->integer('user_id'),
            $request->string('format'),
        );

        return response()->json([
            'success' => true,
            'payload' => [
                'delayed' => ['uuid' => $process->uuid, 'status' => $process->status->value],
            ],
        ]);
    }
}
```

### 3. Status Endpoint

```php
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Dskripchenko\DelayedProcess\Resources\DelayedProcessResource;

Route::get('/api/common/delayed-process/status', function (Request $request) {
    $process = DelayedProcess::query()
        ->where('uuid', $request->query('uuid'))
        ->firstOrFail();

    return DelayedProcessResource::make($process);
});
```

### 4. Frontend — Axios Interceptor

```typescript
import axios from 'axios';
import { applyAxiosInterceptor } from './delayed-process';

const api = axios.create({ baseURL: '/api' });

applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 3000,
});

// Usage — polling is fully automatic
const response = await api.post('/reports/generate', { user_id: 1, format: 'pdf' });
console.log(response.data.payload); // { url: '...', rows: 150 }
```

---

## Architecture

### Lifecycle Overview

```
Client                           Server                           Queue Worker
  │                               │                                   │
  ├─── POST /api/reports ────────►│                                   │
  │                               ├── Factory.make()                  │
  │                               │   ├─ Validate entity+method       │
  │                               │   ├─ INSERT (status=new)          │
  │                               │   └─ Dispatch Job ───────────────►│
  │◄── { delayed: { uuid } } ─────┤                                   │
  │                               │                                   ├── Claim (status=wait)
  │                               │                                   ├── Resolve callable
  │                               │                                   ├── Execute handler
  │                               │                                   ├── Save result (status=done)
  │─── GET /status?uuid=... ─────►│                                   │
  │◄── { status: "wait" } ────────┤                                   │
  │                               │                                   │
  │─── GET /status?uuid=... ─────►│                                   │
  │◄── { status: "done", data } ──┤                                   │
  │                               │                                   │
  ▼ Interceptor returns data      │                                   │
```

### Component Overview

| Component | Class | Purpose |
|-----------|-------|---------|
| **Model** | `DelayedProcess` | Eloquent model — stores process state, result, logs |
| **Builder** | `DelayedProcessBuilder` | Custom Eloquent builder — `whereNew()`, `whereStuck()`, `claimForExecution()` |
| **Factory** | `DelayedProcessFactory` | Creates process, validates entity, dispatches job |
| **Runner** | `DelayedProcessRunner` | Executes process — claim, resolve, run, handle errors |
| **Logger** | `DelayedProcessLogger` | Buffers log entries during execution, flushes to model |
| **Job** | `DelayedProcessJob` | Laravel queue job — bridges queue to runner |
| **Resource** | `DelayedProcessResource` | JSON response format for status endpoint |
| **Resolver** | `CallableResolver` | Validates and resolves entity+method to callable |
| **EntityConfigResolver** | `EntityConfigResolver` | Resolves per-entity queue/connection/timeout config |
| **CallbackDispatcher** | `CallbackDispatcher` | Sends webhook POST on terminal status |
| **Progress** | `DelayedProcessProgress` | Updates process progress (0-100%) |

### Contracts

| Interface | Default Implementation |
|-----------|----------------------|
| `ProcessFactoryInterface` | `DelayedProcessFactory` |
| `ProcessRunnerInterface` | `DelayedProcessRunner` |
| `ProcessLoggerInterface` | `DelayedProcessLogger` |
| `ProcessProgressInterface` | `DelayedProcessProgress` |

All bindings are registered in `DelayedProcessServiceProvider`. Override via Laravel's service container for custom implementations.

### Events

| Event | Fired When | Properties |
|-------|------------|------------|
| `ProcessCreated` | After `Factory::make()` saves process | `process` |
| `ProcessStarted` | After Runner claims and starts execution | `process` |
| `ProcessCompleted` | After successful execution | `process` |
| `ProcessFailed` | After exception in execution | `process`, `exception` |

---

## Process Lifecycle

### Status Transitions

```
                                           ┌───────────┐
                                cancel     │ CANCELLED │
                            ┌─────────────►└───────────┘
                            │
┌─────┐     claim      ┌────┴─┐     success     ┌──────┐
│ NEW ├───────────────►│ WAIT ├────────────────►│ DONE │
└──┬──┘                └──┬───┘                 └──────┘
   ▲                      │
   │     try < attempts   │ failure
   └──────────────────────┤
   │                      │ try >= attempts
   │ expires_at reached   ▼
   │                   ┌───────┐
   └──────┐            │ ERROR │
          ▼            └───────┘
     ┌─────────┐
     │ EXPIRED │
     └─────────┘
```

| Status | Value | Description |
|--------|-------|-------------|
| **New** | `new` | Created, awaiting execution. Eligible for claiming. |
| **Wait** | `wait` | Claimed by a worker, currently executing. Blocks re-entry. |
| **Done** | `done` | Successfully completed. Result stored in `data`. Terminal. |
| **Error** | `error` | All retry attempts exhausted. Error details in `error_message` / `error_trace`. Terminal. |
| **Expired** | `expired` | TTL exceeded before completion. Marked by `delayed:expire`. Terminal. |
| **Cancelled** | `cancelled` | Manually cancelled via Builder. Terminal. |

### Retry Logic

1. Worker atomically claims process: `UPDATE ... SET status='wait', try=try+1 WHERE status='new'`
2. Handler executes
3. On success: `status → done`, result saved to `data`
4. On failure:
   - If `try < attempts`: `status → new` (eligible for retry)
   - If `try >= attempts`: `status → error`, error details saved

---

## Project Structure

```
src/
├── Builders/
│   └── DelayedProcessBuilder.php       # Custom Eloquent builder (whereNew, whereExpired, cancel, claimForExecution)
├── Components/
│   └── Events/
│       └── Dispatcher.php              # Event dispatcher with listen/unlisten by ID
├── Console/
│   └── Commands/
│       ├── DelayedProcessCommand.php       # delayed:process — synchronous queue worker
│       ├── ClearOldDelayedProcessCommand.php # delayed:clear — delete old terminal processes
│       ├── ExpireProcessesCommand.php      # delayed:expire — mark expired processes
│       ├── UnstuckProcessesCommand.php     # delayed:unstuck — reset stuck processes
│       └── MigrateFromV1Command.php        # delayed:migrate-v1 — legacy schema migration
├── Contracts/
│   ├── ProcessFactoryInterface.php     # Factory contract
│   ├── ProcessRunnerInterface.php      # Runner contract
│   ├── ProcessLoggerInterface.php      # Logger contract
│   ├── ProcessProgressInterface.php    # Progress tracking contract
│   └── ProcessObserverInterface.php    # Observer contract (onCreated, onStarted, etc.)
├── Enums/
│   └── ProcessStatus.php               # new | wait | done | error | expired | cancelled
├── Events/
│   ├── ProcessCreated.php              # Fired after factory creates process
│   ├── ProcessStarted.php             # Fired after runner claims process
│   ├── ProcessCompleted.php           # Fired after successful execution
│   └── ProcessFailed.php             # Fired on execution failure
├── Exceptions/
│   ├── CallableResolutionException.php # Class/method not found
│   ├── EntityNotAllowedException.php   # Entity not in allowlist
│   └── InvalidParametersException.php  # Non-serializable parameters
├── Jobs/
│   └── DelayedProcessJob.php           # Queue job — runs process via runner
├── Models/
│   └── DelayedProcess.php              # Eloquent model with UUIDv7, progress, TTL, callbacks
├── Providers/
│   └── DelayedProcessServiceProvider.php # Registers bindings, migrations, commands
├── Resources/
│   └── DelayedProcessResource.php      # JSON response resource
└── Services/
    ├── CallableResolver.php            # Validates allowlist + resolves callable
    ├── CallbackDispatcher.php          # Webhook POST on terminal status
    ├── DelayedProcessFactory.php       # Creates process + dispatches job + events
    ├── DelayedProcessLogger.php        # Buffers logs with configurable limit
    ├── DelayedProcessProgress.php      # Progress tracking (0-100%)
    ├── DelayedProcessRunner.php        # Claims + executes + events + callbacks
    └── EntityConfigResolver.php        # Per-entity queue/connection/timeout config

resources/js/delayed-process/
├── index.ts                            # Public exports
├── types.ts                            # TypeScript types, BatchPoller types, DelayedProcessError
├── core/
│   ├── config.ts                       # Default config + CSRF auto-detection
│   ├── poller.ts                       # Poll loop with timeout and abort
│   └── batch-poller.ts                # BatchPoller — poll multiple UUIDs at once
├── axios/
│   └── interceptor.ts                  # Axios response interceptor
├── fetch/
│   └── patch.ts                        # window.fetch monkey-patch
└── xhr/
    └── patch.ts                        # XMLHttpRequest monkey-patch (double-patch guard)
```

---

## Backend API

### Creating Processes

Use `ProcessFactoryInterface` (resolved via DI):

```php
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;

$process = $factory->make(
    entity: \App\Services\ExportService::class,
    method: 'exportCsv',
    // Variadic parameters passed to the handler method:
    $userId,
    $filters,
);
```

**What happens inside `make()`:**

1. Validates entity is in `allowed_entities` config
2. Validates class and method exist
3. Validates parameters are JSON-serializable
4. Creates `DelayedProcess` model in a DB transaction (auto-generates UUIDv7, sets `status=new`, `expires_at` from TTL config)
5. Configures job queue/connection/timeout from per-entity config
6. Dispatches `DelayedProcessJob` to the queue
7. Fires `ProcessCreated` event
8. Returns the persisted model

#### Creating with Webhook Callback

```php
$process = $factory->makeWithCallback(
    entity: \App\Services\ExportService::class,
    method: 'exportCsv',
    callbackUrl: 'https://your-app.com/webhooks/process-done',
    $userId,
);
```

When the process reaches a terminal status (`done`, `error`, `expired`, `cancelled`), an HTTP POST is sent to the `callbackUrl` with `{uuid, status, data}`.

#### Per-entity Queue Configuration

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\LightService::class,                           // default queue
    \App\Services\HeavyService::class => [                       // custom queue
        'queue' => 'heavy',
        'connection' => 'redis',
        'timeout' => 600,
    ],
],
```

### Status Endpoint Response

`DelayedProcessResource` returns:

```json
{
    "uuid": "019450a1-b2c3-7def-8901-234567890abc",
    "status": "done",
    "data": { "url": "/exports/report.csv", "rows": 1500 },
    "progress": 100,
    "started_at": "2026-03-11T10:30:01+00:00",
    "duration_ms": 44200,
    "attempts": 5,
    "current_try": 1,
    "created_at": "2026-03-11T10:30:00+00:00",
    "updated_at": "2026-03-11T10:30:45+00:00"
}
```

Notes:
- `data` is only included when `status` is terminal (`done`, `error`, `expired`, `cancelled`)
- `error_message` and `is_error_truncated` are only included when an error exists
- `progress` (0-100) reflects execution progress
- `started_at` and `duration_ms` track execution timing

### Artisan Commands

#### `delayed:process` — Synchronous Worker

Processes delayed tasks without requiring a queue worker. Useful for development or single-server deployments.

```bash
php artisan delayed:process
php artisan delayed:process --max-iterations=100
php artisan delayed:process --sleep=10
```

| Option | Default | Description |
|--------|---------|-------------|
| `--max-iterations` | `0` (infinite) | Stop after N processes. `0` = run forever. |
| `--sleep` | `5` | Seconds to sleep when no processes are found. |

#### `delayed:clear` — Cleanup Old Processes

Deletes terminal (`done` / `error`) processes older than a specified number of days.

```bash
php artisan delayed:clear
php artisan delayed:clear --days=7
php artisan delayed:clear --chunk=1000
```

| Option | Default | Description |
|--------|---------|-------------|
| `--days` | `30` | Delete processes older than N days. |
| `--chunk` | `500` | Batch delete size for memory efficiency. |

#### `delayed:unstuck` — Reset Stuck Processes

Resets processes stuck in `wait` status back to `new` so they can be retried.

```bash
php artisan delayed:unstuck
php artisan delayed:unstuck --timeout=30
php artisan delayed:unstuck --dry-run
```

| Option | Default | Description |
|--------|---------|-------------|
| `--timeout` | `60` | Consider processes stuck after N minutes in `wait`. |
| `--dry-run` | `false` | List stuck processes without resetting them. |

#### `delayed:expire` — Expire TTL Processes

Marks processes whose `expires_at` has passed as `expired`.

```bash
php artisan delayed:expire
php artisan delayed:expire --dry-run
```

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | `false` | Show count without modifying. |

#### `delayed:migrate-v1` — Legacy Migration

Upgrades the database schema from the legacy structure. Adds `error_message` / `error_trace` columns, converts columns to JSONB (PostgreSQL) or JSON (MySQL), creates partial/composite indexes, and adds CHECK constraint.

```bash
php artisan delayed:migrate-v1
php artisan delayed:migrate-v1 --force
```

---

## Frontend Interceptors

The `resources/js/delayed-process/` module provides transparent interceptors that automatically detect delayed process responses and poll until completion.

### How It Works

1. Your API returns a response containing `{ payload: { delayed: { uuid: "..." } } }`
2. The interceptor detects the UUID
3. It starts polling the status endpoint: `GET {statusUrl}?uuid={uuid}`
4. When `status` becomes `done`, the interceptor replaces the response payload with the result data
5. When `status` becomes `error`, `expired`, or `cancelled`, the interceptor throws a `DelayedProcessError`
6. Polling requests include `X-Delayed-Process-Poll: 1` header to prevent infinite loops

### File Structure

| File | Purpose |
|------|---------|
| `index.ts` | Public exports |
| `types.ts` | TypeScript types, `BatchPollerConfig`, `DelayedProcessError` |
| `core/config.ts` | Default config, CSRF auto-detection, `resolveConfig()` |
| `core/poller.ts` | `pollUntilDone()` — core polling loop with timeout and abort |
| `core/batch-poller.ts` | `BatchPoller` — poll multiple UUIDs in a single request |
| `axios/interceptor.ts` | `applyAxiosInterceptor()` — Axios response interceptor |
| `fetch/patch.ts` | `patchFetch()` — monkey-patches `window.fetch` |
| `xhr/patch.ts` | `patchXHR()` — monkey-patches `XMLHttpRequest` (double-patch guard) |

### Axios Interceptor

```typescript
import axios from 'axios';
import { applyAxiosInterceptor } from './delayed-process';

const api = axios.create({ baseURL: '/api' });

const interceptorId = applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 2000,
    maxAttempts: 50,
    timeout: 120_000,
    onPoll: (uuid, attempt) => {
        console.log(`Polling ${uuid}, attempt ${attempt}`);
    },
});

// To remove: api.interceptors.response.eject(interceptorId);
```

### Fetch Patch

```typescript
import { patchFetch } from './delayed-process';

const unpatch = patchFetch({
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 3000,
});

// All fetch() calls now auto-poll delayed processes
const response = await fetch('/api/reports/generate', { method: 'POST' });
const data = await response.json();
console.log(data.payload); // Resolved result, not the UUID

// To restore original fetch:
unpatch();
```

### XHR Patch

```typescript
import { patchXHR } from './delayed-process';

const unpatch = patchXHR({
    statusUrl: '/api/common/delayed-process/status',
});

const xhr = new XMLHttpRequest();
xhr.open('POST', '/api/reports/generate');
xhr.onload = function () {
    const data = JSON.parse(this.responseText);
    console.log(data.payload); // Resolved result
};
xhr.send();

// To restore original XHR:
unpatch();
```

### DelayedProcessConfig

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `statusUrl` | `string` | `'/api/common/delayed-process/status'` | URL for polling process status |
| `pollingInterval` | `number` | `3000` | Milliseconds between poll requests |
| `maxAttempts` | `number` | `100` | Maximum number of poll attempts |
| `timeout` | `number` | `300000` | Total timeout in milliseconds (5 min) |
| `headers` | `Record<string, string>` | `{}` | Extra headers for poll requests |
| `onPoll` | `(uuid: string, attempt: number) => void` | `undefined` | Callback invoked on each poll |

CSRF token from `<meta name="csrf-token">` is automatically included in poll requests.

### Batch Poller

For polling multiple processes at once (e.g., bulk operations):

```typescript
import { BatchPoller } from './delayed-process';

const poller = new BatchPoller({
    batchStatusUrl: '/api/common/delayed-process/batch-status',
    pollingInterval: 3000,
    timeout: 300_000,
    maxAttempts: 100,
    headers: {},
});

const results = await Promise.all([
    poller.add(uuid1),
    poller.add(uuid2),
    poller.add(uuid3),
]);
```

### DelayedProcessError

Thrown when a process completes with `error`, `expired`, or `cancelled` status, or polling times out.

```typescript
import { DelayedProcessError } from './delayed-process';

try {
    const response = await api.post('/api/reports/generate');
} catch (error) {
    if (error instanceof DelayedProcessError) {
        console.error(error.uuid);         // Process UUID
        console.error(error.status);       // 'error' | 'expired' | 'cancelled'
        console.error(error.errorMessage); // Server-side error message
    }
}
```

### Loop Prevention

All polling requests include the header `X-Delayed-Process-Poll: 1`. The interceptors check for this header and skip interception on poll requests, preventing infinite polling loops.

---

## Configuration Reference

File: `config/delayed-process.php`

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `allowed_entities` | `array` | `[]` | FQCN allowlist — string values or `Entity::class => [config]` keyed arrays |
| `default_attempts` | `int` | `5` | Maximum retry attempts before marking as `error` |
| `clear_after_days` | `int` | `30` | `delayed:clear` deletes terminal processes older than this |
| `stuck_timeout_minutes` | `int` | `60` | `delayed:unstuck` considers `wait` processes stuck after this |
| `log_sensitive_context` | `bool` | `false` | Include log context arrays in process logs |
| `log_buffer_limit` | `int` | `500` | Max log entries in memory buffer per process (0 = unlimited) |
| `callback.enabled` | `bool` | `false` | Enable webhook POST on terminal status |
| `callback.timeout` | `int` | `10` | Webhook HTTP timeout in seconds |
| `default_ttl_minutes` | `int\|null` | `null` | Default TTL for new processes (`null` = no expiration) |
| `job.timeout` | `int` | `300` | Queue job timeout in seconds |
| `job.tries` | `int` | `1` | Queue job retry attempts (separate from process attempts) |
| `job.backoff` | `array` | `[30, 60, 120]` | Queue job backoff delays in seconds |
| `command.sleep` | `int` | `5` | `delayed:process` sleep when idle (seconds) |
| `command.max_iterations` | `int` | `0` | `delayed:process` iteration limit (`0` = infinite) |
| `command.throttle` | `int` | `100000` | `delayed:process` throttle between iterations (microseconds) |
| `clear_chunk_size` | `int` | `500` | `delayed:clear` batch delete size |

---

## Database Schema

### Table: `delayed_processes`

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | `bigint` PK | auto-increment | Primary key |
| `uuid` | `string(36)` UNIQUE | auto (UUIDv7) | Unique process identifier |
| `entity` | `string` nullable | `NULL` | FQCN of handler class |
| `method` | `string` | — | Handler method name |
| `parameters` | `jsonb` / `json` | `[]` | Serialized invocation arguments |
| `data` | `jsonb` / `json` | `[]` | Execution result payload |
| `logs` | `jsonb` / `json` | `[]` | Captured log entries |
| `status` | `string` | `'new'` | Process status (`new`, `wait`, `done`, `error`, `expired`, `cancelled`) |
| `attempts` | `tinyint unsigned` | `5` | Maximum retry attempts |
| `try` | `tinyint unsigned` | `0` | Current attempt number |
| `error_message` | `string(1000)` nullable | `NULL` | Last error message (truncated with indicator) |
| `error_trace` | `text` nullable | `NULL` | Last error stack trace |
| `started_at` | `timestamptz` nullable | `NULL` | Execution start time |
| `duration_ms` | `bigint unsigned` nullable | `NULL` | Execution duration in milliseconds |
| `callback_url` | `string(2048)` nullable | `NULL` | Webhook URL for terminal status notification |
| `progress` | `tinyint unsigned` | `0` | Execution progress (0-100) |
| `expires_at` | `timestamptz` nullable | `NULL` | Process expiration time (TTL) |
| `created_at` | `timestamptz` | NOW | Creation timestamp |
| `updated_at` | `timestamptz` | NOW | Last update timestamp |

### Indexes

**PostgreSQL** (partial indexes for optimal performance):

| Index | Condition |
|-------|-----------|
| `(status, try)` | `WHERE status = 'new'` |
| `(created_at)` | `WHERE status IN ('done', 'error', 'expired', 'cancelled')` |
| `(updated_at)` | `WHERE status = 'wait'` |
| `(expires_at)` | `WHERE status IN ('new', 'wait') AND expires_at IS NOT NULL` |

**MySQL / MariaDB** (composite indexes):

| Index |
|-------|
| `(status, try)` |
| `(status, created_at)` |
| `(status, updated_at)` |

### Constraints

- `CHECK (status IN ('new', 'wait', 'done', 'error', 'expired', 'cancelled'))` on all databases

---

## Security

### Entity Allowlist

Only classes listed in `config('delayed-process.allowed_entities')` can be executed. Attempting to create a process with an unlisted class throws `EntityNotAllowedException`.

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\ReportService::class,
    \App\Services\ExportService::class,
    // Only these classes can be used as handlers
],
```

### Callable Validation

Before execution, `CallableResolver` verifies:
1. Entity class is in the allowlist
2. Class exists (`class_exists()`)
3. Method exists (`method_exists()`)

Instantiation uses `app($entity)` — full Laravel DI container support.

### Log Privacy

Set `log_sensitive_context` to `false` (default) to strip context arrays from captured log entries. Only log level, timestamp, and message are stored.

### CSRF Protection

The frontend poller automatically reads `<meta name="csrf-token">` and includes it in poll request headers. Ensure your status endpoint is behind CSRF middleware or explicitly verify the token.

---

## Cookbook

For recipes, patterns, and troubleshooting, see the **[Cookbook](docs/cookbook.md)**.

Available in: [English](docs/cookbook.md) | [Русский](docs/cookbook.ru.md) | [Deutsch](docs/cookbook.de.md) | [中文](docs/cookbook.zh.md)

---

## Frontend Integration Guide

For a detailed step-by-step guide on integrating interceptors into **Vue.js 3** and **React** applications, see the **[Frontend Interceptors Guide](docs/frontend-interceptors-guide.md)**.

Includes: composables/hooks, progress tracking, batch polling, error handling, SSR support, and testing.

---

## License

[MIT](LICENSE.md) &copy; Denis Skripchenko
