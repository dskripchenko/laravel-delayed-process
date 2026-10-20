# Гайд по обновлению

Доступно на: [English](../en/upgrade.md) | [Русский](upgrade.md) | [Deutsch](../de/upgrade.md) | [中文](../zh/upgrade.md)

Каждый раздел описывает, что изменилось между двумя версиями и что с этим
делать; новые версии сверху. При переходе через несколько версий применяйте
все разделы между ними, снизу вверх.

- [2.1 → 2.2](#21--22)
- [2.0 → 2.1](#20--21)
- [1.x → 2.x](#1x--2x)

---

## 2.1 → 2.2

Схема БД и конфигурация не меняются.

**Именованные параметры передаются по имени.** До 2.2 набор параметров со
строковыми ключами всегда передавался обработчику одним массивом. Теперь,
если каждый ключ совпадает с именем параметра метода-обработчика, ключи
связываются по имени:

```php
$factory->make(ReportService::class, 'build', ...['from' => $from, 'to' => $to]);

// 2.1: build(['from' => ..., 'to' => ...])
// 2.2: build(from: ..., to: ...)
```

Обработчик вида `handle(array $params)` по-прежнему получает весь массив,
если только ключи случайно не совпадут с именами его параметров: набор
`['params' => ...]` для `handle(array $params)` теперь связывается по имени.
Список (list) передаётся позиционно, как и раньше.

**2.1.2: конструктор `DelayedProcessRunner`.** Четвёртый аргумент теперь
необязательный `ProcessProgressInterface` вместо `DelayedProcessProgress`.
Это важно, только если вы создаёте runner сами; при получении из контейнера
ничего менять не нужно.

---

## 2.0 → 2.1

В коде ничего менять не нужно.

2.0.0 требовала PHP ^8.5 и Laravel 12. С 2.1.0 пакет поддерживает PHP ^8.2
с Laravel 11, 12 и 13.

---

## 1.x → 2.x

2.0 — переписанный пакет. Имя таблицы, класс джобы и artisan-команды
сохранили свои имена, но почти всё вокруг изменилось: поведение ушло из
модели в сервисы, статусы стали enum, запускаться могут только классы из
белого списка, а в таблице появились новые колонки, JSON-типы и индексы.

### Требования

| | 1.x | 2.x |
|---|---|---|
| PHP | любой, который допускает версия Laravel | ^8.2 (2.0.0: ^8.5) |
| Laravel | 6 – 12 | 11, 12, 13 (2.0.0: только 12) |
| `dskripchenko/php-array-helper` | обязателен | не нужен |

Пакет больше не тянет `dskripchenko/php-array-helper`. Если ваш код вызывает
`array_is_associative()`, `array_merge_deep()` или другие хелперы оттуда,
подключите пакет напрямую:

```bash
composer require dskripchenko/php-array-helper
```

### Шаг 1. Дождитесь опустошения очереди и остановите воркеры

Джобы, поставленные в очередь версией 1.x, попадут в runner, который
проверяет белый список (шаг 3) и пишет в колонки, появляющиеся только на
шаге 4. Перед выкладкой:

1. Перестаньте создавать новые процессы или включите режим обслуживания.
2. Дайте воркерам очереди и `delayed:process` завершить начатое.
3. Остановите воркеры и планировщик.

Процессы, оставшиеся в `new`, будут подхвачены после обновления. Процессы,
застрявшие в `wait`, сбросьте командой `php artisan delayed:unstuck` после
обновления.

### Шаг 2. Обновите пакет

```bash
composer require dskripchenko/laravel-delayed-process:^2.2
```

### Шаг 3. Опубликуйте конфигурацию и заполните белый список

```bash
php artisan vendor:publish --tag=delayed-process-config
```

**Этот шаг обязателен.** В 2.x запускаться могут только классы из
`allowed_entities`. По умолчанию список пуст, и без него каждый `make()`
бросает `EntityNotAllowedException`.

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\ReportService::class,
    // настройки очереди для конкретного класса
    \App\Services\HeavyExport::class => ['queue' => 'heavy', 'timeout' => 600],
],
```

Чтобы найти используемые классы, поищите `DelayedProcess::make(` в коде и
посмотрите колонку `entity` у существующих строк:

```sql
SELECT DISTINCT entity, method FROM delayed_processes;
```

Обработчик должен быть классом с публичным методом. 1.x принимала также
пустой entity с именем глобальной функции или класс без метода (invokable).
2.x не принимает ни то, ни другое: оберните такой код в метод класса.

Остальные настройки (число попыток, срок хранения, таймаут зависания,
таймаут джобы и т. д.) описаны в [справочнике по конфигурации](README.md#справочник-конфигурации).

### Шаг 4. Обновите таблицу

**Порядок важен: сначала `delayed:migrate-v1`, потом `migrate`.**

```bash
php artisan delayed:migrate-v1 --force
php artisan migrate --force
```

`delayed:migrate-v1` приводит таблицу 1.x к схеме 2.0:

- добавляет `error_message` и `error_trace`;
- добавляет CHECK-ограничение на `status` (PostgreSQL, MySQL 8.0.16+, MariaDB);
- PostgreSQL: переводит `parameters`, `data`, `logs` в `jsonb`, а
  `created_at`, `updated_at` в `timestamptz`, создаёт частичные индексы;
- MySQL/MariaDB: переводит три колонки в `json`, создаёт составные индексы;
- SQLite: только добавляет две колонки.

Затем `migrate` выполняет миграцию пакета
`2026_03_11_000001_add_extended_columns_to_delayed_processes_table`: она
добавляет `started_at`, `duration_ms`, `callback_url`, `progress`,
`expires_at` и расширяет CHECK-ограничение статусами `expired` и `cancelled`.

В обратном порядке обновление ломается:

- **MySQL/MariaDB:** миграция ставит свои колонки после `error_trace`,
  которой ещё нет, и падает с «Unknown column».
- **PostgreSQL:** миграция проходит, затем `delayed:migrate-v1` добавляет две
  колонки и падает на CHECK-ограничении, которое миграция уже создала. При
  повторном запуске команда отвечает «Already migrated», потому что
  `error_message` уже есть, и перевод в `jsonb` и индексы так и не применяются.

Если деплой сам запускает `migrate`, вставьте `delayed:migrate-v1` перед
этим шагом.

Что проверить перед запуском на боевых данных:

- **Часовой пояс (PostgreSQL).** Время переводится как UTC
  (`AT TIME ZONE 'UTC'`). Если приложение хранило в `created_at`/`updated_at`
  местное время, значения сдвинутся на смещение пояса.
- **Невалидный JSON.** Перевод колонок падает на строке, где `parameters`,
  `data` или `logs` — не валидный JSON. 1.x всегда писала туда JSON, но строки,
  правленные руками, стоит проверить заранее.
- **Статусы.** CHECK-ограничение принимает `new`, `wait`, `done`, `error`.
  Строки с другим статусом нужно исправить до обновления.
- **Резервная копия.** В MySQL изменения схемы не транзакционны: сбой на
  середине оставит таблицу обновлённой частично. Сделайте копию таблицы.

Миграция создания таблицы в 1.x и 2.x называется одинаково, поэтому Laravel
считает её выполненной и таблицу заново не создаёт.

### Шаг 5. Обновите код

#### Создание процесса

`DelayedProcess::make()` больше нет. Процессы создаются через
`ProcessFactoryInterface`, аргументы те же:

```php
// 1.x
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

$process = DelayedProcess::make(ReportService::class, 'build', $from, $to);

// 2.x
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;

$process = app(ProcessFactoryInterface::class)->make(ReportService::class, 'build', $from, $to);
```

Где можно, внедряйте `ProcessFactoryInterface` через конструктор или метод
контроллера вместо `app()`. Фабрика проверяет белый список и наличие метода
до сохранения, поэтому опечатка теперь падает при создании, а не в воркере.

`DelayedProcessFactory::makeWithCallback($entity, $method, $url, ...$params)`
вдобавок отправляет вебхук по завершении процесса, см. README.

#### Удалённые методы модели

| 1.x | 2.x |
|---|---|
| `DelayedProcess::make($entity, $method, ...$params)` | `ProcessFactoryInterface::make()` |
| `$process->run()` | `app(ProcessRunnerInterface::class)->run($process)` |
| `$process->log($level, $message, $context)` | пишите через `Log::...()` внутри обработчика, джоба соберёт строки |
| `$process->toResponse()` | см. [Ответы](#ответы) |
| `DelayedProcess::STATUS_NEW` … `STATUS_ERROR` | `ProcessStatus::New` … `ProcessStatus::Error` |
| `DelayedProcess::DEFAULT_ATTEMPTS` | `config('delayed-process.default_attempts')` |

#### Статус — enum

`$process->status` теперь enum `ProcessStatus`, а не строка. Код, который
сравнивает или выводит его как строку, перестаёт работать:

```php
// 1.x
if ($process->status === DelayedProcess::STATUS_ERROR) { ... }
strtoupper($process->status);

// 2.x
use Dskripchenko\DelayedProcess\Enums\ProcessStatus;

if ($process->status === ProcessStatus::Error) { ... }
strtoupper($process->status->value);
```

Запросы к БД могут по-прежнему использовать строковые значения
(`where('status', 'new')`) или скоупы билдера: `whereNew()`, `whereTerminal()`,
`whereStuck()`, `whereExpired()`, `whereUuids()`, `olderThanDays()`.

Добавились два статуса, `expired` и `cancelled`. Оба конечные: код, который
считает «не `done` и не `error`» признаком «ещё выполняется», должен
использовать `$process->status->isTerminal()`.

#### Ответы

`Resources\DelayedProcess` заменён на `Resources\DelayedProcessResource` с
другой структурой. `toResponse()` в 1.x возвращал:

```json
{ "delayed": { "uuid": "..." } }
```

Чтобы сохранить такой ответ у эндпоинта, который запускает процесс, соберите
его сами. Фронтенд-перехватчики пакета ищут именно `delayed.uuid`:

```php
return response()->json([
    'delayed' => ['uuid' => $process->uuid, 'status' => $process->status->value],
]);
```

`DelayedProcessResource` предназначен для эндпоинта статуса и возвращает
`uuid`, `status`, `data` (только для конечных статусов), `error_message`,
`progress`, `started_at`, `duration_ms`, `attempts`, `current_try`,
`created_at`, `updated_at`.

#### Наследование модели

Наследник `DelayedProcess` продолжает работать в запросах и админ-экранах, с
такими отличиями:

- фабрика создаёт базовый `DelayedProcess`, а не ваш класс, поэтому события
  модели и аксессоры наследника при создании не срабатывают;
- касты объявлены в `casts()`. Свойство `$casts` в наследнике сливается с
  ними и не заменяет каст `status` в enum;
- `$fillable` теперь `entity`, `method`, `parameters`, `callback_url`.
  `uuid`, `status`, `data`, `logs`, `attempts`, `try` больше нельзя
  заполнять массово: `create([... 'status' => ...])` молча их отбросит.

### Изменения поведения

Код менять не нужно, но наблюдаемое поведение может измениться.

| | 1.x | 2.x |
|---|---|---|
| Перехват ошибок | только `Exception`; `Error` (`TypeError` и т. п.) оставлял процесс в `wait` | любой `Throwable`; сообщение и трейс пишутся в `error_message`/`error_trace` |
| Повторный запуск завершённого | `run()` на `done`/`error` запускал процесс снова | завершённые процессы пропускаются |
| Два воркера, один процесс | могли выполнить оба | процесс захватывается атомарным `UPDATE` |
| `uuid` | UUID v4 | UUID v7 (старые значения остаются валидными) |
| Записи лога | с ключом по времени, `Level`/`Date`/`Message`/`Context` | список `level`/`date`/`message`, пишется по окончании запуска |
| Контекст лога | сохранялся всегда | отбрасывается, если `log_sensitive_context` не `true` |
| `created_at` в JSON | `Y-m-d H:i:s` | ISO 8601 (сериализация модели по умолчанию) |
| Повторы джобы | `--tries` воркера | `job.tries` в конфиге, по умолчанию 1 |

Строки, записанные 1.x, хранят записи лога в старом формате. Код, читающий
`logs`, должен понимать оба.

#### `delayed:process` больше не завершается сам

В 1.x `delayed:process` обрабатывал все процессы в `new` и завершался. В 2.x
он работает, пока его не остановят, и при пустой очереди спит
`command.sleep` секунд.

Если планировщик запускает его каждую минуту, каждый запуск теперь остаётся
жить, и копии копятся. Выберите одно:

```php
// оставить в планировщике: выходить, когда обрабатывать нечего
$schedule->command('delayed:process --max-iterations=1000')
    ->everyMinute()
    ->withoutOverlapping();
```

или запускайте `php artisan delayed:process` как долгоживущий процесс под
Supervisor или systemd и уберите его из планировщика.

#### Новые команды

Добавьте нужные в планировщик:

```php
$schedule->command('delayed:clear')->daily();      // как в 1.x
$schedule->command('delayed:unstuck')->everyTenMinutes();
$schedule->command('delayed:expire')->everyMinute(); // только при default_ttl_minutes
```

`delayed:clear` теперь принимает `--days` и `--chunk` и удаляет порциями;
срок по умолчанию — `clear_after_days` (30, как в 1.x).

### Чеклист

- [ ] очередь пуста, воркеры остановлены
- [ ] `composer require dskripchenko/laravel-delayed-process:^2.2`
- [ ] `dskripchenko/php-array-helper` подключён напрямую, если его хелперы используются
- [ ] конфиг опубликован, все классы-обработчики в `allowed_entities`
- [ ] сделана копия таблицы
- [ ] `delayed:migrate-v1 --force`, **потом** `migrate --force`
- [ ] `DelayedProcess::make()` → `ProcessFactoryInterface::make()`
- [ ] константы `STATUS_*` и сравнения со строкой → `ProcessStatus`
- [ ] `toResponse()` → явный ответ с `delayed.uuid`
- [ ] код, читающий `logs`, понимает оба формата
- [ ] `delayed:process` в планировщике с `--max-iterations` или запущен демоном
- [ ] `delayed:unstuck` запущен один раз после выкладки и добавлен в планировщик
