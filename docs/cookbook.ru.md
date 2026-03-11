# Laravel Delayed Process — Книга рецептов

**Язык:** [English](cookbook.md) | [Русский](cookbook.ru.md) | [Deutsch](cookbook.de.md) | [中文](cookbook.zh.md)

Вернуться к [README](README.ru.md)

Практические рецепты, паттерны интеграции и устранение неполадок для `dskripchenko/laravel-delayed-process`.

---

## Оглавление

### Часть 1: Рецепты Backend
- [Рецепт 1: Простая асинхронная генерация отчётов](#рецепт-1-простая-асинхронная-генерация-отчётов)
- [Рецепт 2: Экспорт CSV/Excel файлов](#рецепт-2-экспорт-csvexcel-файлов)
- [Рецепт 3: Массовая отправка писем](#рецепт-3-массовая-отправка-писем)
- [Рецепт 4: Конвейер обработки изображений/видео](#рецепт-4-конвейер-обработки-изображенийвидео)
- [Рецепт 5: Интеграция со сторонним API](#рецепт-5-интеграция-со-сторонним-api)

### Часть 2: Интеграция Frontend
- [Рецепт 6: Полная настройка Axios (production-ready)](#рецепт-6-полная-настройка-axios-production-ready)
- [Рецепт 7: Использование Native Fetch API](#рецепт-7-использование-native-fetch-api)
- [Рецепт 8: Vue.js 3 Composable](#рецепт-8-vuejs-3-composable)
- [Рецепт 9: Индикатор прогресса с onPoll](#рецепт-9-индикатор-прогресса-с-onpoll)
- [Рецепт 10: Глобальная обработка ошибок](#рецепт-10-глобальная-обработка-ошибок)
- [Рецепт 11: Несколько конфигураций для разных endpoint'ов](#рецепт-11-несколько-конфигураций-для-разных-endpoint-ов)

### Часть 3: Продвинутые паттерны
- [Рецепт 12: Условная отложенная обработка](#рецепт-12-условная-отложенная-обработка)
- [Рецепт 13: Цепочка отложенных процессов](#рецепт-13-цепочка-отложенных-процессов)
- [Рецепт 14: Кастомные стратегии повторных попыток (Frontend)](#рецепт-14-кастомные-стратегии-повторных-попыток-frontend)
- [Рецепт 15: Тестирование с Pest PHP](#рецепт-15-тестирование-с-pest-php)

### Часть 4: Операции и мониторинг
- [Рецепт 16: Настройка Supervisor / Horizon](#рецепт-16-настройка-supervisor--horizon)
- [Рецепт 17: Плановая очистка](#рецепт-17-плановая-очистка)
- [Рецепт 18: Мониторинг зависших процессов](#рецепт-18-мониторинг-зависших-процессов)
- [Рецепт 19: Обслуживание базы данных](#рецепт-19-обслуживание-базы-данных)

### Часть 5: Устранение неполадок
- [Процесс зависает в статусе "wait" навечно](#проблема-процесс-зависает-в-статусе-wait-навечно)
- [Ошибка "Entity Not Allowed"](#проблема-ошибка-entity-not-allowed)
- [Ошибка CSRF Token Mismatch в SPA](#проблема-ошибка-csrf-token-mismatch-в-spa)
- [Время опроса истекло](#проблема-время-опроса-истекло)
- [Race условия при высокой конкурентности](#проблема-race-условия-при-высокой-конкурентности)
- [Утечки памяти при длительном polling](#проблема-утечки-памяти-при-длительном-polling)
- [Устаревшая миграция не удаётся](#проблема-устаревшая-миграция-не-удаётся)

---

## Часть 1: Рецепты Backend

### Рецепт 1: Простая асинхронная генерация отчётов

Отчёт о продажах, который генерируется более 30 секунд.

**Обработчик:**

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

**Конфиг:**

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\SalesReportService::class,
],
```

**Контроллер:**

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

### Рецепт 2: Экспорт CSV/Excel файлов

Экспорт больших наборов данных в файл и возврат URL для скачивания.

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

**Использование:**

```php
$process = $factory->make(
    UserExportService::class,
    'exportCsv',
    ['role' => 'admin', 'active' => true],
);
```

---

### Рецепт 3: Массовая отправка писем

Отправка персонализированных писем большой группе получателей.

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

### Рецепт 4: Конвейер обработки изображений/видео

Обработка загруженного медиа — изменение размера, конвертация, генерация миниатюр.

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

**Использование:**

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

### Рецепт 5: Интеграция со сторонним API

Вызов медленного внешнего API (платёжный шлюз, поставщик доставки, и т.д.).

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

## Часть 2: Интеграция Frontend

### Рецепт 6: Полная настройка Axios (production-ready)

Полная production-ready настройка Axios с обработкой ошибок.

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

**Использование в сервисе:**

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

### Рецепт 7: Использование Native Fetch API

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

### Рецепт 8: Vue.js 3 Composable

Переиспользуемый composable для операций отложенного процесса.

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

**Использование в компоненте:**

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

### Рецепт 9: Индикатор прогресса с onPoll

Показывать прогресс опроса пользователю.

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

### Рецепт 10: Глобальная обработка ошибок

Централизованная обработка ошибок для сбоев отложенных процессов.

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

**Использование с глобальным хранилищем уведомлений:**

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

### Рецепт 11: Несколько конфигураций для разных endpoint'ов

Использование разных конфигураций опроса для разных групп API.

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

## Часть 3: Продвинутые паттерны

### Рецепт 12: Условная отложенная обработка

Отложить обработку только в том случае, если операция ожидается тяжёлой.

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

### Рецепт 13: Цепочка отложенных процессов

Процесс A генерирует данные, процесс B их использует. Обработчик A запускает B.

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

> **Примечание:** Не забудьте добавить класс в `allowed_entities` один раз — он обрабатывает оба метода.

---

### Рецепт 14: Кастомные стратегии повторных попыток (Frontend)

Реализовать экспоненциальный backoff на стороне frontend polling.

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

### Рецепт 15: Тестирование с Pest PHP

Тестирование создания и выполнения отложенного процесса.

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

## Часть 4: Операции и мониторинг

### Рецепт 16: Настройка Supervisor / Horizon

#### Использование Supervisor (Queue Worker)

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

#### Использование Supervisor (Synchronous Command)

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

#### Использование Laravel Horizon

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

### Рецепт 17: Плановая очистка

Регистрация команды очистки в Laravel Scheduler.

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

### Рецепт 18: Мониторинг зависших процессов

Обнаружение зависших процессов и отправка оповещений.

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

**Расписание:**

```php
Schedule::command('monitor:delayed-processes --threshold=10')
    ->everyFiveMinutes();
```

---

### Рецепт 19: Обслуживание базы данных

#### PostgreSQL — Частичные индексы (уже созданы миграцией)

Миграция пакета уже создаёт оптимизированные частичные индексы для PostgreSQL. Дополнительная настройка не требуется.

#### PostgreSQL — Разделение таблицы для больших объёмов

Если обрабатываются миллионы отложенных процессов, рассмотрите диапазонное разделение по `created_at`:

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

#### Регулярное обслуживание

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

## Часть 5: Устранение неполадок

### Проблема: Процесс зависает в статусе "wait" навечно

**Симптомы:** Статус процесса остаётся `wait`, никогда не переходит в `done` или `error`.

**Причины:**
1. Worker очереди аварийно завершил работу во время выполнения
2. Превышен timeout задачи, но процесс не был очищен
3. Worker был перезагружен во время обработки

**Решения:**

```bash
# Check for stuck processes
php artisan delayed:unstuck --dry-run

# Reset stuck processes (default: stuck > 60 minutes)
php artisan delayed:unstuck

# Reset with custom timeout (30 minutes)
php artisan delayed:unstuck --timeout=30
```

**Профилактика:** Запланировать `delayed:unstuck` на выполнение каждые 15 минут (см. [Рецепт 18](#рецепт-18-мониторинг-зависших-процессов)).

---

### Проблема: Ошибка "Entity Not Allowed"

**Симптомы:** `EntityNotAllowedException: Entity [App\Services\MyService] is not in the allowed_entities list.`

**Причина:** Класс-обработчик не зарегистрирован в конфигурации.

**Решение:**

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\MyService::class, // Add your class here
],
```

> **Важно:** Используйте полное имя класса (FQCN). Проверка разрешённого списка выполняется с использованием строгого сравнения.

---

### Проблема: Ошибка CSRF Token Mismatch в SPA

**Симптомы:** Запросы опроса статуса не удаются с ошибкой 419 (CSRF token mismatch).

**Причины:**
1. SPA не отправляет CSRF token
2. В HTML отсутствует `<meta name="csrf-token">`

**Решения:**

**Вариант A:** Убедитесь, что meta-тег присутствует в HTML:

```html
<meta name="csrf-token" content="{{ csrf_token() }}">
```

Frontend poller автоматически читает этот тег и включает токен.

**Вариант B:** Передайте токен явно в конфиг:

```typescript
applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    headers: {
        'X-CSRF-TOKEN': 'your-token-here',
    },
});
```

**Вариант C:** Исключите endpoint статуса из проверки CSRF:

```php
// app/Http/Middleware/VerifyCsrfToken.php
protected $except = [
    'api/common/delayed-process/status*',
];
```

---

### Проблема: Время опроса истекло

**Симптомы:** `DelayedProcessError` выброшена без `error_message` с сервера. Процесс может всё ещё выполняться.

**Причины:**
1. Процесс выполняется дольше, чем сконфигурированный `timeout` (по умолчанию: 5 минут)
2. Превышен `maxAttempts` (по умолчанию: 100 попыток × 3s = 5 мин)

**Решения:**

```typescript
// Increase timeouts for heavy operations
applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 5000,     // Poll every 5 seconds
    maxAttempts: 360,          // Up to 360 attempts
    timeout: 1_800_000,        // 30 minutes total
});
```

Также рассмотрите увеличение timeout задачи на backend:

```php
// config/delayed-process.php
'job' => [
    'timeout' => 1800, // 30 minutes
],
```

---

### Проблема: Race условия при высокой конкурентности

**Симптомы:** Один и тот же процесс выполняется несколько раз одновременно.

**Объяснение:** Это не должно происходить. Пакет использует атомарное получение:

```sql
UPDATE delayed_processes SET status = 'wait', try = try + 1
WHERE id = ? AND status = 'new'
```

Только один worker может успешно получить процесс. Если наблюдается дублирование выполнения:

1. **Проверьте сброс `status='new'`:** Что-то сбрасывает процессы обратно в `new` во время их обработки?
2. **Проверьте расписание `delayed:unstuck`:** Если timeout слишком короткий, он может сбросить законно работающий процесс обратно в `new`.
3. **Увеличьте stuck timeout:** Установите `stuck_timeout_minutes` выше, чем ваша самая долгая ожидаемая операция.

---

### Проблема: Утечки памяти при длительном polling

**Симптомы:** Использование памяти вкладки браузера растёт со временем во время опроса.

**Причины:**
1. `patchXHR()` или `patchFetch()` не очищены при размонтировании компонента
2. Несколько перехватчиков зарегистрировано без удаления

**Решения:**

```typescript
// In Vue component — cleanup on unmount
import { onUnmounted } from 'vue';
import { patchFetch } from '@/delayed-process';

const unpatch = patchFetch({ statusUrl: '/api/common/delayed-process/status' });

onUnmounted(() => {
    unpatch();
});
```

Для Axios, извлеките перехватчик при необходимости:

```typescript
const interceptorId = applyAxiosInterceptor(api, config);

// When no longer needed:
api.interceptors.response.eject(interceptorId);
```

---

### Проблема: Устаревшая миграция не удаётся

**Симптомы:** Команда `delayed:migrate-v1` завершается с ошибками SQL.

**Основные причины и исправления:**

**1. Столбец уже существует:**

```
SQLSTATE[42701]: Duplicate column: column "error_message" already exists
```

Миграция была применена частично. Проверьте, какие столбцы существуют, и вручную примените остальные изменения, или удалите новые столбцы и пересчитайте.

**2. Доступ запрещён:**

```
SQLSTATE[42501]: Insufficient privilege
```

Пользователю базы данных нужны права ALTER TABLE. Запустите с привилегированным пользователем или дайте права.

**3. Безопасность production:**

```bash
# The command requires --force in production
php artisan delayed:migrate-v1 --force
```

**4. Блокировка большой таблицы:**

Для таблиц с миллионами строк ALTER TABLE может блокировать таблицу на длительный период. Рассмотрите:
- Выполнение во время окна обслуживания
- Использование `pg_repack` (PostgreSQL) или `pt-online-schema-change` (MySQL) для миграции без простоев
- Миграция небольшими шагами вручную

---

Вернуться к [README](README.ru.md)
