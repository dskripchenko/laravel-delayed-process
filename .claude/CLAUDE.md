# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Проект

Laravel-пакет `dskripchenko/laravel-delayed-process` — отложенное выполнение долгих операций через очереди Laravel. Клиент создаёт процесс, получает UUID, затем поллит статус до завершения.

**Пакет:** `dskripchenko/laravel-delayed-process`
**Совместимость:** Laravel 6–12
**Зависимости:** `dskripchenko/php-array-helper` ^1.1
**Лицензия:** MIT

## Структура

```
src/
├── Models/DelayedProcess.php              # Eloquent-модель процесса (pipeline: NEW → WAIT → DONE|ERROR)
├── Jobs/DelayedProcessJob.php             # Queue job — запуск процесса через очередь
├── Providers/DelayedProcessServiceProvider.php  # ServiceProvider (миграции + artisan-команды)
├── Resources/DelayedProcess.php           # JsonResource — ответ с UUID
├── Console/Commands/
│   ├── DelayedProcessCommand.php          # `delayed:process` — синхронный обработчик очереди процессов
│   └── ClearOldDelayedProcessCommand.php  # `delayed:clear` — удаление завершённых процессов старше 30 дней
└── Components/Events/Dispatcher.php       # Кастомный Event Dispatcher с listen/unlisten по ID

databases/migrations/
└── 2022_12_01_174014_create_delayed_processes_table.php  # Таблица delayed_processes
```

## Архитектура

### Жизненный цикл процесса

1. `DelayedProcess::make($entity, $method, ...$params)` — создаёт запись в БД (status=`new`), диспатчит `DelayedProcessJob`
2. Job вызывает `$process->run()` — статус `new` → `wait`, выполняет callable, статус → `done` или retry/`error`
3. Клиент поллит `/api/.../status/{uuid}` — получает `status` + `data`

### Статусы (pipeline)

`new` → `wait` → `done` | `error`

- **new** — создан, ожидает обработки
- **wait** — выполняется (повторный запуск заблокирован)
- **done** — успешно завершён, результат в `data`
- **error** — все попытки исчерпаны (`try >= attempts`, по умолчанию 5)

### Модель DelayedProcess

Ключевые поля: `uuid` (уникальный идентификатор), `entity` (класс-обработчик), `method` (метод), `parameters` (аргументы, cast array), `data` (результат, cast array), `logs` (логи выполнения, cast array), `status`, `attempts` (макс. попыток), `try` (текущая попытка).

Callable резолвится через `app($entity)` — поддерживает DI-контейнер Laravel.

### Логирование

`DelayedProcessJob` подменяет Event Dispatcher на кастомный `Dispatcher` с `listen()`/`unlisten()` по ID. Все `MessageLogged`-события во время выполнения процесса записываются в поле `logs` модели через `$process->log()`.

## Artisan-команды

```bash
php artisan delayed:process    # Обработать все процессы со статусом NEW (синхронно, в цикле)
php artisan delayed:clear      # Удалить завершённые процессы старше 30 дней
```

## Таблица БД: delayed_processes

| Поле | Тип | Описание |
|------|-----|----------|
| id | bigint PK | Auto-increment |
| uuid | string, unique, indexed | UUID процесса (генерируется при создании) |
| entity | string, nullable | FQCN класса-обработчика |
| method | string | Метод обработчика |
| parameters | text (cast array) | Аргументы вызова |
| data | text (cast array) | Результат выполнения |
| logs | longText (cast array) | Логи выполнения |
| status | string, indexed, default 'new' | Статус процесса |
| attempts | tinyint unsigned, default 5 | Максимум попыток |
| try | tinyint unsigned, default 0 | Текущая попытка |
| created_at | timestamp | — |
| updated_at | timestamp | — |
