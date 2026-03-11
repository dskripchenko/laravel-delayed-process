# Laravel Verzögerter Prozess — Kochbuch

**Sprache:** [English](cookbook.md) | [Русский](cookbook.ru.md) | [Deutsch](cookbook.de.md) | [中文](cookbook.zh.md)

Zurück zur [README](README.de.md)

Praktische Rezepte, Integrationsmuster und Fehlerbehebung für `dskripchenko/laravel-delayed-process`.

---

## Inhaltsverzeichnis

### Teil 1: Backend-Rezepte
- [Rezept 1: Einfache asynchrone Berichtgenerierung](#rezept-1-einfache-asynchrone-berichtgenerierung)
- [Rezept 2: CSV/Excel-Dateiexport](#rezept-2-csvexcel-dateiexport)
- [Rezept 3: Massen-E-Mail-Versand](#rezept-3-massen-e-mail-versand)
- [Rezept 4: Bild-/Video-Verarbeitungs-Pipeline](#rezept-4-bild-video-verarbeitungs-pipeline)
- [Rezept 5: Integration von Drittanbieter-APIs](#rezept-5-integration-von-drittanbieter-apis)

### Teil 2: Frontend-Integration
- [Rezept 6: Vollständige Axios-Einrichtung (Produktionsbereit)](#rezept-6-vollständige-axios-einrichtung-produktionsbereit)
- [Rezept 7: Verwendung der nativen Fetch-API](#rezept-7-verwendung-der-nativen-fetch-api)
- [Rezept 8: Vue.js 3 Composable](#rezept-8-vuejs-3-composable)
- [Rezept 9: Fortschrittsanzeige mit onPoll](#rezept-9-fortschrittsanzeige-mit-onpoll)
- [Rezept 10: Globale Fehlerbehandlung](#rezept-10-globale-fehlerbehandlung)
- [Rezept 11: Mehrere Konfigurationen für verschiedene Endpoints](#rezept-11-mehrere-konfigurationen-für-verschiedene-endpoints)

### Teil 3: Fortgeschrittene Muster
- [Rezept 12: Bedingte verzögerte Verarbeitung](#rezept-12-bedingte-verzögerte-verarbeitung)
- [Rezept 13: Verkettung verzögerter Prozesse](#rezept-13-verkettung-verzögerter-prozesse)
- [Rezept 14: Benutzerdefinierte Wiederholungsstrategien (Frontend)](#rezept-14-benutzerdefinierte-wiederholungsstrategien-frontend)
- [Rezept 15: Testen mit Pest PHP](#rezept-15-testen-mit-pest-php)

### Teil 4: Betrieb & Monitoring
- [Rezept 16: Supervisor / Horizon-Einrichtung](#rezept-16-supervisor--horizon-einrichtung)
- [Rezept 17: Geplante Bereinigung](#rezept-17-geplante-bereinigung)
- [Rezept 18: Monitoring stuck Prozesse](#rezept-18-monitoring-stuck-prozesse)
- [Rezept 19: Datenbankwartung](#rezept-19-datenbankwartung)

### Teil 5: Fehlerbehebung
- [Prozess bleibt für immer in "wait" stecken](#problem-prozess-bleibt-für-immer-in-wait-stecken)
- [Fehler "Entity Not Allowed"](#problem-fehler-entity-not-allowed)
- [CSRF-Token-Nichtübereinstimmung in SPA](#problem-csrf-token-nichtübereinstimmung-in-spa)
- [Polling-Timeout überschritten](#problem-polling-timeout-überschritten)
- [Race Conditions bei hoher Parallelität](#problem-race-conditions-bei-hoher-parallelität)
- [Speicherlecks bei langer Polling](#problem-speicherlecks-bei-langer-polling)
- [Legacy-Migration schlägt fehl](#problem-legacy-migration-schlägt-fehl)

---

## Teil 1: Backend-Rezepte

### Rezept 1: Einfache asynchrone Berichtgenerierung

Ein Verkaufsbericht, der mehr als 30 Sekunden zum Generieren benötigt.

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

**Konfiguration:**

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

### Rezept 2: CSV/Excel-Dateiexport

Große Datenmengen in eine Datei exportieren und eine Download-URL zurückgeben.

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

**Verwendung:**

```php
$process = $factory->make(
    UserExportService::class,
    'exportCsv',
    ['role' => 'admin', 'active' => true],
);
```

---

### Rezept 3: Massen-E-Mail-Versand

Personalisierte E-Mails an eine große Gruppe von Empfängern versenden.

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

### Rezept 4: Bild-/Video-Verarbeitungs-Pipeline

Hochgeladene Medien verarbeiten — Größe ändern, konvertieren, Thumbnails generieren.

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

**Verwendung:**

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

### Rezept 5: Integration von Drittanbieter-APIs

Rufen Sie eine langsame externe API auf (Zahlungsgateway, Versandanbieter usw.).

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

## Teil 2: Frontend-Integration

### Rezept 6: Vollständige Axios-Einrichtung (Produktionsbereit)

Eine vollständige, produktionsreife Axios-Einrichtung mit Fehlerbehandlung.

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

**Verwendung in einem Service:**

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

### Rezept 7: Verwendung der nativen Fetch-API

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

### Rezept 8: Vue.js 3 Composable

Ein wiederverwendbares Composable für Verzögerte-Prozess-Operationen.

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

**Verwendung in einer Komponente:**

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

### Rezept 9: Fortschrittsanzeige mit onPoll

Zeigen Sie dem Benutzer den Polling-Fortschritt an.

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

### Rezept 10: Globale Fehlerbehandlung

Zentralisierte Fehlerbehandlung für Fehler bei verzögerten Prozessen.

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

**Verwendung mit einem globalen Error-Benachrichtigungsspeicher:**

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

### Rezept 11: Mehrere Konfigurationen für verschiedene Endpoints

Verwenden Sie unterschiedliche Polling-Konfigurationen für verschiedene API-Gruppen.

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

## Teil 3: Fortgeschrittene Muster

### Rezept 12: Bedingte verzögerte Verarbeitung

Verschieben Sie nur, wenn die Operation schwer sein soll.

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

### Rezept 13: Verkettung verzögerter Prozesse

Prozess A generiert Daten, Prozess B nutzt sie. Der Handler für A triggert B.

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

> **Hinweis:** Denken Sie daran, die Klasse einmal zu `allowed_entities` hinzuzufügen — sie handhabt beide Methoden.

---

### Rezept 14: Benutzerdefinierte Wiederholungsstrategien (Frontend)

Implementieren Sie exponentielles Backoff auf der Frontend-Polling-Seite.

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

### Rezept 15: Testen mit Pest PHP

Testen Sie die Erstellung und Ausführung verzögerter Prozesse.

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

## Teil 4: Betrieb & Monitoring

### Rezept 16: Supervisor / Horizon-Einrichtung

#### Supervisor verwenden (Queue Worker)

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

#### Supervisor verwenden (Synchroner Befehl)

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

#### Laravel Horizon verwenden

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

### Rezept 17: Geplante Bereinigung

Registrieren Sie den Bereinigungsbefehl im Laravel-Scheduler.

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

### Rezept 18: Monitoring stuck Prozesse

Erkennen Sie stuck Prozesse und senden Sie Warnungen.

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

**Planung:**

```php
Schedule::command('monitor:delayed-processes --threshold=10')
    ->everyFiveMinutes();
```

---

### Rezept 19: Datenbankwartung

#### PostgreSQL — Teilindizes (bereits durch Migration erstellt)

Die Paketmigration erstellt bereits optimierte Teilindizes für PostgreSQL. Keine zusätzliche Einrichtung erforderlich.

#### PostgreSQL — Tabellenpartitionierung für großes Volumen

Wenn Sie Millionen verzögerter Prozesse verarbeiten, sollten Sie eine Range-Partitionierung nach `created_at` in Betracht ziehen:

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

#### Regelmäßige Wartung

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

## Teil 5: Fehlerbehebung

### Problem: Prozess bleibt für immer in "wait" stecken

**Symptome:** Der Prozessstatus bleibt `wait`, wechselt niemals zu `done` oder `error`.

**Ursachen:**
1. Queue Worker ist während der Ausführung abgestürzt
2. Jobzeitüberschreitung wurde überschritten, aber der Prozess wurde nicht bereinigt
3. Worker wurde neu gestartet, während die Verarbeitung lief

**Lösungen:**

```bash
# Check for stuck processes
php artisan delayed:unstuck --dry-run

# Reset stuck processes (default: stuck > 60 minutes)
php artisan delayed:unstuck

# Reset with custom timeout (30 minutes)
php artisan delayed:unstuck --timeout=30
```

**Vorbeugung:** Planen Sie die Ausführung von `delayed:unstuck` alle 15 Minuten (siehe [Rezept 18](#rezept-18-monitoring-stuck-prozesse)).

---

### Problem: Fehler "Entity Not Allowed"

**Symptome:** `EntityNotAllowedException: Entity [App\Services\MyService] is not in the allowed_entities list.`

**Ursache:** Die Handler-Klasse ist nicht in der Konfiguration registriert.

**Lösung:**

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\MyService::class, // Add your class here
],
```

> **Wichtig:** Verwenden Sie den vollständig qualifizierten Klassennamen (FQCN). Die Zulassungsliste wird mit strikter Gleichheit überprüft.

---

### Problem: CSRF-Token-Nichtübereinstimmung in SPA

**Symptome:** Status-Polling-Anfragen schlagen mit 419 (CSRF-Token-Nichtübereinstimmung) fehl.

**Ursachen:**
1. SPA sendet das CSRF-Token nicht
2. `<meta name="csrf-token">` fehlt in der HTML

**Lösungen:**

**Option A:** Stellen Sie sicher, dass das Meta-Tag in Ihrer HTML vorhanden ist:

```html
<meta name="csrf-token" content="{{ csrf_token() }}">
```

Der Frontend-Poller liest dieses Tag automatisch und fügt das Token ein.

**Option B:** Übergeben Sie das Token explizit in der Konfiguration:

```typescript
applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    headers: {
        'X-CSRF-TOKEN': 'your-token-here',
    },
});
```

**Option C:** Schließen Sie den Status-Endpoint von der CSRF-Überprüfung aus:

```php
// app/Http/Middleware/VerifyCsrfToken.php
protected $except = [
    'api/common/delayed-process/status*',
];
```

---

### Problem: Polling-Timeout überschritten

**Symptome:** `DelayedProcessError` geworfen ohne `error_message` vom Server. Der Prozess wird möglicherweise noch ausgeführt.

**Ursachen:**
1. Der Prozess dauert länger als das konfigurierte `timeout` (Standard: 5 Minuten)
2. `maxAttempts` überschritten (Standard: 100 Versuche × 3s = 5 Min)

**Lösungen:**

```typescript
// Increase timeouts for heavy operations
applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 5000,     // Poll every 5 seconds
    maxAttempts: 360,          // Up to 360 attempts
    timeout: 1_800_000,        // 30 minutes total
});
```

Erhöhen Sie auch das Backend-Job-Timeout:

```php
// config/delayed-process.php
'job' => [
    'timeout' => 1800, // 30 minutes
],
```

---

### Problem: Race Conditions bei hoher Parallelität

**Symptome:** Derselbe Prozess wird mehrmals gleichzeitig ausgeführt.

**Erklärung:** Dies sollte nicht vorkommen. Das Paket verwendet atomares Claiming:

```sql
UPDATE delayed_processes SET status = 'wait', try = try + 1
WHERE id = ? AND status = 'new'
```

Nur ein Worker kann erfolgreich einen Prozess beanspruchen. Wenn Sie doppelte Ausführung beobachten:

1. **Überprüfen Sie auf `status='new'` Zurückstellungen:** Setzt etwas Prozesse auf `new` zurück, während sie verarbeitet werden?
2. **Überprüfen Sie die `delayed:unstuck`-Planung:** Wenn das Stuck-Timeout zu kurz ist, wird möglicherweise ein legitim laufender Prozess auf `new` zurückgesetzt.
3. **Erhöhen Sie das Stuck-Timeout:** Setzen Sie `stuck_timeout_minutes` höher als Ihre längste erwartete Operation.

---

### Problem: Speicherlecks bei langer Polling

**Symptome:** Die Speichernutzung des Browser-Tabs wächst im Laufe der Zeit während des Polling.

**Ursachen:**
1. `patchXHR()` oder `patchFetch()` nicht bereinigt, wenn die Komponente unmountet
2. Mehrere Interceptors registriert ohne Entfernung

**Lösungen:**

```typescript
// In Vue component — cleanup on unmount
import { onUnmounted } from 'vue';
import { patchFetch } from '@/delayed-process';

const unpatch = patchFetch({ statusUrl: '/api/common/delayed-process/status' });

onUnmounted(() => {
    unpatch();
});
```

Für Axios werfen Sie den Interceptor aus, wenn Sie fertig sind:

```typescript
const interceptorId = applyAxiosInterceptor(api, config);

// When no longer needed:
api.interceptors.response.eject(interceptorId);
```

---

### Problem: Legacy-Migration schlägt fehl

**Symptome:** Der Befehl `delayed:migrate-v1` schlägt mit SQL-Fehlern fehl.

**Häufige Ursachen und Fixes:**

**1. Spalte existiert bereits:**

```
SQLSTATE[42701]: Duplicate column: column "error_message" already exists
```

Die Migration wurde teilweise angewendet. Überprüfen Sie, welche Spalten vorhanden sind, und wenden Sie die verbleibenden Änderungen manuell an, oder löschen Sie die neuen Spalten und führen Sie den Vorgang erneut aus.

**2. Berechtigung verweigert:**

```
SQLSTATE[42501]: Insufficient privilege
```

Der Datenbankbenutzer benötigt ALTER TABLE-Berechtigungen. Führen Sie mit einem privilegierten Benutzer aus oder erteilen Sie Berechtigungen.

**3. Sicherheit in der Produktion:**

```bash
# The command requires --force in production
php artisan delayed:migrate-v1 --force
```

**4. Großes Tabellenlock:**

Bei Tabellen mit Millionen von Zeilen kann die ALTER TABLE die Tabelle für einen längeren Zeitraum sperren. Erwägen Sie:
- Ausführung während des Wartungsfensters
- Verwendung von `pg_repack` (PostgreSQL) oder `pt-online-schema-change` (MySQL) für die Migration ohne Ausfallzeit
- Manuelle Migration in kleineren Schritten

---

Zurück zur [README](README.de.md)
