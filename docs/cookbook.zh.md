# Laravel 延迟进程 — 实用手册

**语言:** [English](cookbook.md) | [Русский](cookbook.ru.md) | [Deutsch](cookbook.de.md) | [中文](cookbook.zh.md)

返回 [README](README.zh.md)

`dskripchenko/laravel-delayed-process` 的实用方案、集成模式和故障排除。

---

## 目录

### 第1部分：后端方案
- [方案 1：简单的异步报告生成](#方案-1简单的异步报告生成)
- [方案 2：CSV/Excel 文件导出](#方案-2csvexcel-文件导出)
- [方案 3：批量电子邮件发送](#方案-3批量电子邮件发送)
- [方案 4：图像/视频处理管道](#方案-4图像视频处理管道)
- [方案 5：第三方 API 集成](#方案-5第三方-api-集成)

### 第2部分：前端集成
- [方案 6：完整 Axios 设置（生产就绪）](#方案-6完整-axios-设置生产就绪)
- [方案 7：使用原生 Fetch API](#方案-7使用原生-fetch-api)
- [方案 8：Vue.js 3 Composable](#方案-8vuejs-3-composable)
- [方案 9：带 onPoll 的进度指示器](#方案-9带-onpoll-的进度指示器)
- [方案 10：全局错误处理](#方案-10全局错误处理)
- [方案 11：不同端点的多配置](#方案-11不同端点的多配置)

### 第3部分：高级模式
- [方案 12：条件延迟处理](#方案-12条件延迟处理)
- [方案 13：链接延迟进程](#方案-13链接延迟进程)
- [方案 14：自定义重试策略（前端）](#方案-14自定义重试策略前端)
- [方案 15：使用 Pest PHP 测试](#方案-15使用-pest-php-测试)

### 第4部分：运维与监控
- [方案 16：Supervisor / Horizon 设置](#方案-16supervisor--horizon-设置)
- [方案 17：计划清理](#方案-17计划清理)
- [方案 18：卡住进程监控](#方案-18卡住进程监控)
- [方案 19：数据库维护](#方案-19数据库维护)

### 第5部分：故障排除
- [进程永久卡在"wait"状态](#故障进程永久卡在wait状态)
- ["实体不允许"错误](#故障实体不允许错误)
- [SPA 中的 CSRF 令牌不匹配](#故障spa-中的-csrf-令牌不匹配)
- [轮询超时已超出](#故障轮询超时已超出)
- [高并发中的竞态条件](#故障高并发中的竞态条件)
- [长时间轮询的内存泄漏](#故障长时间轮询的内存泄漏)
- [旧版迁移失败](#故障旧版迁移失败)

---

## 第1部分：后端方案

### 方案 1：简单的异步报告生成

需要 30 多秒才能生成的销售报告。

**处理程序：**

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

**配置：**

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\SalesReportService::class,
],
```

**控制器：**

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

### 方案 2：CSV/Excel 文件导出

将大型数据集导出到文件并返回下载 URL。

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

**使用方式：**

```php
$process = $factory->make(
    UserExportService::class,
    'exportCsv',
    ['role' => 'admin', 'active' => true],
);
```

---

### 方案 3：批量电子邮件发送

向大量收件人发送个性化电子邮件。

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

### 方案 4：图像/视频处理管道

处理上传的媒体 — 调整大小、转换、生成缩略图。

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

**使用方式：**

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

### 方案 5：第三方 API 集成

调用缓慢的外部 API（支付网关、物流提供商等）。

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

## 第2部分：前端集成

### 方案 6：完整 Axios 设置（生产就绪）

一个完整的生产就绪 Axios 设置，具有错误处理功能。

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

**在服务中的使用：**

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

### 方案 7：使用原生 Fetch API

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

### 方案 8：Vue.js 3 Composable

一个可重用的 composable 用于延迟进程操作。

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

**在组件中的使用：**

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

### 方案 9：带 onPoll 的进度指示器

向用户显示轮询进度。

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

### 方案 10：全局错误处理

延迟进程失败的集中式错误处理。

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

**与全局错误通知存储的用法：**

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

### 方案 11：不同端点的多配置

对不同的 API 组使用不同的轮询配置。

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

## 第3部分：高级模式

### 方案 12：条件延迟处理

仅当操作预期较重时才延迟。

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

### 方案 13：链接延迟进程

进程 A 生成数据，进程 B 使用它。A 的处理程序触发 B。

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

> **注意：** 记得将类添加到 `allowed_entities` 一次 — 它处理两个方法。

---

### 方案 14：自定义重试策略（前端）

在前端轮询端实现指数退避。

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

### 方案 15：使用 Pest PHP 测试

测试延迟进程创建和执行。

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

## 第4部分：运维与监控

### 方案 16：Supervisor / Horizon 设置

#### 使用 Supervisor（队列工作进程）

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

#### 使用 Supervisor（同步命令）

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

#### 使用 Laravel Horizon

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

### 方案 17：计划清理

在 Laravel 调度程序中注册清理命令。

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

### 方案 18：卡住进程监控

检测卡住的进程并发送警报。

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

**计划：**

```php
Schedule::command('monitor:delayed-processes --threshold=10')
    ->everyFiveMinutes();
```

---

### 方案 19：数据库维护

#### PostgreSQL — 部分索引（已由迁移创建）

包维护已创建了为 PostgreSQL 优化的部分索引。不需要额外设置。

#### PostgreSQL — 大容量的表分区

如果你处理数百万个延迟进程，请考虑按 `created_at` 进行范围分区：

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

#### 定期维护

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

## 第5部分：故障排除

### 故障：进程永久卡在"wait"状态

**症状：** 进程状态保持为 `wait`，永远不会转换到 `done` 或 `error`。

**原因：**
1. 执行期间队列工作进程崩溃
2. 作业超时，但进程未被清理
3. 工作进程在处理时重新启动

**解决方案：**

```bash
# Check for stuck processes
php artisan delayed:unstuck --dry-run

# Reset stuck processes (default: stuck > 60 minutes)
php artisan delayed:unstuck

# Reset with custom timeout (30 minutes)
php artisan delayed:unstuck --timeout=30
```

**预防：** 计划 `delayed:unstuck` 每 15 分钟运行一次（见 [方案 18](#方案-18卡住进程监控)）。

---

### 故障："实体不允许"错误

**症状：** `EntityNotAllowedException: Entity [App\Services\MyService] is not in the allowed_entities list.`

**原因：** 处理程序类未在配置中注册。

**解决方案：**

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\MyService::class, // Add your class here
],
```

> **重要：** 使用完全限定类名 (FQCN)。allowlist 使用严格相等性检查。

---

### 故障：SPA 中的 CSRF 令牌不匹配

**症状：** 状态轮询请求失败，出现 419（CSRF 令牌不匹配）。

**原因：**
1. SPA 未发送 CSRF 令牌
2. HTML 中缺少 `<meta name="csrf-token">`

**解决方案：**

**选项 A：** 确保 meta 标签存在于你的 HTML 中：

```html
<meta name="csrf-token" content="{{ csrf_token() }}">
```

前端轮询器自动读取此标签并包含令牌。

**选项 B：** 在配置中显式传递令牌：

```typescript
applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    headers: {
        'X-CSRF-TOKEN': 'your-token-here',
    },
});
```

**选项 C：** 将状态端点排除在 CSRF 验证之外：

```php
// app/Http/Middleware/VerifyCsrfToken.php
protected $except = [
    'api/common/delayed-process/status*',
];
```

---

### 故障：轮询超时已超出

**症状：** 抛出 `DelayedProcessError`，来自服务器的 `error_message` 为空。该进程可能仍在运行。

**原因：**
1. 进程耗时超过配置的 `timeout`（默认：5 分钟）
2. 超过 `maxAttempts`（默认：100 次尝试 × 3 秒 = 5 分钟）

**解决方案：**

```typescript
// Increase timeouts for heavy operations
applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 5000,     // Poll every 5 seconds
    maxAttempts: 360,          // Up to 360 attempts
    timeout: 1_800_000,        // 30 minutes total
});
```

同时考虑增加后端作业超时：

```php
// config/delayed-process.php
'job' => [
    'timeout' => 1800, // 30 minutes
],
```

---

### 故障：高并发中的竞态条件

**症状：** 同一进程同时执行多次。

**说明：** 这不应该发生。该包使用原子声称：

```sql
UPDATE delayed_processes SET status = 'wait', try = try + 1
WHERE id = ? AND status = 'new'
```

只有一个工作进程可以成功声称一个进程。如果观察到重复执行：

1. **检查 `status='new'` 重置：** 是否有东西在处理时将进程重置回 `new`？
2. **检查 `delayed:unstuck` 计划：** 如果卡住超时太短，它可能会将合法运行的进程重置回 `new`。
3. **增加卡住超时：** 将 `stuck_timeout_minutes` 设置为高于最长预期操作。

---

### 故障：长时间轮询的内存泄漏

**症状：** 浏览器标签页内存使用量在轮询期间随时间增长。

**原因：**
1. 组件卸载时未清理 `patchXHR()` 或 `patchFetch()`
2. 多个拦截器注册后未移除

**解决方案：**

```typescript
// In Vue component — cleanup on unmount
import { onUnmounted } from 'vue';
import { patchFetch } from '@/delayed-process';

const unpatch = patchFetch({ statusUrl: '/api/common/delayed-process/status' });

onUnmounted(() => {
    unpatch();
});
```

对于 Axios，在完成后弹出拦截器：

```typescript
const interceptorId = applyAxiosInterceptor(api, config);

// When no longer needed:
api.interceptors.response.eject(interceptorId);
```

---

### 故障：旧版迁移失败

**症状：** `delayed:migrate-v1` 命令失败，出现 SQL 错误。

**常见原因和修复：**

**1. 列已存在：**

```
SQLSTATE[42701]: Duplicate column: column "error_message" already exists
```

迁移被部分应用。检查存在的列，手动应用剩余更改，或删除新列并重新运行。

**2. 权限被拒绝：**

```
SQLSTATE[42501]: Insufficient privilege
```

数据库用户需要 ALTER TABLE 权限。使用特权用户运行或授予权限。

**3. 生产安全：**

```bash
# The command requires --force in production
php artisan delayed:migrate-v1 --force
```

**4. 大表锁：**

对于有数百万行的表，ALTER TABLE 可能长时间锁定表。考虑：
- 在维护窗口期间运行
- 使用 `pg_repack`（PostgreSQL）或 `pt-online-schema-change`（MySQL）进行零停机迁移
- 手动分步迁移

---

返回 [README](README.zh.md)
