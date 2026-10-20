# 升级指南

可用语言：[English](../en/upgrade.md) | [Русский](../ru/upgrade.md) | [Deutsch](../de/upgrade.md) | [中文](upgrade.md)

每一节列出两个版本之间的变化以及需要采取的操作，按从新到旧排列。
跨越多个版本升级时，需要从下往上依次应用其间的每一节。

- [2.1 → 2.2](#21--22)
- [2.0 → 2.1](#20--21)
- [1.x → 2.x](#1x--2x)

---

## 2.1 → 2.2

模式和配置均无变化。

**命名参数按名称绑定。** 在 2.2 之前，字符串键的参数集总是作为单个数组参数传给处理器。
现在，当每个键都是处理器方法的参数名时，这些键按名称绑定：

```php
$factory->make(ReportService::class, 'build', ...['from' => $from, 'to' => $to]);

// 2.1: build(['from' => ..., 'to' => ...])
// 2.2: build(from: ..., to: ...)
```

声明为 `handle(array $params)` 的处理器仍然收到完整数组，除非键恰好与其参数名一致：
发送给 `handle(array $params)` 的参数集 `['params' => ...]` 现在会按名称绑定。
列表与以前一样按位置传递。

**2.1.2：`DelayedProcessRunner` 构造函数。** 第四个参数现在是可选的
`ProcessProgressInterface`，而不是 `DelayedProcessProgress`。
只有自行构造运行器时才需要注意；从容器解析则无需任何修改。

---

## 2.0 → 2.1

代码无需修改。

2.0.0 要求 PHP ^8.5 和 Laravel 12。从 2.1.0 起，包支持 PHP ^8.2 以及 Laravel 11、12 和 13。

---

## 1.x → 2.x

2.0 是一次重写。表名、任务类和 Artisan 命令保留了原名，但它们周围几乎一切都变了：
模型的行为移到了服务中，状态变成了枚举，只有白名单中的类才能运行，
表增加了新列、JSON 类型和索引。

### 要求

| | 1.x | 2.x |
|---|---|---|
| PHP | Laravel 版本允许的任意版本 | ^8.2（2.0.0：^8.5） |
| Laravel | 6 – 12 | 11、12、13（2.0.0：仅 12） |
| `dskripchenko/php-array-helper` | 必需 | 非必需 |

包不再引入 `dskripchenko/php-array-helper`。如果你的代码调用了其中的
`array_is_associative()`、`array_merge_deep()` 或其他辅助函数，请直接依赖该包：

```bash
composer require dskripchenko/php-array-helper
```

### 第 1 步：清空队列并停止工作进程

由 1.x 入队的任务会到达一个检查白名单（第 3 步）并写入第 4 步之前尚不存在的列的运行器。
部署之前：

1. 停止创建新过程，或将应用置于维护模式。
2. 让队列工作进程和 `delayed:process` 完成正在执行的工作。
3. 停止工作进程和调度器。

停留在 `new` 状态的过程会在升级后重新被处理。停留在 `wait` 状态的过程在升级完成后
由 `php artisan delayed:unstuck` 重置。

### 第 2 步：更新包

```bash
composer require dskripchenko/laravel-delayed-process:^2.2
```

### 第 3 步：发布配置并填写白名单

```bash
php artisan vendor:publish --tag=delayed-process-config
```

**此步骤是必需的。** 在 2.x 中只有 `allowed_entities` 中列出的类才能运行。
该列表默认为空，因此不填写的话，每次调用 `make()` 都会抛出 `EntityNotAllowedException`。

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\ReportService::class,
    // 按类设置队列参数
    \App\Services\HeavyExport::class => ['queue' => 'heavy', 'timeout' => 600],
],
```

要找出你使用的类，请在代码中搜索 `DelayedProcess::make(`，并查看现有行的 `entity` 列：

```sql
SELECT DISTINCT entity, method FROM delayed_processes;
```

处理器必须是带有公共方法的类。1.x 还接受空实体加全局函数名，或者不带方法的类（可调用对象）。
2.x 两者都不接受：请将此类代码包装到类方法中。

其他设置（尝试次数、清理期限、卡住超时、任务超时等）见[配置参考](README.md#配置参考)。

### 第 4 步：升级表

**顺序很重要：先运行 `delayed:migrate-v1`，再运行 `migrate`。**

```bash
php artisan delayed:migrate-v1 --force
php artisan migrate --force
```

`delayed:migrate-v1` 将 1.x 的表升级到 2.0 模式：

- 添加 `error_message` 和 `error_trace`；
- 为 `status` 添加 CHECK 约束（PostgreSQL、MySQL 8.0.16+、MariaDB）；
- PostgreSQL：将 `parameters`、`data`、`logs` 转换为 `jsonb`，将
  `created_at`、`updated_at` 转换为 `timestamptz`，创建部分索引；
- MySQL/MariaDB：将这三列转换为 `json`，创建复合索引；
- SQLite：只添加这两列。

随后 `migrate` 运行包迁移
`2026_03_11_000001_add_extended_columns_to_delayed_processes_table`，
它添加 `started_at`、`duration_ms`、`callback_url`、`progress`、`expires_at`，
并将 CHECK 约束扩展到 `expired` 和 `cancelled`。

如果顺序相反，升级会失败：

- **MySQL/MariaDB：** 迁移把它的列放在尚不存在的 `error_trace` 之后，
  因而以 "Unknown column" 失败。
- **PostgreSQL：** 迁移成功，随后 `delayed:migrate-v1` 添加两列，
  却在迁移已创建的 CHECK 约束上失败。再次运行时，由于 `error_message` 已存在，
  它报告 "Already migrated"，`jsonb` 转换和索引永远不会被应用。

如果部署流程会自行运行 `migrate`，请在该步骤之前运行 `delayed:migrate-v1`。

在生产数据上运行之前请检查：

- **时区（PostgreSQL）。** 时间戳按 UTC 转换（`AT TIME ZONE 'UTC'`）。
  如果应用在 `created_at`/`updated_at` 中存储的是本地时间，转换后的值会偏移相应的时差。
- **无效 JSON。** 如果某行的 `parameters`、`data` 或 `logs` 不是有效 JSON，转换会失败。
  1.x 在这些列中总是写入 JSON，但手工编辑过的行值得先检查一遍。
- **状态。** CHECK 约束接受 `new`、`wait`、`done`、`error`。
  其他状态的行必须在升级前修正。
- **备份。** 在 MySQL 上模式变更不是事务性的：中途失败会使表处于部分升级的状态。
  请先备份该表。

1.x 和 2.x 的建表迁移同名，因此 Laravel 认为它已经运行过，不会再次创建表。

### 第 5 步：更新代码

#### 创建过程

`DelayedProcess::make()` 已被移除。过程通过 `ProcessFactoryInterface` 创建，参数相同：

```php
// 1.x
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

$process = DelayedProcess::make(ReportService::class, 'build', $from, $to);

// 2.x
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;

$process = app(ProcessFactoryInterface::class)->make(ReportService::class, 'build', $from, $to);
```

尽可能将 `ProcessFactoryInterface` 注入到构造函数或控制器方法中，而不是调用 `app()`。
工厂在保存之前会检查白名单和方法，因此拼写错误现在会在创建时失败，而不是在工作进程中。

`DelayedProcessFactory::makeWithCallback($entity, $method, $url, ...$params)`
还会在过程结束时发送 Webhook；参见 README。

#### 已移除的模型方法

| 1.x | 2.x |
|---|---|
| `DelayedProcess::make($entity, $method, ...$params)` | `ProcessFactoryInterface::make()` |
| `$process->run()` | `app(ProcessRunnerInterface::class)->run($process)` |
| `$process->log($level, $message, $context)` | 在处理器中通过 `Log::...()` 写入；由任务收集日志行 |
| `$process->toResponse()` | 参见[响应](#响应) |
| `DelayedProcess::STATUS_NEW` … `STATUS_ERROR` | `ProcessStatus::New` … `ProcessStatus::Error` |
| `DelayedProcess::DEFAULT_ATTEMPTS` | `config('delayed-process.default_attempts')` |

#### 状态是枚举

`$process->status` 现在是 `ProcessStatus` 枚举，而不是字符串。将其作为字符串比较或输出的代码将无法工作：

```php
// 1.x
if ($process->status === DelayedProcess::STATUS_ERROR) { ... }
strtoupper($process->status);

// 2.x
use Dskripchenko\DelayedProcess\Enums\ProcessStatus;

if ($process->status === ProcessStatus::Error) { ... }
strtoupper($process->status->value);
```

数据库查询可以继续使用字符串值（`where('status', 'new')`），
也可以使用构建器作用域：`whereNew()`、`whereTerminal()`、`whereStuck()`、
`whereExpired()`、`whereUuids()`、`olderThanDays()`。

新增了两个状态：`expired` 和 `cancelled`。两者都是终端状态：
把“既不是 `done` 也不是 `error`”视为“仍在运行”的代码，必须改用 `$process->status->isTerminal()`。

#### 响应

`Resources\DelayedProcess` 已被结构不同的 `Resources\DelayedProcessResource` 取代。
1.x 的 `toResponse()` 返回：

```json
{ "delayed": { "uuid": "..." } }
```

若要在启动过程的端点上保留这种响应，请自行构建。内置的前端拦截器会查找 `delayed.uuid`：

```php
return response()->json([
    'delayed' => ['uuid' => $process->uuid, 'status' => $process->status->value],
]);
```

`DelayedProcessResource` 用于状态端点，返回
`uuid`、`status`、`data`（仅限终端状态）、`error_message`、
`progress`、`started_at`、`duration_ms`、`attempts`、`current_try`、
`created_at`、`updated_at`。

#### 扩展模型

`DelayedProcess` 的子类在查询和管理界面中仍然可用，但有以下区别：

- 工厂创建的是基础 `DelayedProcess`，而不是你的子类，因此子类中定义的模型事件和访问器在创建时不会执行；
- 类型转换在 `casts()` 中声明。子类上的 `$casts` 属性会与之合并，不会替换 `status` 的枚举转换；
- `$fillable` 现在是 `entity`、`method`、`parameters`、`callback_url`。
  `uuid`、`status`、`data`、`logs`、`attempts`、`try` 不再支持批量赋值：
  `create([... 'status' => ...])` 会静默丢弃它们。

### 行为变化

这些变化不需要修改代码，但可能改变你观察到的行为。

| | 1.x | 2.x |
|---|---|---|
| 捕获的错误 | 仅 `Exception`；`Error`（`TypeError` 等）会让过程停留在 `wait` | 任何 `Throwable`；消息和堆栈写入 `error_message`/`error_trace` |
| 再次运行已完成的过程 | 对 `done`/`error` 过程调用 `run()` 会再次运行它 | 已完成的过程会被跳过 |
| 两个工作进程，一个过程 | 可能都运行它 | 过程通过原子 `UPDATE` 认领 |
| `uuid` | UUID v4 | UUID v7（旧值仍然有效） |
| 日志条目 | 以时间为键，`Level`/`Date`/`Message`/`Context` | `level`/`date`/`message` 列表，在运行结束时写入 |
| 日志上下文 | 始终保存 | 除非 `log_sensitive_context` 为 `true`，否则丢弃 |
| JSON 中的 `created_at` | `Y-m-d H:i:s` | ISO 8601（模型的默认序列化） |
| 任务重试 | 工作进程的 `--tries` | 配置中的 `job.tries`，默认为 1 |

1.x 写入的行保留旧格式的日志条目。读取 `logs` 的代码必须同时兼容两种格式。

#### `delayed:process` 不再自行退出

在 1.x 中，`delayed:process` 处理完所有 `new` 过程后退出。在 2.x 中它会一直运行直到被停止，
队列为空时休眠 `command.sleep` 秒。

如果调度器每分钟运行它一次，现在每次运行都会持续存活，副本不断累积。请选择其一：

```php
// 保留在调度器中：没有剩余任务时退出
$schedule->command('delayed:process --max-iterations=1000')
    ->everyMinute()
    ->withoutOverlapping();
```

或者在 Supervisor 或 systemd 下将 `php artisan delayed:process` 作为常驻进程运行，并将其从调度器中移除。

#### 新命令

将需要的命令添加到调度器：

```php
$schedule->command('delayed:clear')->daily();      // 与 1.x 相同
$schedule->command('delayed:unstuck')->everyTenMinutes();
$schedule->command('delayed:expire')->everyMinute(); // 仅在设置 default_ttl_minutes 时
```

`delayed:clear` 现在接受 `--days` 和 `--chunk`，并分块删除；
默认期限为 `clear_after_days`（30，与 1.x 相同）。

### 检查清单

- [ ] 队列已清空，工作进程已停止
- [ ] `composer require dskripchenko/laravel-delayed-process:^2.2`
- [ ] 如果使用了 `dskripchenko/php-array-helper` 的辅助函数，已直接依赖该包
- [ ] 配置已发布，每个处理器类都在 `allowed_entities` 中
- [ ] 表已备份
- [ ] `delayed:migrate-v1 --force`，**然后** `migrate --force`
- [ ] `DelayedProcess::make()` → `ProcessFactoryInterface::make()`
- [ ] `STATUS_*` 常量和字符串比较 → `ProcessStatus`
- [ ] `toResponse()` → 显式的 `delayed.uuid` 响应
- [ ] 读取 `logs` 的代码兼容两种格式
- [ ] `delayed:process` 以 `--max-iterations` 加入调度器，或作为守护进程运行
- [ ] 部署后运行一次 `delayed:unstuck`，然后加入调度器
