# Laravel Delayed Process

[![Packagist Version](https://img.shields.io/packagist/v/dskripchenko/laravel-delayed-process)](https://packagist.org/packages/dskripchenko/laravel-delayed-process)
[![License](https://img.shields.io/packagist/l/dskripchenko/laravel-delayed-process)](../LICENSE.md)
[![PHP Version](https://img.shields.io/packagist/dependency-v/dskripchenko/laravel-delayed-process/php)](../composer.json)
[![Laravel Version](https://img.shields.io/packagist/dependency-v/dskripchenko/laravel-delayed-process/laravel/framework)](../composer.json)

**Язык:** [English](../README.md) | [Русский](README.ru.md) | [Deutsch](README.de.md) | [中文](README.zh.md)

Асинхронное выполнение долгих операций в Laravel с отслеживанием по UUID, автоматическим повтором, белым списком безопасности и прозрачными фронтенд-перехватчиками для Axios, Fetch и XHR.

---

## Содержание

- [Особенности](#особенности)
- [Требования](#требования)
- [Установка](#установка)
- [Быстрый старт](#быстрый-старт)
- [Архитектура](#архитектура)
- [Жизненный цикл процесса](#жизненный-цикл-процесса)
- [Структура проекта](#структура-проекта)
- [Backend API](#backend-api)
- [Frontend перехватчики](#frontend-перехватчики)
- [Справочник конфигурации](#справочник-конфигурации)
- [Схема базы данных](#схема-базы-данных)
- [Безопасность](#безопасность)
- [Книга рецептов](#книга-рецептов)
- [Лицензия](#лицензия)

---

## Особенности

- **Асинхронная обработка** — разгрузка тяжёлых операций в очередь, мгновенный возврат UUID
- **Отслеживание по UUID** — каждый процесс получает UUIDv7 для опроса статуса
- **Автоматический повтор** — настраиваемое максимальное количество попыток с захватом ошибок при финальном отказе
- **Белый список безопасности** — в очередь можно поставить только явно разрешённые классы сущностей
- **Frontend перехватчики** — прозрачные перехватчики для Axios, Fetch и XHR, которые автоматически опрашивают статус до завершения
- **Групповой опрос** — класс `BatchPoller` для опроса нескольких UUID в одном запросе
- **Предотвращение циклов** — заголовок `X-Delayed-Process-Poll` предотвращает повторное перехватывание запросов опроса
- **События жизненного цикла** — события `ProcessCreated`, `ProcessStarted`, `ProcessCompleted`, `ProcessFailed` для наблюдаемости
- **Отслеживание прогресса** — обновления прогресса 0-100% через `ProcessProgressInterface`
- **Webhook обратные вызовы** — HTTP POST уведомления на `callback_url` при достижении терминального статуса
- **TTL / Срок истечения** — автоматическое истечение процесса через `expires_at` + команда `delayed:expire`
- **Отмена** — отмена процессов в статусе `new`/`wait` через builder
- **Конфигурация очереди по сущности** — настройка очереди, соединения и timeout'а для каждого класса сущности
- **Artisan команды** — `delayed:process`, `delayed:clear`, `delayed:unstuck`, `delayed:expire`, `delayed:migrate-v1` (устаревшая миграция)
- **Структурированное логирование** — захватывает все события `MessageLogged` во время выполнения, настраиваемый лимит буфера
- **Атомарный захват** — безопасный для условий гонки захват процесса через атомарный UPDATE
- **Оптимизация для PostgreSQL** — частичные индексы, колонки JSONB, TIMESTAMPTZ; также поддерживается MySQL/MariaDB

---

## Требования

| Зависимость | Версия |
|-------------|--------|
| PHP | ^8.5 |
| Laravel | ^12.0 |
| База данных | PostgreSQL (рекомендуется) или MySQL/MariaDB |

---

## Установка

```bash
composer require dskripchenko/laravel-delayed-process
```

Опубликуйте файл конфигурации:

```bash
php artisan vendor:publish --tag=delayed-process-config
```

Выполните миграцию:

```bash
php artisan migrate
```

Зарегистрируйте разрешённые сущности в `config/delayed-process.php`:

```php
'allowed_entities' => [
    \App\Services\ReportService::class,
    \App\Services\ExportService::class,
],
```

---

## Быстрый старт

### 1. Создайте обработчик

```php
<?php

declare(strict_types=1);

namespace App\Services;

final class ReportService
{
    public function generate(int $userId, string $format): array
    {
        // Долгоживущая операция (30+ секунд)
        $data = $this->buildReport($userId, $format);

        return ['url' => $data['url'], 'rows' => $data['count']];
    }
}
```

### 2. Запустите отложенный процесс (Backend)

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

### 3. Endpoint для проверки статуса

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

### 4. Frontend — Axios перехватчик

```typescript
import axios from 'axios';
import { applyAxiosInterceptor } from './delayed-process';

const api = axios.create({ baseURL: '/api' });

applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 3000,
});

// Использование — опрос полностью автоматический
const response = await api.post('/reports/generate', { user_id: 1, format: 'pdf' });
console.log(response.data.payload); // { url: '...', rows: 150 }
```

---

## Архитектура

### Обзор жизненного цикла

```
Клиент                          Сервер                          Worker очереди
  │                               │                                   │
  ├─── POST /api/reports ────────►│                                   │
  │                               ├── Factory.make()                  │
  │                               │   ├─ Валидация entity+method      │
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
  ▼ Перехватчик возвращает данные │                                   │
```

### Обзор компонентов

| Компонент | Класс | Назначение |
|-----------|-------|-----------|
| **Model** | `DelayedProcess` | Eloquent модель — хранит состояние процесса, результат, логи |
| **Builder** | `DelayedProcessBuilder` | Custom Eloquent builder — `whereNew()`, `whereStuck()`, `claimForExecution()` |
| **Factory** | `DelayedProcessFactory` | Создаёт процесс, валидирует сущность, диспатчит job |
| **Runner** | `DelayedProcessRunner` | Выполняет процесс — захват, резолв, запуск, обработка ошибок |
| **Logger** | `DelayedProcessLogger` | Буферизирует записи логов во время выполнения, сбрасывает в модель |
| **Job** | `DelayedProcessJob` | Laravel queue job — связывает очередь с runner'ом |
| **Resource** | `DelayedProcessResource` | JSON-формат ответа для endpoint'а статуса |
| **Resolver** | `CallableResolver` | Валидирует и резолвит entity+method в callable |
| **EntityConfigResolver** | `EntityConfigResolver` | Резолвит конфиг очереди/соединения/timeout'а для каждой сущности |
| **CallbackDispatcher** | `CallbackDispatcher` | Отправляет webhook POST при терминальном статусе |
| **Progress** | `DelayedProcessProgress` | Обновляет прогресс процесса (0-100%) |

### Контракты

| Интерфейс | Реализация по умолчанию |
|-----------|------------------------|
| `ProcessFactoryInterface` | `DelayedProcessFactory` |
| `ProcessRunnerInterface` | `DelayedProcessRunner` |
| `ProcessLoggerInterface` | `DelayedProcessLogger` |
| `ProcessProgressInterface` | `DelayedProcessProgress` |

Все привязки регистрируются в `DelayedProcessServiceProvider`. Переопределите через сервис-контейнер Laravel для пользовательских реализаций.

### События

| События | Срабатывает когда | Свойства |
|--------|------------|------------|
| `ProcessCreated` | После сохранения процесса в `Factory::make()` | `process` |
| `ProcessStarted` | После захвата и начала выполнения Runner | `process` |
| `ProcessCompleted` | После успешного выполнения | `process` |
| `ProcessFailed` | После исключения при выполнении | `process`, `exception` |

---

## Жизненный цикл процесса

### Переходы между статусами

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

| Статус | Значение | Описание |
|--------|----------|----------|
| **New** | `new` | Создан, ожидает выполнения. Доступен для захвата. |
| **Wait** | `wait` | Захвачен worker'ом, в данный момент выполняется. Блокирует повторный вход. |
| **Done** | `done` | Успешно завершён. Результат сохранён в `data`. Финальный. |
| **Error** | `error` | Все попытки повтора исчерпаны. Детали ошибки в `error_message` / `error_trace`. Финальный. |
| **Expired** | `expired` | TTL истёк до завершения. Помечен командой `delayed:expire`. Финальный. |
| **Cancelled** | `cancelled` | Вручную отменён через Builder. Финальный. |

### Логика повторного выполнения

1. Worker атомарно захватывает процесс: `UPDATE ... SET status='wait', try=try+1 WHERE status='new'`
2. Обработчик выполняется
3. При успехе: `status → done`, результат сохранён в `data`
4. При ошибке:
   - Если `try < attempts`: `status → new` (доступен для повтора)
   - Если `try >= attempts`: `status → error`, детали ошибки сохранены

---

## Структура проекта

```
src/
├── Builders/
│   └── DelayedProcessBuilder.php       # Custom Eloquent builder (whereNew, whereExpired, cancel, claimForExecution)
├── Components/
│   └── Events/
│       └── Dispatcher.php              # Event dispatcher с listen/unlisten по ID
├── Console/
│   └── Commands/
│       ├── DelayedProcessCommand.php       # delayed:process — синхронный queue worker
│       ├── ClearOldDelayedProcessCommand.php # delayed:clear — удаление старых терминальных процессов
│       ├── ExpireProcessesCommand.php      # delayed:expire — пометка истёкших процессов
│       ├── UnstuckProcessesCommand.php     # delayed:unstuck — сброс зависаний
│       └── MigrateFromV1Command.php        # delayed:migrate-v1 — миграция устаревшей схемы
├── Contracts/
│   ├── ProcessFactoryInterface.php     # Контракт factory
│   ├── ProcessRunnerInterface.php      # Контракт runner
│   ├── ProcessLoggerInterface.php      # Контракт logger
│   ├── ProcessProgressInterface.php    # Контракт отслеживания прогресса
│   └── ProcessObserverInterface.php    # Контракт наблюдателя (onCreated, onStarted, и т.д.)
├── Enums/
│   └── ProcessStatus.php               # new | wait | done | error | expired | cancelled
├── Events/
│   ├── ProcessCreated.php              # Срабатывает после создания процесса factory'ом
│   ├── ProcessStarted.php             # Срабатывает после захвата процесса runner'ом
│   ├── ProcessCompleted.php           # Срабатывает после успешного выполнения
│   └── ProcessFailed.php             # Срабатывает при ошибке выполнения
├── Exceptions/
│   ├── CallableResolutionException.php # Класс/метод не найдены
│   ├── EntityNotAllowedException.php   # Сущность не в белом списке
│   └── InvalidParametersException.php  # Non-serializable параметры
├── Jobs/
│   └── DelayedProcessJob.php           # Queue job — запуск процесса через runner
├── Models/
│   └── DelayedProcess.php              # Eloquent модель с UUIDv7, прогрессом, TTL, обратными вызовами
├── Providers/
│   └── DelayedProcessServiceProvider.php # Регистрирует привязки, миграции, команды
├── Resources/
│   └── DelayedProcessResource.php      # JSON resource ответа
└── Services/
    ├── CallableResolver.php            # Валидирует белый список + резолвит callable
    ├── CallbackDispatcher.php          # Отправляет webhook POST при терминальном статусе
    ├── DelayedProcessFactory.php       # Создаёт процесс + диспатчит job + события
    ├── DelayedProcessLogger.php        # Буферизирует логи с настраиваемым лимитом
    ├── DelayedProcessProgress.php      # Отслеживание прогресса (0-100%)
    ├── DelayedProcessRunner.php        # Захватывает + выполняет + события + обратные вызовы
    └── EntityConfigResolver.php        # Резолвит конфиг очереди/соединения/timeout'а по сущности

resources/js/delayed-process/
├── index.ts                            # Public exports
├── types.ts                            # TypeScript типы, типы BatchPoller, DelayedProcessError
├── core/
│   ├── config.ts                       # Config по умолчанию + CSRF auto-detect
│   ├── poller.ts                       # Poll loop с timeout и abort
│   └── batch-poller.ts                # BatchPoller — опрос нескольких UUID за раз
├── axios/
│   └── interceptor.ts                  # Axios response interceptor
├── fetch/
│   └── patch.ts                        # window.fetch monkey-patch
└── xhr/
    └── patch.ts                        # XMLHttpRequest monkey-patch (защита от двойного patch)
```

---

## Backend API

### Создание процессов

Используйте `ProcessFactoryInterface` (резолвится через DI):

```php
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;

$process = $factory->make(
    entity: \App\Services\ExportService::class,
    method: 'exportCsv',
    // Вариативные параметры, передаваемые методу обработчика:
    $userId,
    $filters,
);
```

**Что происходит внутри `make()`:**

1. Валидирует, что сущность находится в конфиге `allowed_entities`
2. Валидирует, что класс и метод существуют
3. Валидирует, что параметры JSON-сериализуемые
4. Создаёт модель `DelayedProcess` в транзакции БД (авто-генерирует UUIDv7, устанавливает `status=new`, вычисляет `expires_at` из конфига TTL)
5. Конфигурирует очередь/соединение/timeout job'а из конфига по сущности
6. Диспатчит `DelayedProcessJob` в очередь
7. Срабатывает событие `ProcessCreated`
8. Возвращает сохранённую модель

#### Создание с Webhook обратным вызовом

```php
$process = $factory->makeWithCallback(
    entity: \App\Services\ExportService::class,
    method: 'exportCsv',
    callbackUrl: 'https://your-app.com/webhooks/process-done',
    $userId,
);
```

Когда процесс достигнет терминального статуса (`done`, `error`, `expired`, `cancelled`), HTTP POST будет отправлен на `callbackUrl` с `{uuid, status, data}`.

#### Конфигурация очереди по сущности

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

### Ответ endpoint'а статуса

`DelayedProcessResource` возвращает:

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

Примечания:
- `data` включается только когда `status` равен терминальному (`done`, `error`, `expired`, `cancelled`)
- `error_message` и `is_error_truncated` включаются только если присутствует ошибка
- `progress` (0-100) отражает прогресс выполнения
- `started_at` и `duration_ms` отслеживают время выполнения

### Artisan команды

#### `delayed:process` — Синхронный обработчик

Обрабатывает отложенные задачи без необходимости в queue worker. Полезна для разработки или развёртывания на одном сервере.

```bash
php artisan delayed:process
php artisan delayed:process --max-iterations=100
php artisan delayed:process --sleep=10
```

| Опция | По умолчанию | Описание |
|-------|--------------|----------|
| `--max-iterations` | `0` (бесконечно) | Остановиться после N процессов. `0` = выполнять вечно. |
| `--sleep` | `5` | Секунды ожидания, когда процессы не найдены. |

#### `delayed:clear` — Очистка старых процессов

Удаляет терминальные (`done` / `error`) процессы старше указанного количества дней.

```bash
php artisan delayed:clear
php artisan delayed:clear --days=7
php artisan delayed:clear --chunk=1000
```

| Опция | По умолчанию | Описание |
|-------|--------------|----------|
| `--days` | `30` | Удалить процессы старше N дней. |
| `--chunk` | `500` | Размер пакета удаления для эффективности памяти. |

#### `delayed:unstuck` — Сброс зависаний

Сбрасывает процессы, зависшие со статусом `wait`, обратно в `new`, чтобы их можно было повторить.

```bash
php artisan delayed:unstuck
php artisan delayed:unstuck --timeout=30
php artisan delayed:unstuck --dry-run
```

| Опция | По умолчанию | Описание |
|-------|--------------|----------|
| `--timeout` | `60` | Считать процессы зависшими после N минут в статусе `wait`. |
| `--dry-run` | `false` | Показать зависшие процессы без сброса. |

#### `delayed:expire` — Истечение процессов с TTL

Помечает процессы, у которых `expires_at` прошло, как `expired`.

```bash
php artisan delayed:expire
php artisan delayed:expire --dry-run
```

| Опция | По умолчанию | Описание |
|-------|--------------|----------|
| `--dry-run` | `false` | Показать счётчик без изменений. |

#### `delayed:migrate-v1` — Миграция устаревшей схемы

Обновляет схему БД из устаревшей структуры. Добавляет колонки `error_message` / `error_trace`, преобразует колонки в JSONB (PostgreSQL) или JSON (MySQL), создаёт частичные/составные индексы и добавляет CHECK ограничение.

```bash
php artisan delayed:migrate-v1
php artisan delayed:migrate-v1 --force
```

---

## Frontend перехватчики

Модуль `resources/js/delayed-process/` предоставляет прозрачные перехватчики, которые автоматически обнаруживают ответы с отложенным процессом и опрашивают до завершения.

### Как это работает

1. Ваш API возвращает ответ, содержащий `{ payload: { delayed: { uuid: "..." } } }`
2. Перехватчик обнаруживает UUID
3. Он начинает опрашивать endpoint статуса: `GET {statusUrl}?uuid={uuid}`
4. Когда `status` становится `done`, перехватчик заменяет полезную нагрузку ответа результирующими данными
5. Когда `status` становится `error`, `expired` или `cancelled`, перехватчик выбрасывает `DelayedProcessError`
6. Запросы опроса включают заголовок `X-Delayed-Process-Poll: 1` для предотвращения бесконечных циклов

### Структура файлов

| Файл | Назначение |
|------|-----------|
| `index.ts` | Public exports |
| `types.ts` | TypeScript типы, `BatchPollerConfig`, `DelayedProcessError` |
| `core/config.ts` | Config по умолчанию, CSRF auto-detect, `resolveConfig()` |
| `core/poller.ts` | `pollUntilDone()` — основной poll loop с timeout и abort |
| `core/batch-poller.ts` | `BatchPoller` — опрос нескольких UUID в одном запросе |
| `axios/interceptor.ts` | `applyAxiosInterceptor()` — Axios response interceptor |
| `fetch/patch.ts` | `patchFetch()` — monkey-patch для `window.fetch` |
| `xhr/patch.ts` | `patchXHR()` — monkey-patch для `XMLHttpRequest` (защита от двойного patch) |

### Axios перехватчик

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

// Для удаления: api.interceptors.response.eject(interceptorId);
```

### Fetch patch

```typescript
import { patchFetch } from './delayed-process';

const unpatch = patchFetch({
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 3000,
});

// Все fetch() вызовы теперь автоматически опрашивают отложенные процессы
const response = await fetch('/api/reports/generate', { method: 'POST' });
const data = await response.json();
console.log(data.payload); // Разрешённый результат, не UUID

// Для восстановления оригинального fetch:
unpatch();
```

### XHR patch

```typescript
import { patchXHR } from './delayed-process';

const unpatch = patchXHR({
    statusUrl: '/api/common/delayed-process/status',
});

const xhr = new XMLHttpRequest();
xhr.open('POST', '/api/reports/generate');
xhr.onload = function () {
    const data = JSON.parse(this.responseText);
    console.log(data.payload); // Разрешённый результат
};
xhr.send();

// Для восстановления оригинального XHR:
unpatch();
```

### DelayedProcessConfig

| Опция | Тип | По умолчанию | Описание |
|-------|-----|--------------|----------|
| `statusUrl` | `string` | `'/api/common/delayed-process/status'` | URL для опроса статуса процесса |
| `pollingInterval` | `number` | `3000` | Миллисекунды между запросами опроса |
| `maxAttempts` | `number` | `100` | Максимальное количество попыток опроса |
| `timeout` | `number` | `300000` | Общий timeout в миллисекундах (5 мин) |
| `headers` | `Record<string, string>` | `{}` | Дополнительные заголовки для запросов опроса |
| `onPoll` | `(uuid: string, attempt: number) => void` | `undefined` | Callback, вызываемый при каждом опросе |

CSRF token из `<meta name="csrf-token">` автоматически включается в запросы опроса.

### Групповой опрос

Для опроса нескольких процессов одновременно (например, при массовых операциях):

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

Выбрасывается, когда процесс завершается со статусом `error`, `expired` или `cancelled`, либо истекает timeout опроса.

```typescript
import { DelayedProcessError } from './delayed-process';

try {
    const response = await api.post('/api/reports/generate');
} catch (error) {
    if (error instanceof DelayedProcessError) {
        console.error(error.uuid);         // UUID процесса
        console.error(error.status);       // 'error' | 'expired' | 'cancelled'
        console.error(error.errorMessage); // Сообщение об ошибке со стороны сервера
    }
}
```

### Предотвращение циклов

Все запросы опроса включают заголовок `X-Delayed-Process-Poll: 1`. Перехватчики проверяют этот заголовок и пропускают перехват в запросах опроса, предотвращая циклы бесконечного опроса.

---

## Справочник конфигурации

Файл: `config/delayed-process.php`

| Ключ | Тип | По умолчанию | Описание |
|-----|-----|--------------|----------|
| `allowed_entities` | `array` | `[]` | Список FQCN классов, разрешённых в качестве обработчиков процессов; значения строк или массивы с ключом `Entity::class => [config]` |
| `default_attempts` | `int` | `5` | Максимальное количество попыток повтора перед пометкой как `error` |
| `clear_after_days` | `int` | `30` | `delayed:clear` удаляет терминальные процессы старше этого количества дней |
| `stuck_timeout_minutes` | `int` | `60` | `delayed:unstuck` считает процессы `wait` зависшими после этого количества минут |
| `log_sensitive_context` | `bool` | `false` | Включать массивы контекста логов в логи процесса |
| `log_buffer_limit` | `int` | `500` | Макс. записей логов в памяти буфера процесса (0 = без ограничений) |
| `callback.enabled` | `bool` | `false` | Включить webhook POST при терминальном статусе |
| `callback.timeout` | `int` | `10` | Webhook HTTP timeout в секундах |
| `default_ttl_minutes` | `int\|null` | `null` | TTL по умолчанию для новых процессов (`null` = без истечения) |
| `job.timeout` | `int` | `300` | Queue job timeout в секундах |
| `job.tries` | `int` | `1` | Попытки повтора queue job (отдельно от попыток процесса) |
| `job.backoff` | `array` | `[30, 60, 120]` | Queue job backoff задержки в секундах |
| `command.sleep` | `int` | `5` | `delayed:process` ожидание при неактивности (секунды) |
| `command.max_iterations` | `int` | `0` | Лимит итераций `delayed:process` (`0` = бесконечно) |
| `command.throttle` | `int` | `100000` | `delayed:process` throttle между итерациями (микросекунды) |
| `clear_chunk_size` | `int` | `500` | `delayed:clear` размер пакета удаления |

---

## Схема базы данных

### Таблица: `delayed_processes`

| Колонка | Тип | По умолчанию | Описание |
|---------|-----|--------------|----------|
| `id` | `bigint` PK | auto-increment | Primary key |
| `uuid` | `string(36)` UNIQUE | auto (UUIDv7) | Уникальный идентификатор процесса |
| `entity` | `string` nullable | `NULL` | FQCN класса обработчика |
| `method` | `string` | — | Имя метода обработчика |
| `parameters` | `jsonb` / `json` | `[]` | Сериализованные аргументы вызова |
| `data` | `jsonb` / `json` | `[]` | Полезная нагрузка результата выполнения |
| `logs` | `jsonb` / `json` | `[]` | Захваченные записи логов |
| `status` | `string` | `'new'` | Статус процесса (`new`, `wait`, `done`, `error`, `expired`, `cancelled`) |
| `attempts` | `tinyint unsigned` | `5` | Максимальное количество попыток повтора |
| `try` | `tinyint unsigned` | `0` | Номер текущей попытки |
| `error_message` | `string(1000)` nullable | `NULL` | Последнее сообщение об ошибке (усечено с индикатором) |
| `error_trace` | `text` nullable | `NULL` | Последний stack trace ошибки |
| `started_at` | `timestamptz` nullable | `NULL` | Время начала выполнения |
| `duration_ms` | `bigint unsigned` nullable | `NULL` | Длительность выполнения в миллисекундах |
| `callback_url` | `string(2048)` nullable | `NULL` | Webhook URL для уведомления о терминальном статусе |
| `progress` | `tinyint unsigned` | `0` | Прогресс выполнения (0-100) |
| `expires_at` | `timestamptz` nullable | `NULL` | Время истечения процесса (TTL) |
| `created_at` | `timestamptz` | NOW | Timestamp создания |
| `updated_at` | `timestamptz` | NOW | Timestamp последнего обновления |

### Индексы

**PostgreSQL** (частичные индексы для оптимальной производительности):

| Индекс | Условие |
|--------|---------|
| `(status, try)` | `WHERE status = 'new'` |
| `(created_at)` | `WHERE status IN ('done', 'error', 'expired', 'cancelled')` |
| `(updated_at)` | `WHERE status = 'wait'` |
| `(expires_at)` | `WHERE status IN ('new', 'wait') AND expires_at IS NOT NULL` |

**MySQL / MariaDB** (составные индексы):

| Индекс |
|--------|
| `(status, try)` |
| `(status, created_at)` |
| `(status, updated_at)` |

### Ограничения

- `CHECK (status IN ('new', 'wait', 'done', 'error', 'expired', 'cancelled'))` на всех БД

---

## Безопасность

### Белый список сущностей

Только классы, перечисленные в `config('delayed-process.allowed_entities')`, могут быть выполнены. Попытка создать процесс с неразрешённым классом выбрасывает `EntityNotAllowedException`.

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\ReportService::class,
    \App\Services\ExportService::class,
    // Только эти классы могут использоваться в качестве обработчиков
],
```

### Валидация Callable

Перед выполнением `CallableResolver` проверяет:
1. Класс сущности находится в белом списке
2. Класс существует (`class_exists()`)
3. Метод существует (`method_exists()`)

Инстанцирование использует `app($entity)` — полная поддержка Laravel DI контейнера.

### Конфиденциальность логов

Установите `log_sensitive_context` в `false` (по умолчанию), чтобы удалить массивы контекста из захваченных записей логов. Хранятся только уровень логирования, timestamp и сообщение.

### CSRF защита

Frontend poller автоматически читает `<meta name="csrf-token">` и включает его в заголовки запроса опроса. Убедитесь, что ваш endpoint статуса защищён CSRF middleware или явно проверьте token.

---

## Книга рецептов

Для рецептов, паттернов и решения проблем смотрите **[Книга рецептов](cookbook.ru.md)**.

Доступна на: [English](cookbook.md) | [Русский](cookbook.ru.md) | [Deutsch](cookbook.de.md) | [中文](cookbook.zh.md)

---

## Руководство по интеграции с фронтендом

Подробное пошаговое руководство по интеграции перехватчиков в приложения на **Vue.js 3** и **React** смотрите в **[Руководстве по Frontend перехватчикам](frontend-interceptors-guide.ru.md)**.

Включает: composables/хуки, отслеживание прогресса, групповой опрос, обработку ошибок, поддержку SSR и тестирование.

---

## Лицензия

[MIT](../LICENSE.md) &copy; Denis Skripchenko
