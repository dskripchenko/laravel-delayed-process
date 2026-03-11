# Laravel 延时处理

[![Packagist Version](https://img.shields.io/packagist/v/dskripchenko/laravel-delayed-process)](https://packagist.org/packages/dskripchenko/laravel-delayed-process)
[![License](https://img.shields.io/packagist/l/dskripchenko/laravel-delayed-process)](../LICENSE.md)
[![PHP Version](https://img.shields.io/packagist/dependency-v/dskripchenko/laravel-delayed-process/php)](../composer.json)
[![Laravel Version](https://img.shields.io/packagist/dependency-v/dskripchenko/laravel-delayed-process/laravel/framework)](../composer.json)

**语言:** [English](../README.md) | [Русский](README.ru.md) | [Deutsch](README.de.md) | [中文](README.zh.md)

在 Laravel 中异步执行长时间运行的操作，支持基于 UUID 的跟踪、自动重试、安全白名单以及适用于 Axios、Fetch 和 XHR 的透明前端拦截器。

---

## 目录

- [功能](#功能)
- [要求](#要求)
- [安装](#安装)
- [快速开始](#快速开始)
- [架构](#架构)
- [过程生命周期](#过程生命周期)
- [项目结构](#项目结构)
- [后端 API](#后端-api)
- [前端拦截器](#前端拦截器)
- [配置参考](#配置参考)
- [数据库模式](#数据库模式)
- [安全](#安全)
- [实用手册](#实用手册)
- [许可证](#许可证)

---

## 功能

- **异步处理** — 将耗时操作卸载到队列，立即返回 UUID
- **UUID 跟踪** — 每个过程都获得一个 UUIDv7 用于状态轮询
- **自动重试** — 可配置的最大尝试次数，最终失败时捕获错误
- **安全白名单** — 仅允许显式授权的实体类执行
- **前端拦截器** — 透明的 Axios、Fetch 和 XHR 拦截器，自动轮询至完成
- **批量轮询** — `BatchPoller` 类用于在单个请求中轮询多个 UUID
- **循环预防** — `X-Delayed-Process-Poll` 头部防止拦截器重新拦截轮询请求
- **生命周期事件** — `ProcessCreated`、`ProcessStarted`、`ProcessCompleted`、`ProcessFailed` 事件用于可观测性
- **进度跟踪** — 通过 `ProcessProgressInterface` 进行 0-100% 的进度更新
- **Webhook 回调** — 在终端状态下对 `callback_url` 发送 HTTP POST 通知
- **TTL / 过期** — 通过 `expires_at` + `delayed:expire` 命令自动过期过程
- **取消** — 通过构建器取消处于 `new`/`wait` 状态的过程
- **每个实体的队列配置** — 为每个实体类配置队列、连接和超时
- **Artisan 命令** — `delayed:process`、`delayed:clear`、`delayed:unstuck`、`delayed:expire`、`delayed:migrate-v1`（旧版迁移）
- **结构化日志** — 捕获执行期间的所有 `MessageLogged` 事件，可配置的缓冲限制
- **原子申领** — 通过原子 UPDATE 实现无竞态条件的过程申领
- **PostgreSQL 优化** — 部分索引、JSONB 列、TIMESTAMPTZ；也支持 MySQL/MariaDB

---

## 要求

| 依赖项 | 版本 |
|--------|------|
| PHP | ^8.5 |
| Laravel | ^12.0 |
| 数据库 | PostgreSQL（推荐）或 MySQL/MariaDB |

---

## 安装

```bash
composer require dskripchenko/laravel-delayed-process
```

发布配置文件：

```bash
php artisan vendor:publish --tag=delayed-process-config
```

运行迁移：

```bash
php artisan migrate
```

在 `config/delayed-process.php` 中注册允许的实体：

```php
'allowed_entities' => [
    \App\Services\ReportService::class,
    \App\Services\ExportService::class,
],
```

---

## 快速开始

### 1. 创建处理器

```php
<?php

declare(strict_types=1);

namespace App\Services;

final class ReportService
{
    public function generate(int $userId, string $format): array
    {
        // 长时间运行的操作（30+ 秒）
        $data = $this->buildReport($userId, $format);

        return ['url' => $data['url'], 'rows' => $data['count']];
    }
}
```

### 2. 触发延时过程（后端）

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

### 3. 状态端点

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

### 4. 前端 — Axios 拦截器

```typescript
import axios from 'axios';
import { applyAxiosInterceptor } from './delayed-process';

const api = axios.create({ baseURL: '/api' });

applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 3000,
});

// 使用 — 轮询完全自动
const response = await api.post('/reports/generate', { user_id: 1, format: 'pdf' });
console.log(response.data.payload); // { url: '...', rows: 150 }
```

---

## 架构

### 生命周期概览

```
客户端                           服务器                           队列工作进程
  │                               │                                   │
  ├─── POST /api/reports ────────►│                                   │
  │                               ├── Factory.make()                  │
  │                               │   ├─ 验证实体+方法                  │
  │                               │   ├─ INSERT (status=new)          │
  │                               │   └─ 分发任务 ────────────────────►│
  │◄── { delayed: { uuid } } ─────┤                                   │
  │                               │                                   ├── 申领 (status=wait)
  │                               │                                   ├── 解析 callable
  │                               │                                   ├── 执行处理器
  │                               │                                   ├── 保存结果 (status=done)
  │─── GET /status?uuid=... ─────►│                                   │
  │◄── { status: "wait" } ────────┤                                   │
  │                               │                                   │
  │─── GET /status?uuid=... ─────►│                                   │
  │◄── { status: "done", data } ──┤                                   │
  │                               │                                   │
  ▼ 拦截器返回数据                  │                                   │
```

### 组件概览

| 组件 | 类 | 用途 |
|------|-----|------|
| **模型** | `DelayedProcess` | Eloquent 模型 — 存储过程状态、结果、日志 |
| **构建器** | `DelayedProcessBuilder` | 自定义 Eloquent 构建器 — `whereNew()`、`whereStuck()`、`claimForExecution()` |
| **工厂** | `DelayedProcessFactory` | 创建过程，验证实体，分发任务 |
| **运行器** | `DelayedProcessRunner` | 执行过程 — 申领、解析、运行、处理错误 |
| **日志记录器** | `DelayedProcessLogger` | 执行期间缓冲日志条目，刷新到模型 |
| **任务** | `DelayedProcessJob` | Laravel 队列任务 — 桥接队列和运行器 |
| **资源** | `DelayedProcessResource` | 状态端点的 JSON 响应格式 |
| **解析器** | `CallableResolver` | 验证并解析实体+方法为 callable |
| **EntityConfigResolver** | `EntityConfigResolver` | 解析每个实体的队列/连接/超时配置 |
| **CallbackDispatcher** | `CallbackDispatcher` | 在终端状态下发送 Webhook POST |
| **进度** | `DelayedProcessProgress` | 更新过程进度 (0-100%) |

### 契约

| 接口 | 默认实现 |
|------|---------|
| `ProcessFactoryInterface` | `DelayedProcessFactory` |
| `ProcessRunnerInterface` | `DelayedProcessRunner` |
| `ProcessLoggerInterface` | `DelayedProcessLogger` |
| `ProcessProgressInterface` | `DelayedProcessProgress` |

所有绑定在 `DelayedProcessServiceProvider` 中注册。通过 Laravel 的服务容器自定义实现。

### 事件

| 事件 | 触发时间 | 属性 |
|-------|------------|------------|
| `ProcessCreated` | 在 `Factory::make()` 保存过程后 | `process` |
| `ProcessStarted` | 在运行器申领并启动执行后 | `process` |
| `ProcessCompleted` | 成功执行后 | `process` |
| `ProcessFailed` | 执行中发生异常后 | `process`, `exception` |

---

## 过程生命周期

### 状态转换

```
                                         ┌───────────┐
                               取消       │ CANCELLED │
                          ┌─────────────►└───────────┘
                          │
┌─────┐     申领      ┌────┴─┐      成功      ┌──────┐
│ NEW ├─────────────►│ WAIT ├───────────────►│ DONE │
└──┬──┘              └──┬───┘                └──────┘
   ▲                    │
   │     尝试 < 最大次数  │ 失败
   └────────────────────┤
   │                    │ 尝试 >= 最大次数
   │ expires_at 已到期   ▼
   │                  ┌───────┐
   └──────┐           │ ERROR │
          ▼           └───────┘
     ┌─────────┐
     │ EXPIRED │
     └─────────┘
```

| 状态 | 值 | 描述 |
|------|-----|------|
| **新** | `new` | 已创建，等待执行。符合申领条件。 |
| **等待** | `wait` | 被工作进程申领，当前正在执行。阻止重新进入。 |
| **完成** | `done` | 成功完成。结果存储在 `data` 中。终端状态。 |
| **错误** | `error` | 所有重试次数已耗尽。错误详情在 `error_message` / `error_trace` 中。终端状态。 |
| **已过期** | `expired` | TTL 在完成前已超出。由 `delayed:expire` 标记。终端状态。 |
| **已取消** | `cancelled` | 通过构建器手动取消。终端状态。 |

### 重试逻辑

1. 工作进程原子性申领过程：`UPDATE ... SET status='wait', try=try+1 WHERE status='new'`
2. 处理器执行
3. 成功时：`status → done`，结果保存到 `data`
4. 失败时：
   - 若 `try < attempts`：`status → new`（符合重试条件）
   - 若 `try >= attempts`：`status → error`，保存错误详情

---

## 项目结构

```
src/
├── Builders/
│   └── DelayedProcessBuilder.php       # 自定义 Eloquent 构建器 (whereNew, whereExpired, cancel, claimForExecution)
├── Components/
│   └── Events/
│       └── Dispatcher.php              # 具有按 ID 监听/取消监听的事件分发器
├── Console/
│   └── Commands/
│       ├── DelayedProcessCommand.php       # delayed:process — 同步队列工作进程
│       ├── ClearOldDelayedProcessCommand.php # delayed:clear — 删除旧的终端过程
│       ├── ExpireProcessesCommand.php      # delayed:expire — 标记已过期的过程
│       ├── UnstuckProcessesCommand.php     # delayed:unstuck — 重置卡住的过程
│       └── MigrateFromV1Command.php        # 旧版架构迁移
├── Contracts/
│   ├── ProcessFactoryInterface.php     # 工厂契约
│   ├── ProcessRunnerInterface.php      # 运行器契约
│   ├── ProcessLoggerInterface.php      # 日志记录器契约
│   ├── ProcessProgressInterface.php    # 进度跟踪契约
│   └── ProcessObserverInterface.php    # 观察者契约 (onCreated, onStarted, 等等)
├── Enums/
│   └── ProcessStatus.php               # new | wait | done | error | expired | cancelled
├── Events/
│   ├── ProcessCreated.php              # 在工厂创建过程后触发
│   ├── ProcessStarted.php             # 在运行器申领过程后触发
│   ├── ProcessCompleted.php           # 成功执行后触发
│   └── ProcessFailed.php             # 执行失败后触发
├── Exceptions/
│   ├── CallableResolutionException.php # 类/方法未找到
│   ├── EntityNotAllowedException.php   # 实体不在白名单中
│   └── InvalidParametersException.php  # 非可序列化参数
├── Jobs/
│   └── DelayedProcessJob.php           # 队列任务 — 通过运行器运行过程
├── Models/
│   └── DelayedProcess.php              # Eloquent 模型，带 UUIDv7、进度、TTL、回调
├── Providers/
│   └── DelayedProcessServiceProvider.php # 注册绑定、迁移、命令
├── Resources/
│   └── DelayedProcessResource.php      # JSON 响应资源
└── Services/
    ├── CallableResolver.php            # 验证白名单 + 解析 callable
    ├── CallbackDispatcher.php          # 在终端状态下发送 Webhook POST
    ├── DelayedProcessFactory.php       # 创建过程 + 分发任务 + 事件
    ├── DelayedProcessLogger.php        # 执行期间缓冲日志，可配置限制
    ├── DelayedProcessProgress.php      # 进度跟踪 (0-100%)
    ├── DelayedProcessRunner.php        # 申领 + 执行 + 事件 + 回调
    └── EntityConfigResolver.php        # 解析每个实体的队列/连接/超时配置

resources/js/delayed-process/
├── index.ts                            # 公开导出
├── types.ts                            # TypeScript 类型、BatchPoller 类型、DelayedProcessError
├── core/
│   ├── config.ts                       # 默认配置 + CSRF 自动检测
│   ├── poller.ts                       # 轮询循环，支持超时和中止
│   └── batch-poller.ts                # BatchPoller — 一次轮询多个 UUID
├── axios/
│   └── interceptor.ts                  # Axios 响应拦截器
├── fetch/
│   └── patch.ts                        # window.fetch monkey-patch
└── xhr/
    └── patch.ts                        # XMLHttpRequest monkey-patch (双patch防护)
```

---

## 后端 API

### 创建过程

使用 `ProcessFactoryInterface`（通过 DI 解析）：

```php
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;

$process = $factory->make(
    entity: \App\Services\ExportService::class,
    method: 'exportCsv',
    // 可变参数传递给处理器方法：
    $userId,
    $filters,
);
```

**`make()` 内部发生的事情：**

1. 验证实体在 `allowed_entities` 配置中
2. 验证类和方法存在
3. 验证参数是可 JSON 序列化的
4. 在数据库事务中创建 `DelayedProcess` 模型（自动生成 UUIDv7，设置 `status=new`，从 TTL 配置计算 `expires_at`）
5. 从每个实体的配置中配置任务的队列/连接/超时
6. 分发 `DelayedProcessJob` 到队列
7. 触发 `ProcessCreated` 事件
8. 返回持久化的模型

#### 使用 Webhook 回调创建

```php
$process = $factory->makeWithCallback(
    entity: \App\Services\ExportService::class,
    method: 'exportCsv',
    callbackUrl: 'https://your-app.com/webhooks/process-done',
    $userId,
);
```

当过程到达终端状态（`done`、`error`、`expired`、`cancelled`）时，HTTP POST 将被发送到 `callbackUrl`，包含 `{uuid, status, data}`。

#### 每个实体的队列配置

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

### 状态端点响应

`DelayedProcessResource` 返回：

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

注意：
- `data` 仅在 `status` 为终端状态（`done`、`error`、`expired`、`cancelled`）时包含
- `error_message` 和 `is_error_truncated` 仅在存在错误时包含
- `progress` (0-100) 反映执行进度
- `started_at` 和 `duration_ms` 跟踪执行时间

### Artisan 命令

#### `delayed:process` — 同步工作进程

处理延时任务，无需队列工作进程。适用于开发或单服务器部署。

```bash
php artisan delayed:process
php artisan delayed:process --max-iterations=100
php artisan delayed:process --sleep=10
```

| 选项 | 默认值 | 描述 |
|------|--------|------|
| `--max-iterations` | `0`（无限） | N 个过程后停止。`0` = 永远运行。 |
| `--sleep` | `5` | 未找到过程时睡眠秒数。 |

#### `delayed:clear` — 清理旧过程

删除超过指定天数的终端（`done` / `error`）过程。

```bash
php artisan delayed:clear
php artisan delayed:clear --days=7
php artisan delayed:clear --chunk=1000
```

| 选项 | 默认值 | 描述 |
|------|--------|------|
| `--days` | `30` | 删除早于 N 天的过程。 |
| `--chunk` | `500` | 批量删除大小，用于内存效率。 |

#### `delayed:unstuck` — 重置卡住的过程

将卡在 `wait` 状态的过程重置回 `new`，以便可以重试。

```bash
php artisan delayed:unstuck
php artisan delayed:unstuck --timeout=30
php artisan delayed:unstuck --dry-run
```

| 选项 | 默认值 | 描述 |
|------|--------|------|
| `--timeout` | `60` | 在 `wait` 中考虑过程卡住后的分钟数。 |
| `--dry-run` | `false` | 列出卡住的过程而不重置它们。 |

#### `delayed:expire` — 过期 TTL 过程

将 `expires_at` 已超出的过程标记为 `expired`。

```bash
php artisan delayed:expire
php artisan delayed:expire --dry-run
```

| 选项 | 默认值 | 描述 |
|------|--------|------|
| `--dry-run` | `false` | 显示计数而不进行修改。 |

#### `delayed:migrate-v1` — 旧版迁移

升级数据库模式。添加 `error_message` / `error_trace` 列，转换为 JSONB/JSON，创建索引，并添加 CHECK 约束。

```bash
php artisan delayed:migrate-v1
php artisan delayed:migrate-v1 --force
```

---

## 前端拦截器

`resources/js/delayed-process/` 模块提供透明拦截器，自动检测延时过程响应并轮询至完成。

### 工作原理

1. 您的 API 返回包含 `{ payload: { delayed: { uuid: "..." } } }` 的响应
2. 拦截器检测 UUID
3. 它开始轮询状态端点：`GET {statusUrl}?uuid={uuid}`
4. 当 `status` 变为 `done` 时，拦截器用结果数据替换响应负载
5. 当 `status` 变为 `error`、`expired` 或 `cancelled` 时，拦截器抛出 `DelayedProcessError`
6. 轮询请求包含 `X-Delayed-Process-Poll: 1` 头部以防止无限循环

### 文件结构

| 文件 | 用途 |
|------|------|
| `index.ts` | 公开导出 |
| `types.ts` | TypeScript 类型、`BatchPollerConfig`、`DelayedProcessError` |
| `core/config.ts` | 默认配置，CSRF 自动检测，`resolveConfig()` |
| `core/poller.ts` | `pollUntilDone()` — 核心轮询循环，支持超时和中止 |
| `core/batch-poller.ts` | `BatchPoller` — 在单个请求中轮询多个 UUID |
| `axios/interceptor.ts` | `applyAxiosInterceptor()` — Axios 响应拦截器 |
| `fetch/patch.ts` | `patchFetch()` — monkey-patch `window.fetch` |
| `xhr/patch.ts` | `patchXHR()` — monkey-patch `XMLHttpRequest` (双patch防护) |

### Axios 拦截器

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
        console.log(`轮询 ${uuid}，尝试 ${attempt}`);
    },
});

// 移除：api.interceptors.response.eject(interceptorId);
```

### Fetch Patch

```typescript
import { patchFetch } from './delayed-process';

const unpatch = patchFetch({
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 3000,
});

// 所有 fetch() 调用现在自动轮询延时过程
const response = await fetch('/api/reports/generate', { method: 'POST' });
const data = await response.json();
console.log(data.payload); // 已解析的结果，而非 UUID

// 恢复原始 fetch：
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
    console.log(data.payload); // 已解析的结果
};
xhr.send();

// 恢复原始 XHR：
unpatch();
```

### DelayedProcessConfig

| 选项 | 类型 | 默认值 | 描述 |
|------|------|--------|------|
| `statusUrl` | `string` | `'/api/common/delayed-process/status'` | 轮询过程状态的 URL |
| `pollingInterval` | `number` | `3000` | 轮询请求之间的毫秒数 |
| `maxAttempts` | `number` | `100` | 最大轮询次数 |
| `timeout` | `number` | `300000` | 总超时时间（毫秒），5 分钟 |
| `headers` | `Record<string, string>` | `{}` | 轮询请求的额外头部 |
| `onPoll` | `(uuid: string, attempt: number) => void` | `undefined` | 每次轮询时调用的回调 |

CSRF token 从 `<meta name="csrf-token">` 自动包含在轮询请求中。

### 批量轮询

用于同时轮询多个过程（例如，批量操作）：

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

当过程以 `error`、`expired` 或 `cancelled` 状态完成或轮询超时时抛出。

```typescript
import { DelayedProcessError } from './delayed-process';

try {
    const response = await api.post('/api/reports/generate');
} catch (error) {
    if (error instanceof DelayedProcessError) {
        console.error(error.uuid);         // 过程 UUID
        console.error(error.status);       // 'error' | 'expired' | 'cancelled'
        console.error(error.errorMessage); // 服务器端错误消息
    }
}
```

### 循环预防

所有轮询请求都包含头部 `X-Delayed-Process-Poll: 1`。拦截器检查此头部并跳过轮询请求上的拦截，防止无限轮询循环。

---

## 配置参考

文件：`config/delayed-process.php`

| 键 | 类型 | 默认值 | 描述 |
|-----|------|--------|------|
| `allowed_entities` | `array` | `[]` | 作为过程处理器的类的 FQCN 列表；字符串值或 `Entity::class => [config]` 键控数组 |
| `default_attempts` | `int` | `5` | 标记为 `error` 前的最大重试次数 |
| `clear_after_days` | `int` | `30` | `delayed:clear` 删除早于该时间的终端过程 |
| `stuck_timeout_minutes` | `int` | `60` | `delayed:unstuck` 在该时间后认为 `wait` 过程卡住 |
| `log_sensitive_context` | `bool` | `false` | 在过程日志中包含日志上下文数组 |
| `log_buffer_limit` | `int` | `500` | 每个过程的内存缓冲区中的最大日志条目数（0 = 无限） |
| `callback.enabled` | `bool` | `false` | 在终端状态下启用 Webhook POST |
| `callback.timeout` | `int` | `10` | Webhook HTTP 超时（秒） |
| `default_ttl_minutes` | `int\|null` | `null` | 新过程的默认 TTL（`null` = 无过期） |
| `job.timeout` | `int` | `300` | 队列任务超时（秒） |
| `job.tries` | `int` | `1` | 队列任务重试次数（不同于过程尝试） |
| `job.backoff` | `array` | `[30, 60, 120]` | 队列任务退避延迟（秒） |
| `command.sleep` | `int` | `5` | `delayed:process` 空闲时睡眠（秒） |
| `command.max_iterations` | `int` | `0` | `delayed:process` 迭代限制（`0` = 无限） |
| `command.throttle` | `int` | `100000` | `delayed:process` 迭代间的节流（微秒） |
| `clear_chunk_size` | `int` | `500` | `delayed:clear` 批量删除大小 |

---

## 数据库模式

### 表：`delayed_processes`

| 列 | 类型 | 默认值 | 描述 |
|-----|------|--------|------|
| `id` | `bigint` PK | 自增 | 主键 |
| `uuid` | `string(36)` UNIQUE | 自动（UUIDv7） | 唯一过程标识符 |
| `entity` | `string` nullable | `NULL` | 处理器类的 FQCN |
| `method` | `string` | — | 处理器方法名称 |
| `parameters` | `jsonb` / `json` | `[]` | 序列化的调用参数 |
| `data` | `jsonb` / `json` | `[]` | 执行结果负载 |
| `logs` | `jsonb` / `json` | `[]` | 捕获的日志条目 |
| `status` | `string` | `'new'` | 过程状态（`new`、`wait`、`done`、`error`、`expired`、`cancelled`） |
| `attempts` | `tinyint unsigned` | `5` | 最大重试次数 |
| `try` | `tinyint unsigned` | `0` | 当前尝试次数 |
| `error_message` | `string(1000)` nullable | `NULL` | 最后错误消息（截断及指示符） |
| `error_trace` | `text` nullable | `NULL` | 最后错误堆栈跟踪 |
| `started_at` | `timestamptz` nullable | `NULL` | 执行开始时间 |
| `duration_ms` | `bigint unsigned` nullable | `NULL` | 执行持续时间（毫秒） |
| `callback_url` | `string(2048)` nullable | `NULL` | 终端状态通知的 Webhook URL |
| `progress` | `tinyint unsigned` | `0` | 执行进度 (0-100) |
| `expires_at` | `timestamptz` nullable | `NULL` | 过程过期时间 (TTL) |
| `created_at` | `timestamptz` | NOW | 创建时间戳 |
| `updated_at` | `timestamptz` | NOW | 最后更新时间戳 |

### 索引

**PostgreSQL**（用于最优性能的部分索引）：

| 索引 | 条件 |
|------|------|
| `(status, try)` | `WHERE status = 'new'` |
| `(created_at)` | `WHERE status IN ('done', 'error', 'expired', 'cancelled')` |
| `(updated_at)` | `WHERE status = 'wait'` |
| `(expires_at)` | `WHERE status IN ('new', 'wait') AND expires_at IS NOT NULL` |

**MySQL / MariaDB**（复合索引）：

| 索引 |
|------|
| `(status, try)` |
| `(status, created_at)` |
| `(status, updated_at)` |

### 约束

- `CHECK (status IN ('new', 'wait', 'done', 'error', 'expired', 'cancelled'))` 在所有数据库上

---

## 安全

### 实体白名单

仅列在 `config('delayed-process.allowed_entities')` 中的类可以执行。尝试使用未列出的类创建过程将抛出 `EntityNotAllowedException`。

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\ReportService::class,
    \App\Services\ExportService::class,
    // 仅这些类可用作处理器
],
```

### Callable 验证

执行前，`CallableResolver` 验证：
1. 实体类在白名单中
2. 类存在（`class_exists()`）
3. 方法存在（`method_exists()`）

实例化使用 `app($entity)` — 完整支持 Laravel DI 容器。

### 日志隐私

将 `log_sensitive_context` 设置为 `false`（默认）以从捕获的日志条目中删除上下文数组。仅存储日志级别、时间戳和消息。

### CSRF 保护

前端轮询器自动读取 `<meta name="csrf-token">` 并在轮询请求头部中包含它。确保您的状态端点在 CSRF 中间件后面或显式验证令牌。

---

## 实用手册

如需使用方法、模式和故障排除，请参阅 **[实用手册](cookbook.zh.md)**。

可用语言：[English](cookbook.md) | [Русский](cookbook.ru.md) | [Deutsch](cookbook.de.md) | [中文](cookbook.zh.md)

---

## 前端集成指南

有关将拦截器集成到 **Vue.js 3** 和 **React** 应用程序中的详细分步指南，请参阅 **[前端拦截器指南](frontend-interceptors-guide.zh.md)**。

包括：composables/hooks、进度跟踪、批量轮询、错误处理、SSR 支持和测试。

---

## 许可证

[MIT](../LICENSE.md) &copy; Denis Skripchenko
