# The "Endless Spinner" Problem in Laravel Can Be Solved More Simply

Here's a scenario every Laravel developer knows: the user clicks "Generate Report", sees a spinner, waits 30 seconds... a minute... timeout. The page crashes, data is lost, the user is frustrated.

Most teams solve this with queues. But between "dispatch a job" and "show the result to the user" there's a massive gap that every team fills differently: WebSocket servers, Pusher, custom status tables, hand-rolled polling. Every time — from scratch.

I ran into this problem across several projects and eventually packaged my solution as open-source. Perhaps it'll save someone else some time too.

---

## Laravel Delayed Process

**GitHub:** [dskripchenko/laravel-delayed-process](https://github.com/dskripchenko/laravel-delayed-process)
**Packagist:** [dskripchenko/laravel-delayed-process](https://packagist.org/packages/dskripchenko/laravel-delayed-process)

### The Idea

Instead of making the client wait for a heavy operation to finish, the server instantly returns a UUID. The frontend automatically polls for status and substitutes the result when it's ready. For the developer — one line on the backend and one on the frontend.

### What It Looks Like

**Backend — controller:**

```php
public function generate(Request $request, ProcessFactoryInterface $factory): JsonResponse
{
    $process = $factory->make(
        ReportService::class,
        'generate',
        $request->integer('user_id'),
        $request->string('format'),
    );

    return response()->json([
        'success' => true,
        'payload' => ['delayed' => ['uuid' => $process->uuid]],
    ]);
}
```

**Frontend — Axios setup (once):**

```typescript
import axios from 'axios';
import { applyAxiosInterceptor } from './delayed-process';

const api = axios.create({ baseURL: '/api' });
applyAxiosInterceptor(api, { statusUrl: '/api/common/delayed-process/status' });
```

That's it. Every POST request returning `delayed.uuid` now automatically polls until completion. Components don't change at all — `await api.post(...)` simply returns the final data as if the operation ran synchronously.

---

### What's Under the Hood

This package isn't just a queue wrapper. It's a full-featured long-running process management system:

**Lifecycle with 6 statuses.** `new → wait → done | error | expired | cancelled`. Every transition is an atomic UPDATE, protected against race conditions. A process cannot be claimed by two workers simultaneously.

**Automatic retry.** If the handler throws — the process goes back to the queue. Attempt count is configurable. After exhaustion — `error` status with message and stack trace preserved.

**Progress 0–100%.** Handlers can report progress via `ProcessProgressInterface`. The frontend sees the current percentage on every poll request — progress bars out of the box.

**Webhook notifications.** On terminal status, an HTTP POST is sent to `callback_url`. Handy for integrations, Telegram bots, email notifications.

**TTL and auto-expiration.** Each process can have an `expires_at`. The `delayed:expire` artisan command marks overdue ones. No more "stuck" tasks.

**Security allowlist.** Only classes listed in config can be invoked as handlers. This isn't a framework for running arbitrary code — it's a secure pipeline for known operations.

---

### Frontend — Not Just Axios

The package includes interceptors for three transports:

| Transport | Setup |
|-----------|-------|
| **Axios** | `applyAxiosInterceptor(instance, config)` |
| **Fetch** | `patchFetch(config)` — monkey-patches `window.fetch` |
| **XHR** | `patchXHR(config)` — monkey-patches `XMLHttpRequest` |

Plus `BatchPoller` for bulk operations — polls multiple UUIDs in a single request.

Loop prevention: the `X-Delayed-Process-Poll` header prevents interceptors from re-intercepting poll requests.

---

### Per-entity Configuration

Different operations have different requirements. A lightweight service goes to the default queue, while a heavy export gets its own with an extended timeout:

```php
'allowed_entities' => [
    LightService::class,                         // default queue
    HeavyService::class => [                     // custom
        'queue' => 'heavy',
        'connection' => 'redis',
        'timeout' => 600,
    ],
],
```

---

### Production-ready Artisan Commands

| Command | Purpose |
|---------|---------|
| `delayed:process` | Synchronous worker (for dev or single-server setups) |
| `delayed:clear` | Clean up terminal processes older than N days |
| `delayed:unstuck` | Reset "stuck" processes in `wait` status |
| `delayed:expire` | Expire processes with elapsed TTL |

All commands support `--dry-run` for safe preview.

---

### Events for Observability

Four lifecycle events: `ProcessCreated`, `ProcessStarted`, `ProcessCompleted`, `ProcessFailed`. Attach a Listener — and you've got monitoring, metrics, and alerts.

---

### Where This Might Be Useful

- **Report generation and exports** — PDFs, Excel, CSV with tens of thousands of rows
- **File processing** — image conversion, data imports
- **External APIs** — requests to slow services, ERP synchronization
- **Bulk operations** — mailings, price recalculations, catalog updates
- **Any operation over 5 seconds** that a user triggers through the UI

### What the Package Handles for You

- No WebSocket server to set up
- No status table to design
- No frontend polling to implement
- Race conditions are handled internally
- Retry logic is built in

---

### Specs

- **Compatibility:** Laravel 12+, PHP 8.5+
- **Database:** PostgreSQL (recommended, with partial indexes), MySQL/MariaDB
- **Tests:** 103 tests, 244 assertions, 88.4% coverage
- **Documentation:** 4 languages (EN/RU/DE/ZH), Cookbook, Frontend Integration Guide
- **License:** MIT

---

If you work with Laravel and occasionally face the "background process with status tracking" challenge — this package might save you some time. Feedback and contributions are always welcome.

#Laravel #PHP #OpenSource #WebDevelopment #Backend #AsyncProcessing #DeveloperTools
