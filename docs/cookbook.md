# Laravel Delayed Process — Cookbook

**Language:** [English](cookbook.md) | [Русский](cookbook.ru.md) | [Deutsch](cookbook.de.md) | [中文](cookbook.zh.md)

Back to [README](../README.md)

Practical recipes, integration patterns, and troubleshooting for `dskripchenko/laravel-delayed-process`.

---

## Table of Contents

### Part 1: Backend Recipes
- [Recipe 1: Simple Async Report Generation](#recipe-1-simple-async-report-generation)
- [Recipe 2: CSV/Excel File Export](#recipe-2-csvexcel-file-export)
- [Recipe 3: Bulk Email Sending](#recipe-3-bulk-email-sending)
- [Recipe 4: Image/Video Processing Pipeline](#recipe-4-imagevideo-processing-pipeline)
- [Recipe 5: Third-Party API Integration](#recipe-5-third-party-api-integration)

### Part 2: Frontend Integration
- [Recipe 6: Full Axios Setup (Production-Ready)](#recipe-6-full-axios-setup-production-ready)
- [Recipe 7: Using with Native Fetch API](#recipe-7-using-with-native-fetch-api)
- [Recipe 8: Vue.js 3 Composable](#recipe-8-vuejs-3-composable)
- [Recipe 9: Progress Indicator with onPoll](#recipe-9-progress-indicator-with-onpoll)
- [Recipe 10: Global Error Handling](#recipe-10-global-error-handling)
- [Recipe 11: Multiple Configs for Different Endpoints](#recipe-11-multiple-configs-for-different-endpoints)

### Part 3: Advanced Patterns
- [Recipe 12: Conditional Delayed Processing](#recipe-12-conditional-delayed-processing)
- [Recipe 13: Chaining Delayed Processes](#recipe-13-chaining-delayed-processes)
- [Recipe 14: Custom Retry Strategies (Frontend)](#recipe-14-custom-retry-strategies-frontend)
- [Recipe 15: Testing with Pest PHP](#recipe-15-testing-with-pest-php)

### Part 4: Operations & Monitoring
- [Recipe 16: Supervisor / Horizon Setup](#recipe-16-supervisor--horizon-setup)
- [Recipe 17: Scheduled Cleanup](#recipe-17-scheduled-cleanup)
- [Recipe 18: Stuck Process Monitoring](#recipe-18-stuck-process-monitoring)
- [Recipe 19: Database Maintenance](#recipe-19-database-maintenance)

### Part 5: Troubleshooting
- [Process Stuck in "wait" Forever](#problem-process-stuck-in-wait-forever)
- ["Entity Not Allowed" Error](#problem-entity-not-allowed-error)
- [CSRF Token Mismatch in SPA](#problem-csrf-token-mismatch-in-spa)
- [Polling Timeout Exceeded](#problem-polling-timeout-exceeded)
- [Race Conditions in High-Concurrency](#problem-race-conditions-in-high-concurrency)
- [Memory Leaks with Long-Running Polling](#problem-memory-leaks-with-long-running-polling)
- [Legacy Migration Fails](#problem-legacy-migration-fails)

---

## Part 1: Backend Recipes

### Recipe 1: Simple Async Report Generation

A sales report that takes 30+ seconds to generate.

**Handler:**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Builders\OrderBuilder;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

final class SalesReportService
{
    public function generate(string $startDate, string $endDate): array
    {
        Log::info('Generating sales report', [
            'start' => $startDate,
            'end' => $endDate,
        ]);

        $orders = Order::query()
            ->whereBetweenDates($startDate, $endDate)
            ->withSum('items', 'total')
            ->get();

        $summary = [
            'total_orders' => $orders->count(),
            'total_revenue' => $orders->sum('items_sum_total'),
            'period' => "{$startDate} — {$endDate}",
        ];

        Log::info('Report generated', ['orders' => $summary['total_orders']]);

        return $summary;
    }
}
```

**Config:**

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\SalesReportService::class,
],
```

**Controller:**

```php
<?php

declare(strict_types=1);

namespace App\Api\V1\Controllers;

use App\Services\SalesReportService;
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReportController extends ApiController
{
    public function sales(
        Request $request,
        ProcessFactoryInterface $factory,
    ): JsonResponse {
        $process = $factory->make(
            SalesReportService::class,
            'generate',
            $request->string('start_date'),
            $request->string('end_date'),
        );

        return $this->success([
            'delayed' => [
                'uuid' => $process->uuid,
                'status' => $process->status->value,
            ],
        ]);
    }
}
```

---

### Recipe 2: CSV/Excel File Export

Export large datasets to a file and return a download URL.

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class UserExportService
{
    public function exportCsv(array $filters): array
    {
        $filename = 'exports/users-' . Str::random(16) . '.csv';
        $handle = fopen(Storage::path($filename), 'w');

        fputcsv($handle, ['ID', 'Name', 'Email', 'Created At']);

        User::query()
            ->applyFilters($filters)
            ->chunk(1000, function ($users) use ($handle): void {
                foreach ($users as $user) {
                    fputcsv($handle, [
                        $user->id,
                        $user->name,
                        $user->email,
                        $user->created_at->toDateTimeString(),
                    ]);
                }
            });

        fclose($handle);

        return [
            'url' => Storage::url($filename),
            'size' => Storage::size($filename),
        ];
    }
}
```

**Usage:**

```php
$process = $factory->make(
    UserExportService::class,
    'exportCsv',
    ['role' => 'admin', 'active' => true],
);
```

---

### Recipe 3: Bulk Email Sending

Send personalized emails to a large group of recipients.

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\PromotionMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class BulkEmailService
{
    public function sendPromotion(int $campaignId, array $userIds): array
    {
        $sent = 0;
        $failed = 0;

        $users = User::query()->whereIn('id', $userIds)->get();

        foreach ($users as $user) {
            try {
                Mail::to($user->email)->send(new PromotionMail($campaignId, $user));
                $sent++;
            } catch (\Throwable $e) {
                Log::warning("Failed to send to {$user->email}: {$e->getMessage()}");
                $failed++;
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'total' => $users->count(),
        ];
    }
}
```

---

### Recipe 4: Image/Video Processing Pipeline

Process uploaded media — resize, convert, generate thumbnails.

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class MediaProcessingService
{
    public function processImage(string $storagePath, array $sizes): array
    {
        $results = [];

        foreach ($sizes as $name => $dimensions) {
            Log::info("Generating {$name}: {$dimensions['w']}x{$dimensions['h']}");

            $outputPath = $this->resize(
                $storagePath,
                $dimensions['w'],
                $dimensions['h'],
                $name,
            );

            $results[$name] = Storage::url($outputPath);
        }

        return $results;
    }

    private function resize(
        string $input,
        int $width,
        int $height,
        string $suffix,
    ): string {
        // Image processing logic (e.g., Intervention Image)
        $info = pathinfo($input);
        $output = "{$info['dirname']}/{$info['filename']}_{$suffix}.{$info['extension']}";

        // ... actual resize logic ...

        return $output;
    }
}
```

**Usage:**

```php
$process = $factory->make(
    MediaProcessingService::class,
    'processImage',
    'uploads/photo.jpg',
    [
        'thumb' => ['w' => 150, 'h' => 150],
        'medium' => ['w' => 800, 'h' => 600],
        'large' => ['w' => 1920, 'h' => 1080],
    ],
);
```

---

### Recipe 5: Third-Party API Integration

Call a slow external API (payment gateway, shipping provider, etc.).

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ShippingRateService
{
    public function calculateRates(array $items, array $destination): array
    {
        Log::info('Requesting shipping rates', [
            'items_count' => count($items),
            'country' => $destination['country'],
        ]);

        $response = Http::timeout(60)->post('https://api.shipping-provider.com/rates', [
            'items' => $items,
            'destination' => $destination,
        ]);

        $response->throw();

        $rates = $response->json('rates');

        Log::info('Received rates', ['count' => count($rates)]);

        return [
            'rates' => $rates,
            'currency' => $response->json('currency'),
            'expires_at' => $response->json('expires_at'),
        ];
    }
}
```

---

## Part 2: Frontend Integration

### Recipe 6: Full Axios Setup (Production-Ready)

A complete production-ready Axios setup with error handling.

```typescript
// src/shared/api/http-client.ts
import axios, { type AxiosInstance } from 'axios';
import { applyAxiosInterceptor, DelayedProcessError } from '@/delayed-process';

function createHttpClient(): AxiosInstance {
    const instance = axios.create({
        baseURL: import.meta.env.VITE_API_URL || '/api',
        withCredentials: true,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        },
    });

    // Apply delayed process interceptor
    applyAxiosInterceptor(instance, {
        statusUrl: '/api/common/delayed-process/status',
        pollingInterval: 3000,
        maxAttempts: 100,
        timeout: 300_000,
        onPoll: (uuid, attempt) => {
            console.debug(`[DelayedProcess] Polling ${uuid} (attempt ${attempt})`);
        },
    });

    // Error interceptor
    instance.interceptors.response.use(
        (response) => response,
        (error: unknown) => {
            if (error instanceof DelayedProcessError) {
                console.error(
                    `[DelayedProcess] Failed: ${error.uuid} — ${error.errorMessage}`,
                );
            }
            return Promise.reject(error);
        },
    );

    return instance;
}

export const httpClient = createHttpClient();
```

**Usage in a service:**

```typescript
// src/entities/report/api/report-api.ts
import { httpClient } from '@/shared/api/http-client';

interface ReportResult {
    total_orders: number;
    total_revenue: number;
    period: string;
}

export async function generateSalesReport(
    startDate: string,
    endDate: string,
): Promise<ReportResult> {
    const response = await httpClient.post<{ success: boolean; payload: ReportResult }>(
        '/v1/reports/sales',
        { start_date: startDate, end_date: endDate },
    );
    return response.data.payload;
}
```

---

### Recipe 7: Using with Native Fetch API

```typescript
import { patchFetch } from '@/delayed-process';

// Install patch (typically in app initialization)
const unpatchFetch = patchFetch({
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 3000,
    maxAttempts: 100,
    headers: {
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>(
            'meta[name="csrf-token"]',
        )?.content ?? '',
    },
});

// Usage — automatic polling is transparent
async function fetchReport(): Promise<void> {
    const response = await fetch('/api/v1/reports/sales', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ start_date: '2026-01-01', end_date: '2026-03-01' }),
    });

    const data: { success: boolean; payload: Record<string, unknown> } = await response.json();
    console.log(data.payload); // Already resolved result
}

// Cleanup when module unloads
// unpatchFetch();
```

---

### Recipe 8: Vue.js 3 Composable

A reusable composable for delayed process operations.

```typescript
// src/shared/composables/useDelayedProcess.ts
import { ref, type Ref } from 'vue';
import { httpClient } from '@/shared/api/http-client';
import { DelayedProcessError } from '@/delayed-process';

interface UseDelayedProcessReturn<T> {
    data: Ref<T | null>;
    error: Ref<string | null>;
    isLoading: Ref<boolean>;
    execute: (...args: unknown[]) => Promise<T | null>;
    reset: () => void;
}

export function useDelayedProcess<T>(
    url: string,
    method: 'get' | 'post' = 'post',
): UseDelayedProcessReturn<T> {
    const data = ref<T | null>(null) as Ref<T | null>;
    const error = ref<string | null>(null);
    const isLoading = ref(false);

    async function execute(...args: unknown[]): Promise<T | null> {
        isLoading.value = true;
        error.value = null;
        data.value = null;

        try {
            const response = method === 'post'
                ? await httpClient.post<{ success: boolean; payload: T }>(url, args[0])
                : await httpClient.get<{ success: boolean; payload: T }>(url, { params: args[0] });

            data.value = response.data.payload;
            return response.data.payload;
        } catch (e: unknown) {
            if (e instanceof DelayedProcessError) {
                error.value = e.errorMessage ?? `Process ${e.uuid} failed`;
            } else if (e instanceof Error) {
                error.value = e.message;
            } else {
                error.value = 'Unknown error';
            }
            return null;
        } finally {
            isLoading.value = false;
        }
    }

    function reset(): void {
        data.value = null;
        error.value = null;
        isLoading.value = false;
    }

    return { data, error, isLoading, execute, reset };
}
```

**Usage in a component:**

```vue
<script setup lang="ts">
import { useDelayedProcess } from '@/shared/composables/useDelayedProcess';

interface ReportData {
    total_orders: number;
    total_revenue: number;
    period: string;
}

const {
    data: report,
    error,
    isLoading,
    execute: generateReport,
} = useDelayedProcess<ReportData>('/v1/reports/sales');

async function onGenerate(): Promise<void> {
    await generateReport({ start_date: '2026-01-01', end_date: '2026-03-01' });
}
</script>

<template>
    <div>
        <button :disabled="isLoading" @click="onGenerate">
            Generate Report
        </button>

        <div v-if="isLoading">Processing... Please wait.</div>
        <div v-else-if="error">Error: {{ error }}</div>
        <div v-else-if="report">
            <p>Orders: {{ report.total_orders }}</p>
            <p>Revenue: {{ report.total_revenue }}</p>
            <p>Period: {{ report.period }}</p>
        </div>
    </div>
</template>
```

---

### Recipe 9: Progress Indicator with onPoll

Show polling progress to the user.

```typescript
import { ref } from 'vue';
import axios from 'axios';
import { applyAxiosInterceptor } from '@/delayed-process';

const pollAttempt = ref(0);
const pollUuid = ref<string | null>(null);
const estimatedProgress = ref(0);

const api = axios.create({ baseURL: '/api' });

applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 2000,
    maxAttempts: 150,
    onPoll: (uuid: string, attempt: number) => {
        pollUuid.value = uuid;
        pollAttempt.value = attempt;
        // Asymptotic progress: approaches 95% but never reaches 100% until done
        estimatedProgress.value = Math.min(95, Math.round((1 - 1 / (1 + attempt * 0.3)) * 100));
    },
});
```

```vue
<template>
    <div v-if="pollUuid" class="progress-bar">
        <div class="progress-fill" :style="{ width: `${estimatedProgress}%` }" />
        <span>{{ estimatedProgress }}% (attempt {{ pollAttempt }})</span>
    </div>
</template>
```

---

### Recipe 10: Global Error Handling

Centralized error handling for delayed process failures.

```typescript
// src/shared/api/error-handler.ts
import { DelayedProcessError } from '@/delayed-process';

interface AppError {
    type: 'delayed-process' | 'network' | 'unknown';
    message: string;
    uuid?: string;
}

export function normalizeError(error: unknown): AppError {
    if (error instanceof DelayedProcessError) {
        return {
            type: 'delayed-process',
            message: error.errorMessage ?? 'Background process failed',
            uuid: error.uuid,
        };
    }

    if (error instanceof Error) {
        return {
            type: error.message.includes('Network') ? 'network' : 'unknown',
            message: error.message,
        };
    }

    return { type: 'unknown', message: String(error) };
}
```

**Usage with a global error notification store:**

```typescript
// src/shared/stores/notification-store.ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { normalizeError } from '@/shared/api/error-handler';

export const useNotificationStore = defineStore('notifications', () => {
    const errors = ref<Array<{ id: number; message: string }>>([]);
    let nextId = 0;

    function handleError(error: unknown): void {
        const normalized = normalizeError(error);
        const id = nextId++;

        errors.value.push({
            id,
            message: normalized.type === 'delayed-process'
                ? `Background task failed: ${normalized.message}`
                : normalized.message,
        });

        setTimeout(() => dismiss(id), 5000);
    }

    function dismiss(id: number): void {
        errors.value = errors.value.filter((e) => e.id !== id);
    }

    return { errors, handleError, dismiss };
});
```

---

### Recipe 11: Multiple Configs for Different Endpoints

Use different polling configurations for different API groups.

```typescript
import axios from 'axios';
import { applyAxiosInterceptor } from '@/delayed-process';

// Fast operations — short timeout, frequent polling
const quickApi = axios.create({ baseURL: '/api' });
applyAxiosInterceptor(quickApi, {
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 1000,
    maxAttempts: 30,
    timeout: 60_000, // 1 minute max
});

// Heavy operations — long timeout, slower polling
const heavyApi = axios.create({ baseURL: '/api' });
applyAxiosInterceptor(heavyApi, {
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 5000,
    maxAttempts: 360,
    timeout: 1_800_000, // 30 minutes max
});
```

---

## Part 3: Advanced Patterns

### Recipe 12: Conditional Delayed Processing

Defer only if the operation is expected to be heavy.

```php
<?php

declare(strict_types=1);

namespace App\Api\V1\Controllers;

use App\Services\ReportService;
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReportController extends ApiController
{
    private const int HEAVY_THRESHOLD = 10_000;

    public function generate(
        Request $request,
        ProcessFactoryInterface $factory,
        ReportService $service,
    ): JsonResponse {
        $rowEstimate = $service->estimateRows(
            $request->string('start_date'),
            $request->string('end_date'),
        );

        // Small datasets — execute synchronously
        if ($rowEstimate < self::HEAVY_THRESHOLD) {
            $result = $service->generate(
                $request->string('start_date'),
                $request->string('end_date'),
            );
            return $this->success($result);
        }

        // Large datasets — defer to queue
        $process = $factory->make(
            ReportService::class,
            'generate',
            $request->string('start_date'),
            $request->string('end_date'),
        );

        return $this->success([
            'delayed' => [
                'uuid' => $process->uuid,
                'status' => $process->status->value,
            ],
        ]);
    }
}
```

---

### Recipe 13: Chaining Delayed Processes

Process A generates data, process B uses it. The handler for A triggers B.

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;

final class DataPipelineService
{
    public function __construct(
        private readonly ProcessFactoryInterface $factory,
    ) {}

    public function extractAndTransform(string $source): array
    {
        // Step 1: Extract (heavy operation)
        $rawData = $this->extract($source);

        // Step 2: Trigger transform as a separate delayed process
        $transformProcess = $this->factory->make(
            self::class,
            'transform',
            $rawData,
        );

        return [
            'extracted_rows' => count($rawData),
            'transform_uuid' => $transformProcess->uuid,
        ];
    }

    public function transform(array $rawData): array
    {
        // Step 2: Transform (another heavy operation)
        $transformed = array_map(fn (array $row): array => [
            'id' => $row['id'],
            'value' => strtoupper($row['name']),
            'score' => $row['amount'] * 1.1,
        ], $rawData);

        return ['transformed_rows' => count($transformed)];
    }

    private function extract(string $source): array
    {
        // ... heavy extraction logic ...
        return [];
    }
}
```

> **Note:** Remember to add the class to `allowed_entities` once — it handles both methods.

---

### Recipe 14: Custom Retry Strategies (Frontend)

Implement exponential backoff on the frontend polling side.

```typescript
import { pollUntilDone, resolveConfig, type DelayedProcessConfig } from '@/delayed-process';

async function pollWithExponentialBackoff(
    uuid: string,
    baseConfig?: Partial<DelayedProcessConfig>,
): Promise<unknown> {
    const config = resolveConfig(baseConfig);
    let attempt = 0;
    const maxAttempts = config.maxAttempts;

    while (attempt < maxAttempts) {
        try {
            return await pollUntilDone(uuid, {
                ...config,
                maxAttempts: 1, // Single attempt per cycle
            });
        } catch (error: unknown) {
            attempt++;

            if (attempt >= maxAttempts) {
                throw error;
            }

            // Exponential backoff: 1s, 2s, 4s, 8s... capped at 30s
            const delay = Math.min(1000 * Math.pow(2, attempt - 1), 30_000);
            await new Promise<void>((resolve) => setTimeout(resolve, delay));
        }
    }

    throw new Error(`Polling timed out after ${maxAttempts} attempts`);
}
```

---

### Recipe 15: Testing with Pest PHP

Test delayed process creation and execution.

```php
<?php

declare(strict_types=1);

use App\Services\SalesReportService;
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;
use Dskripchenko\DelayedProcess\Contracts\ProcessRunnerInterface;
use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config([
        'delayed-process.allowed_entities' => [
            SalesReportService::class,
        ],
    ]);
});

it('creates a delayed process with correct attributes', function (): void {
    Queue::fake();

    $factory = app(ProcessFactoryInterface::class);
    $process = $factory->make(SalesReportService::class, 'generate', '2026-01-01', '2026-03-01');

    expect($process)
        ->toBeInstanceOf(DelayedProcess::class)
        ->uuid->not->toBeEmpty()
        ->status->toBe(ProcessStatus::New)
        ->entity->toBe(SalesReportService::class)
        ->method->toBe('generate')
        ->parameters->toBe(['2026-01-01', '2026-03-01']);
});

it('dispatches a job when process is created', function (): void {
    Queue::fake();

    $factory = app(ProcessFactoryInterface::class);
    $factory->make(SalesReportService::class, 'generate', '2026-01-01', '2026-03-01');

    Queue::assertPushed(\Dskripchenko\DelayedProcess\Jobs\DelayedProcessJob::class);
});

it('executes process and sets status to done', function (): void {
    Queue::fake();

    $factory = app(ProcessFactoryInterface::class);
    $process = $factory->make(SalesReportService::class, 'generate', '2026-01-01', '2026-03-01');

    $runner = app(ProcessRunnerInterface::class);
    $runner->run($process);

    $process->refresh();

    expect($process)
        ->status->toBe(ProcessStatus::Done)
        ->data->toBeArray()
        ->data->toHaveKeys(['total_orders', 'total_revenue', 'period']);
});

it('retries on failure and eventually marks as error', function (): void {
    Queue::fake();

    config(['delayed-process.default_attempts' => 2]);

    $factory = app(ProcessFactoryInterface::class);
    $process = $factory->make(SalesReportService::class, 'nonexistentMethod');

    // This will fail — method doesn't exist
    $runner = app(ProcessRunnerInterface::class);

    $runner->run($process);
    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::New); // Retry

    $runner->run($process);
    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Error); // Final failure
});

it('returns correct resource format', function (): void {
    Queue::fake();

    $factory = app(ProcessFactoryInterface::class);
    $process = $factory->make(SalesReportService::class, 'generate', '2026-01-01', '2026-03-01');

    $resource = \Dskripchenko\DelayedProcess\Resources\DelayedProcessResource::make($process);
    $array = $resource->toArray(request());

    expect($array)
        ->toHaveKeys(['uuid', 'status', 'attempts', 'current_try', 'created_at', 'updated_at'])
        ->and($array['status'])->toBe('new');
});
```

---

## Part 4: Operations & Monitoring

### Recipe 16: Supervisor / Horizon Setup

#### Using Supervisor (Queue Worker)

```ini
; /etc/supervisor/conf.d/delayed-process-worker.conf
[program:delayed-process-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/worker.log
stopwaitsecs=3600
```

#### Using Supervisor (Synchronous Command)

```ini
; /etc/supervisor/conf.d/delayed-process-command.conf
[program:delayed-process-sync]
command=php /var/www/artisan delayed:process --sleep=5 --max-iterations=0
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/delayed-process.log
```

#### Using Laravel Horizon

```php
// config/horizon.php
'environments' => [
    'production' => [
        'delayed-process-worker' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 5,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'tries' => 3,
            'timeout' => 300,
        ],
    ],
],
```

---

### Recipe 17: Scheduled Cleanup

Register the cleanup command in Laravel Scheduler.

```php
// app/Console/Kernel.php or routes/console.php (Laravel 11+)
use Illuminate\Support\Facades\Schedule;

// Delete terminal processes older than 30 days, daily at 3 AM
Schedule::command('delayed:clear --days=30 --chunk=1000')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/delayed-clear.log'));

// Reset stuck processes every 15 minutes
Schedule::command('delayed:unstuck --timeout=60')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/delayed-unstuck.log'));
```

---

### Recipe 18: Stuck Process Monitoring

Detect stuck processes and send alerts.

```php
// app/Console/Commands/MonitorDelayedProcesses.php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

final class MonitorDelayedProcesses extends Command
{
    protected $signature = 'monitor:delayed-processes {--threshold=10}';
    protected $description = 'Alert when too many processes are stuck';

    public function handle(): int
    {
        $threshold = (int) $this->option('threshold');

        $stuckCount = DelayedProcess::query()
            ->whereStuck()
            ->count();

        if ($stuckCount >= $threshold) {
            $this->error("ALERT: {$stuckCount} stuck processes detected!");

            // Send alert (Slack, email, PagerDuty, etc.)
            Notification::route('slack', config('services.slack.alert_webhook'))
                ->notify(new \App\Notifications\StuckProcessesAlert($stuckCount));
        } else {
            $this->info("OK: {$stuckCount} stuck processes (threshold: {$threshold})");
        }

        return self::SUCCESS;
    }
}
```

**Schedule:**

```php
Schedule::command('monitor:delayed-processes --threshold=10')
    ->everyFiveMinutes();
```

---

### Recipe 19: Database Maintenance

#### PostgreSQL — Partial Indexes (Already Created by Migration)

The package migration already creates optimized partial indexes for PostgreSQL. No additional setup needed.

#### PostgreSQL — Table Partitioning for High Volume

If you process millions of delayed processes, consider range partitioning by `created_at`:

```sql
-- Convert to partitioned table (requires PostgreSQL 10+)
-- WARNING: This requires downtime and data migration
CREATE TABLE delayed_processes_partitioned (
    LIKE delayed_processes INCLUDING ALL
) PARTITION BY RANGE (created_at);

-- Create monthly partitions
CREATE TABLE delayed_processes_y2026m01 PARTITION OF delayed_processes_partitioned
    FOR VALUES FROM ('2026-01-01') TO ('2026-02-01');
CREATE TABLE delayed_processes_y2026m02 PARTITION OF delayed_processes_partitioned
    FOR VALUES FROM ('2026-02-01') TO ('2026-03-01');
-- ... add future partitions via cron/scheduler
```

#### Regular Maintenance

```sql
-- Analyze table statistics for query planner
ANALYZE delayed_processes;

-- Monitor table and index sizes
SELECT
    pg_size_pretty(pg_total_relation_size('delayed_processes')) AS total_size,
    pg_size_pretty(pg_table_size('delayed_processes')) AS table_size,
    pg_size_pretty(pg_indexes_size('delayed_processes')) AS index_size;
```

---

## Part 5: Troubleshooting

### Problem: Process Stuck in "wait" Forever

**Symptoms:** Process status remains `wait`, never transitions to `done` or `error`.

**Causes:**
1. Queue worker crashed during execution
2. Job timeout exceeded but process wasn't cleaned up
3. Worker was restarted while processing

**Solutions:**

```bash
# Check for stuck processes
php artisan delayed:unstuck --dry-run

# Reset stuck processes (default: stuck > 60 minutes)
php artisan delayed:unstuck

# Reset with custom timeout (30 minutes)
php artisan delayed:unstuck --timeout=30
```

**Prevention:** Schedule `delayed:unstuck` to run every 15 minutes (see [Recipe 18](#recipe-18-stuck-process-monitoring)).

---

### Problem: "Entity Not Allowed" Error

**Symptoms:** `EntityNotAllowedException: Entity [App\Services\MyService] is not in the allowed_entities list.`

**Cause:** The handler class is not registered in the configuration.

**Solution:**

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\MyService::class, // Add your class here
],
```

> **Important:** Use the fully qualified class name (FQCN). The allowlist is checked using strict equality.

---

### Problem: CSRF Token Mismatch in SPA

**Symptoms:** Status polling requests fail with 419 (CSRF token mismatch).

**Causes:**
1. SPA is not sending the CSRF token
2. `<meta name="csrf-token">` is missing from the HTML

**Solutions:**

**Option A:** Ensure the meta tag exists in your HTML:

```html
<meta name="csrf-token" content="{{ csrf_token() }}">
```

The frontend poller automatically reads this tag and includes the token.

**Option B:** Pass the token explicitly in config:

```typescript
applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    headers: {
        'X-CSRF-TOKEN': 'your-token-here',
    },
});
```

**Option C:** Exclude the status endpoint from CSRF verification:

```php
// app/Http/Middleware/VerifyCsrfToken.php
protected $except = [
    'api/common/delayed-process/status*',
];
```

---

### Problem: Polling Timeout Exceeded

**Symptoms:** `DelayedProcessError` thrown with no `error_message` from the server. The process may still be running.

**Causes:**
1. Process takes longer than the configured `timeout` (default: 5 minutes)
2. `maxAttempts` exceeded (default: 100 attempts × 3s = 5 min)

**Solutions:**

```typescript
// Increase timeouts for heavy operations
applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 5000,     // Poll every 5 seconds
    maxAttempts: 360,          // Up to 360 attempts
    timeout: 1_800_000,        // 30 minutes total
});
```

Also consider increasing the backend job timeout:

```php
// config/delayed-process.php
'job' => [
    'timeout' => 1800, // 30 minutes
],
```

---

### Problem: Race Conditions in High-Concurrency

**Symptoms:** Same process executed multiple times simultaneously.

**Explanation:** This should not happen. The package uses atomic claiming:

```sql
UPDATE delayed_processes SET status = 'wait', try = try + 1
WHERE id = ? AND status = 'new'
```

Only one worker can successfully claim a process. If you observe duplicate execution:

1. **Check for `status='new'` resets:** Is something resetting processes back to `new` while they're being processed?
2. **Check `delayed:unstuck` schedule:** If the unstuck timeout is too short, it may reset a legitimately running process back to `new`.
3. **Increase stuck timeout:** Set `stuck_timeout_minutes` higher than your longest expected operation.

---

### Problem: Memory Leaks with Long-Running Polling

**Symptoms:** Browser tab memory usage grows over time during polling.

**Causes:**
1. `patchXHR()` or `patchFetch()` not cleaned up when component unmounts
2. Multiple interceptors registered without removal

**Solutions:**

```typescript
// In Vue component — cleanup on unmount
import { onUnmounted } from 'vue';
import { patchFetch } from '@/delayed-process';

const unpatch = patchFetch({ statusUrl: '/api/common/delayed-process/status' });

onUnmounted(() => {
    unpatch();
});
```

For Axios, eject the interceptor when done:

```typescript
const interceptorId = applyAxiosInterceptor(api, config);

// When no longer needed:
api.interceptors.response.eject(interceptorId);
```

---

### Problem: Legacy Migration Fails

**Symptoms:** `delayed:migrate-v1` command fails with SQL errors.

**Common causes and fixes:**

**1. Column already exists:**

```
SQLSTATE[42701]: Duplicate column: column "error_message" already exists
```

The migration was partially applied. Check which columns exist and manually apply remaining changes, or drop the new columns and re-run.

**2. Permission denied:**

```
SQLSTATE[42501]: Insufficient privilege
```

The database user needs ALTER TABLE permissions. Run with a privileged user or grant permissions.

**3. Production safety:**

```bash
# The command requires --force in production
php artisan delayed:migrate-v1 --force
```

**4. Large table lock:**

For tables with millions of rows, the ALTER TABLE may lock the table for an extended period. Consider:
- Running during maintenance window
- Using `pg_repack` (PostgreSQL) or `pt-online-schema-change` (MySQL) for zero-downtime migration
- Migrating in smaller steps manually

---

Back to [README](../README.md)
