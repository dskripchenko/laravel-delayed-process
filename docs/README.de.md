# Laravel Delayed Process

[![Packagist Version](https://img.shields.io/packagist/v/dskripchenko/laravel-delayed-process)](https://packagist.org/packages/dskripchenko/laravel-delayed-process)
[![License](https://img.shields.io/packagist/l/dskripchenko/laravel-delayed-process)](../LICENSE.md)
[![PHP Version](https://img.shields.io/packagist/dependency-v/dskripchenko/laravel-delayed-process/php)](../composer.json)
[![Laravel Version](https://img.shields.io/packagist/dependency-v/dskripchenko/laravel-delayed-process/laravel/framework)](../composer.json)

**Sprache:** [English](../README.md) | [Русский](README.ru.md) | [Deutsch](README.de.md) | [中文](README.zh.md)

Asynchrone Ausführung langwieriger Operationen in Laravel mit UUID-basiertem Tracking, automatischen Wiederholungsversuchen, Sicherheits-Allowlist und transparenten Frontend-Interceptoren für Axios, Fetch und XHR.

---

## Inhaltsverzeichnis

- [Funktionen](#funktionen)
- [Anforderungen](#anforderungen)
- [Installation](#installation)
- [Schnelleinstieg](#schnelleinstieg)
- [Architektur](#architektur)
- [Prozesslebenszyklus](#prozesslebenszyklus)
- [Projektstruktur](#projektstruktur)
- [Backend-API](#backend-api)
- [Frontend-Interceptoren](#frontend-interceptoren)
- [Konfigurationsreferenz](#konfigurationsreferenz)
- [Datenbankschema](#datenbankschema)
- [Sicherheit](#sicherheit)
- [Kochbuch](#kochbuch)
- [Lizenz](#lizenz)

---

## Funktionen

- **Asynchrone Verarbeitung** — lagern Sie schwere Operationen in eine Queue aus, geben Sie sofort eine UUID zurück
- **UUID-Tracking** — jeder Prozess erhält eine UUIDv7 zur Statusabfrage
- **Automatische Wiederholung** — konfigurierbare maximale Versuche mit Fehlererfassung beim finalen Fehlschlag
- **Sicherheits-Allowlist** — nur explizit zugelassene Entity-Klassen können ausgeführt werden
- **Frontend-Interceptoren** — transparente Axios-, Fetch- und XHR-Interceptoren, die automatisch bis zur Fertigstellung abfragen
- **Batch-Abfrage** — `BatchPoller`-Klasse zur Abfrage mehrerer UUIDs in einer einzigen Anfrage
- **Schleifenprävention** — `X-Delayed-Process-Poll` Header verhindert, dass Interceptoren Abfrageanfragen erneut abfangen
- **Lebenszyklusereignisse** — `ProcessCreated`, `ProcessStarted`, `ProcessCompleted`, `ProcessFailed` Events für Beobachtbarkeit
- **Fortschrittsanzeige** — 0-100% Fortschrittsaktualisierungen via `ProcessProgressInterface`
- **Webhook-Callbacks** — HTTP POST-Benachrichtigungen an `callback_url` beim Erreichen des Endzustands
- **TTL / Ablauf** — automatisches Ablaufen von Prozessen via `expires_at` + `delayed:expire` Befehl
- **Stornierung** — Stornierung von Prozessen im Status `new`/`wait` via Builder
- **Entity-Konfiguration pro Entity** — Konfigurieren Sie Queue, Connection und Timeout pro Entity-Klasse
- **Artisan-Befehle** — `delayed:process`, `delayed:clear`, `delayed:unstuck`, `delayed:expire`, `delayed:migrate-v1` (Legacy-Migration)
- **Strukturiertes Logging** — erfasst alle `MessageLogged` Events während der Ausführung, konfigurierbares Buffer-Limit
- **Atomare Beanspruchung** — race-condition-sichere Prozessbeanspruchung via atomare UPDATE
- **PostgreSQL-optimiert** — Partial Indexes, JSONB-Spalten, TIMESTAMPTZ; MySQL/MariaDB auch unterstützt

---

## Anforderungen

| Abhängigkeit | Version |
|------------|---------|
| PHP | ^8.5 |
| Laravel | ^12.0 |
| Datenbank | PostgreSQL (empfohlen) oder MySQL/MariaDB |

---

## Installation

```bash
composer require dskripchenko/laravel-delayed-process
```

Veröffentlichen Sie die Konfigurationsdatei:

```bash
php artisan vendor:publish --tag=delayed-process-config
```

Führen Sie die Migration aus:

```bash
php artisan migrate
```

Registrieren Sie zugelassene Entities in `config/delayed-process.php`:

```php
'allowed_entities' => [
    \App\Services\ReportService::class,
    \App\Services\ExportService::class,
],
```

---

## Schnelleinstieg

### 1. Handler erstellen

```php
<?php

declare(strict_types=1);

namespace App\Services;

final class ReportService
{
    public function generate(int $userId, string $format): array
    {
        // Langwierige Operation (30+ Sekunden)
        $data = $this->buildReport($userId, $format);

        return ['url' => $data['url'], 'rows' => $data['count']];
    }
}
```

### 2. Verzögerten Prozess auslösen (Backend)

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

### 3. Status-Endpoint

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

### 4. Frontend — Axios-Interceptor

```typescript
import axios from 'axios';
import { applyAxiosInterceptor } from './delayed-process';

const api = axios.create({ baseURL: '/api' });

applyAxiosInterceptor(api, {
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 3000,
});

// Verwendung — Polling ist vollständig automatisch
const response = await api.post('/reports/generate', { user_id: 1, format: 'pdf' });
console.log(response.data.payload); // { url: '...', rows: 150 }
```

---

## Architektur

### Lebenszyklusübersicht

```
Client                           Server                           Queue Worker
  │                               │                                  │
  ├─── POST /api/reports ────────►│                                  │
  │                               ├── Factory.make()                 │
  │                               │   ├─ Entity+Methode validieren   │
  │                               │   ├─ INSERT (status=new)         │
  │                               │   └─ Job dispatchen ────────────►│
  │◄── { delayed: { uuid } } ─────┤                                  │
  │                               │                                  ├── Claim (status=wait)
  │                               │                                  ├── Callable auflösen
  │                               │                                  ├── Handler ausführen
  │                               │                                  ├── Ergebnis speichern (status=done)
  │─── GET /status?uuid=... ─────►│                                  │
  │◄── { status: "wait" } ────────┤                                  │
  │                               │                                  │
  │─── GET /status?uuid=... ─────►│                                  │
  │◄── { status: "done", data } ──┤                                  │
  │                               │                                  │
  ▼ Interceptor gibt Daten zurück │                                  │
```

### Komponenten-Übersicht

| Komponente | Klasse | Zweck |
|-----------|--------|---------|
| **Model** | `DelayedProcess` | Eloquent-Modell — speichert Prozessstatus, Ergebnis, Logs |
| **Builder** | `DelayedProcessBuilder` | Benutzerdefinierter Eloquent Builder — `whereNew()`, `whereStuck()`, `claimForExecution()` |
| **Factory** | `DelayedProcessFactory` | Erstellt Prozess, validiert Entity, dispatcht Job |
| **Runner** | `DelayedProcessRunner` | Führt Prozess aus — Claim, Auflösung, Lauf, Fehlerbehandlung |
| **Logger** | `DelayedProcessLogger` | Puffert Log-Einträge während Ausführung, spült in Modell |
| **Job** | `DelayedProcessJob` | Laravel-Queue-Job — verbindet Queue zu Runner |
| **Resource** | `DelayedProcessResource` | JSON-Antwortformat für Status-Endpoint |
| **Resolver** | `CallableResolver` | Validiert und löst Entity+Methode zu Callable auf |
| **EntityConfigResolver** | `EntityConfigResolver` | Löst Queue/Connection/Timeout-Konfiguration pro Entity auf |
| **CallbackDispatcher** | `CallbackDispatcher` | Sendet Webhook POST bei Endzustand |
| **Progress** | `DelayedProcessProgress` | Aktualisiert Prozessfortschritt (0-100%) |

### Verträge

| Schnittstelle | Standardimplementierung |
|-----------|----------------------|
| `ProcessFactoryInterface` | `DelayedProcessFactory` |
| `ProcessRunnerInterface` | `DelayedProcessRunner` |
| `ProcessLoggerInterface` | `DelayedProcessLogger` |
| `ProcessProgressInterface` | `DelayedProcessProgress` |

Alle Bindungen werden in `DelayedProcessServiceProvider` registriert. Überschreiben Sie diese über Laravels Service Container für benutzerdefinierte Implementierungen.

### Ereignisse

| Ereignis | Wird ausgelöst wenn | Eigenschaften |
|-------|------------|------------|
| `ProcessCreated` | Nach `Factory::make()` speichert Prozess | `process` |
| `ProcessStarted` | Nach Runner beansprucht und startet Ausführung | `process` |
| `ProcessCompleted` | Nach erfolgreicher Ausführung | `process` |
| `ProcessFailed` | Nach Ausnahme bei Ausführung | `process`, `exception` |

---

## Prozesslebenszyklus

### Statusübergänge

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
   │                    ┌───────┐
   └──────┐             │ ERROR │
          ▼             └───────┘
     ┌─────────┐
     │ EXPIRED │
     └─────────┘
```

| Status | Wert | Beschreibung |
|--------|------|-------------|
| **Neu** | `new` | Erstellt, wartet auf Ausführung. Für Beanspruchung berechtigt. |
| **Warten** | `wait` | Von einem Worker beansprucht, wird gerade ausgeführt. Verhindert Wiedereintritt. |
| **Fertig** | `done` | Erfolgreich abgeschlossen. Ergebnis in `data` gespeichert. Terminal. |
| **Fehler** | `error` | Alle Wiederholungsversuche erschöpft. Fehlerdetails in `error_message` / `error_trace`. Terminal. |
| **Abgelaufen** | `expired` | TTL vor Abschluss überschritten. Mit `delayed:expire` gekennzeichnet. Terminal. |
| **Storniert** | `cancelled` | Manuell über Builder storniert. Terminal. |

### Wiederholungslogik

1. Worker beansprucht Prozess atomar: `UPDATE ... SET status='wait', try=try+1 WHERE status='new'`
2. Handler wird ausgeführt
3. Bei Erfolg: `status → done`, Ergebnis in `data` gespeichert
4. Bei Fehler:
   - Wenn `try < attempts`: `status → new` (berechtigt zur Wiederholung)
   - Wenn `try >= attempts`: `status → error`, Fehlerdetails gespeichert

---

## Projektstruktur

```
src/
├── Builders/
│   └── DelayedProcessBuilder.php       # Benutzerdefinierter Eloquent Builder (whereNew, whereExpired, cancel, claimForExecution)
├── Components/
│   └── Events/
│       └── Dispatcher.php              # Event Dispatcher mit listen/unlisten nach ID
├── Console/
│   └── Commands/
│       ├── DelayedProcessCommand.php       # delayed:process — synchroner Queue Worker
│       ├── ClearOldDelayedProcessCommand.php # delayed:clear — alte Terminal-Prozesse löschen
│       ├── ExpireProcessesCommand.php      # delayed:expire — abgelaufene Prozesse markieren
│       ├── UnstuckProcessesCommand.php     # delayed:unstuck — festgefahrene Prozesse zurücksetzen
│       └── MigrateFromV1Command.php        # delayed:migrate-v1 — Legacy-Schema-Migration
├── Contracts/
│   ├── ProcessFactoryInterface.php     # Factory-Vertrag
│   ├── ProcessRunnerInterface.php      # Runner-Vertrag
│   ├── ProcessLoggerInterface.php      # Logger-Vertrag
│   ├── ProcessProgressInterface.php    # Fortschrittsverfolgungs-Vertrag
│   └── ProcessObserverInterface.php    # Beobachter-Vertrag (onCreated, onStarted, etc.)
├── Enums/
│   └── ProcessStatus.php               # new | wait | done | error | expired | cancelled
├── Events/
│   ├── ProcessCreated.php              # Ausgelöst nach Factory erstellt Prozess
│   ├── ProcessStarted.php             # Ausgelöst nach Runner beansprucht Prozess
│   ├── ProcessCompleted.php           # Ausgelöst nach erfolgreicher Ausführung
│   └── ProcessFailed.php             # Ausgelöst bei Ausführungsfehler
├── Exceptions/
│   ├── CallableResolutionException.php # Klasse/Methode nicht gefunden
│   ├── EntityNotAllowedException.php   # Entity nicht in Allowlist
│   └── InvalidParametersException.php  # Non-serializable Parameter
├── Jobs/
│   └── DelayedProcessJob.php           # Queue Job — führt Prozess via Runner aus
├── Models/
│   └── DelayedProcess.php              # Eloquent-Modell mit UUIDv7, Fortschritt, TTL, Callbacks
├── Providers/
│   └── DelayedProcessServiceProvider.php # Registriert Bindungen, Migrationen, Befehle
├── Resources/
│   └── DelayedProcessResource.php      # JSON-Antwort-Ressource
└── Services/
    ├── CallableResolver.php            # Validiert Allowlist + löst Callable auf
    ├── CallbackDispatcher.php          # Sendet Webhook POST bei Endzustand
    ├── DelayedProcessFactory.php       # Erstellt Prozess + dispatcht Job + Ereignisse
    ├── DelayedProcessLogger.php        # Puffert Logs mit konfigurierbarem Limit
    ├── DelayedProcessProgress.php      # Fortschrittsanzeige (0-100%)
    ├── DelayedProcessRunner.php        # Beansprucht + führt aus + Ereignisse + Callbacks
    └── EntityConfigResolver.php        # Löst Queue/Connection/Timeout-Konfiguration pro Entity auf

resources/js/delayed-process/
├── index.ts                            # Public Exports
├── types.ts                            # TypeScript-Typen, BatchPoller-Typen, DelayedProcessError
├── core/
│   ├── config.ts                       # Standardkonfiguration + CSRF Auto-Erkennung
│   ├── poller.ts                       # Poll-Schleife mit Timeout und Abbruch
│   └── batch-poller.ts                # BatchPoller — Abfrage mehrerer UUIDs auf einmal
├── axios/
│   └── interceptor.ts                  # Axios Response-Interceptor
├── fetch/
│   └── patch.ts                        # window.fetch Monkey-Patch
└── xhr/
    └── patch.ts                        # XMLHttpRequest Monkey-Patch (Doppel-Patch-Schutz)
```

---

## Backend-API

### Prozesse erstellen

Nutzen Sie `ProcessFactoryInterface` (über DI aufgelöst):

```php
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;

$process = $factory->make(
    entity: \App\Services\ExportService::class,
    method: 'exportCsv',
    // Variadische Parameter, die an Handler-Methode übergeben werden:
    $userId,
    $filters,
);
```

**Was inside `make()` passiert:**

1. Validiert, dass Entity in `allowed_entities` Config ist
2. Validiert, dass Klasse und Methode existieren
3. Validiert, dass Parameter JSON-serialisierbar sind
4. Erstellt `DelayedProcess` Modell in einer DB-Transaktion (generiert automatisch UUIDv7, setzt `status=new`, berechnet `expires_at` aus TTL-Config)
5. Konfiguriert Queue/Connection/Timeout des Jobs aus Entity-spezifischer Config
6. Dispatcht `DelayedProcessJob` in die Queue
7. Feuert `ProcessCreated` Ereignis
8. Gibt das persistierte Modell zurück

#### Erstellung mit Webhook-Callback

```php
$process = $factory->makeWithCallback(
    entity: \App\Services\ExportService::class,
    method: 'exportCsv',
    callbackUrl: 'https://your-app.com/webhooks/process-done',
    $userId,
);
```

Wenn der Prozess einen Endzustand erreicht (`done`, `error`, `expired`, `cancelled`), wird ein HTTP POST an die `callbackUrl` mit `{uuid, status, data}` gesendet.

#### Queue-Konfiguration pro Entity

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

### Status-Endpoint-Antwort

`DelayedProcessResource` gibt folgendes zurück:

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

Hinweise:
- `data` wird nur eingebunden, wenn `status` Terminal ist (`done`, `error`, `expired`, `cancelled`)
- `error_message` und `is_error_truncated` werden nur eingebunden, wenn ein Fehler vorhanden ist
- `progress` (0-100) spiegelt Ausführungsfortschritt wider
- `started_at` und `duration_ms` verfolgen Ausführungszeit

### Artisan-Befehle

#### `delayed:process` — Synchroner Worker

Verarbeitet verzögerte Aufgaben ohne einen Queue Worker zu benötigen. Nützlich für Entwicklung oder Single-Server-Bereitstellungen.

```bash
php artisan delayed:process
php artisan delayed:process --max-iterations=100
php artisan delayed:process --sleep=10
```

| Option | Standard | Beschreibung |
|--------|---------|-------------|
| `--max-iterations` | `0` (unendlich) | Nach N Prozessen anhalten. `0` = für immer laufen. |
| `--sleep` | `5` | Sekunden zum Schlafen, wenn keine Prozesse gefunden werden. |

#### `delayed:clear` — Bereinigung alter Prozesse

Löscht Terminal-Prozesse (`done` / `error`) älter als eine bestimmte Anzahl von Tagen.

```bash
php artisan delayed:clear
php artisan delayed:clear --days=7
php artisan delayed:clear --chunk=1000
```

| Option | Standard | Beschreibung |
|--------|---------|-------------|
| `--days` | `30` | Prozesse älter als N Tage löschen. |
| `--chunk` | `500` | Batch-Löschgröße für Speichereffizienz. |

#### `delayed:unstuck` — Festgefahrene Prozesse zurücksetzen

Setzt in `wait` Status festgefahrene Prozesse zurück auf `new`, damit sie wiederholt werden können.

```bash
php artisan delayed:unstuck
php artisan delayed:unstuck --timeout=30
php artisan delayed:unstuck --dry-run
```

| Option | Standard | Beschreibung |
|--------|---------|-------------|
| `--timeout` | `60` | Betrachte Prozesse nach N Minuten in `wait` als festgefahren. |
| `--dry-run` | `false` | Liste festgefahrene Prozesse auf, ohne sie zurückzusetzen. |

#### `delayed:expire` — TTL-Prozesse ablaufen lassen

Markiert Prozesse, deren `expires_at` überschritten wurde, als `expired`.

```bash
php artisan delayed:expire
php artisan delayed:expire --dry-run
```

| Option | Standard | Beschreibung |
|--------|---------|-------------|
| `--dry-run` | `false` | Zählung anzeigen ohne Änderungen. |

#### `delayed:migrate-v1` — Legacy-Migration

Aktualisiert das Datenbankschema aus der Legacy-Struktur. Fügt `error_message` / `error_trace` Spalten hinzu, konvertiert Spalten zu JSONB (PostgreSQL) oder JSON (MySQL), erstellt Partial/Composite Indexes und fügt CHECK-Constraint hinzu.

```bash
php artisan delayed:migrate-v1
php artisan delayed:migrate-v1 --force
```

---

## Frontend-Interceptoren

Das Modul `resources/js/delayed-process/` bietet transparente Interceptoren, die automatisch Antworten mit verzögertem Prozess erkennen und bis zur Fertigstellung abfragen.

### Wie es funktioniert

1. Ihre API gibt eine Antwort zurück, die `{ payload: { delayed: { uuid: "..." } } }` enthält
2. Der Interceptor erkennt die UUID
3. Er startet die Abfrage des Status-Endpoints: `GET {statusUrl}?uuid={uuid}`
4. Wenn `status` zu `done` wird, ersetzt der Interceptor die Antwort-Payload mit den Ergebnisdaten
5. Wenn `status` zu `error`, `expired` oder `cancelled` wird, wirft der Interceptor einen `DelayedProcessError`
6. Abfrageanfragen enthalten Header `X-Delayed-Process-Poll: 1` zur Verhinderung von Endlosschleifen

### Dateistruktur

| Datei | Zweck |
|------|---------|
| `index.ts` | Public Exports |
| `types.ts` | TypeScript-Typen, `BatchPollerConfig`, `DelayedProcessError` |
| `core/config.ts` | Standardkonfiguration, CSRF Auto-Erkennung, `resolveConfig()` |
| `core/poller.ts` | `pollUntilDone()` — Kern-Poll-Schleife mit Timeout und Abbruch |
| `core/batch-poller.ts` | `BatchPoller` — Abfrage mehrerer UUIDs in einer Anfrage |
| `axios/interceptor.ts` | `applyAxiosInterceptor()` — Axios Response-Interceptor |
| `fetch/patch.ts` | `patchFetch()` — Monkey-Patch für `window.fetch` |
| `xhr/patch.ts` | `patchXHR()` — Monkey-Patch für `XMLHttpRequest` (Doppel-Patch-Schutz) |

### Axios-Interceptor

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

// Zum Entfernen: api.interceptors.response.eject(interceptorId);
```

### Fetch-Patch

```typescript
import { patchFetch } from './delayed-process';

const unpatch = patchFetch({
    statusUrl: '/api/common/delayed-process/status',
    pollingInterval: 3000,
});

// Alle fetch() Aufrufe fragen jetzt automatisch verzögerte Prozesse ab
const response = await fetch('/api/reports/generate', { method: 'POST' });
const data = await response.json();
console.log(data.payload); // Aufgelöstes Ergebnis, nicht die UUID

// Um Original-Fetch wiederherzustellen:
unpatch();
```

### XHR-Patch

```typescript
import { patchXHR } from './delayed-process';

const unpatch = patchXHR({
    statusUrl: '/api/common/delayed-process/status',
});

const xhr = new XMLHttpRequest();
xhr.open('POST', '/api/reports/generate');
xhr.onload = function () {
    const data = JSON.parse(this.responseText);
    console.log(data.payload); // Aufgelöstes Ergebnis
};
xhr.send();

// Um Original-XHR wiederherzustellen:
unpatch();
```

### DelayedProcessConfig

| Option | Typ | Standard | Beschreibung |
|--------|------|---------|-------------|
| `statusUrl` | `string` | `'/api/common/delayed-process/status'` | URL zur Abfrage von Prozessstatus |
| `pollingInterval` | `number` | `3000` | Millisekunden zwischen Poll-Anfragen |
| `maxAttempts` | `number` | `100` | Maximale Anzahl von Poll-Versuchen |
| `timeout` | `number` | `300000` | Gesamtes Timeout in Millisekunden (5 min) |
| `headers` | `Record<string, string>` | `{}` | Zusätzliche Header für Poll-Anfragen |
| `onPoll` | `(uuid: string, attempt: number) => void` | `undefined` | Callback, aufgerufen bei jedem Poll |

CSRF Token aus `<meta name="csrf-token">` wird automatisch in Poll-Anfragen eingebunden.

### Batch-Poller

Zur Abfrage mehrerer Prozesse gleichzeitig (z.B. für Massenoperationen):

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

Wird geworfen, wenn ein Prozess mit `error`, `expired` oder `cancelled` Status abschließt oder Polling ein Timeout hat.

```typescript
import { DelayedProcessError } from './delayed-process';

try {
    const response = await api.post('/api/reports/generate');
} catch (error) {
    if (error instanceof DelayedProcessError) {
        console.error(error.uuid);         // Prozess UUID
        console.error(error.status);       // 'error' | 'expired' | 'cancelled'
        console.error(error.errorMessage); // Serverseitige Fehlermeldung
    }
}
```

### Schleifenprävention

Alle Poll-Anfragen enthalten den Header `X-Delayed-Process-Poll: 1`. Die Interceptoren prüfen auf diesen Header und überspringen die Interception bei Poll-Anfragen, wodurch unendliche Poll-Schleifen verhindert werden.

---

## Konfigurationsreferenz

Datei: `config/delayed-process.php`

| Schlüssel | Typ | Standard | Beschreibung |
|-----|------|---------|-------------|
| `allowed_entities` | `array` | `[]` | FQCN-Liste von Klassen, die als Prozess-Handler zugelassen sind; String-Werte oder Objekt-Keyed Arrays `Entity::class => [config]` |
| `default_attempts` | `int` | `5` | Maximale Wiederholungsversuche vor Markierung als `error` |
| `clear_after_days` | `int` | `30` | `delayed:clear` löscht Terminal-Prozesse älter als dies |
| `stuck_timeout_minutes` | `int` | `60` | `delayed:unstuck` betrachtet `wait` Prozesse nach dies als festgefahren |
| `log_sensitive_context` | `bool` | `false` | Log-Kontext-Arrays in Prozess-Logs einschließen |
| `log_buffer_limit` | `int` | `500` | Max. Log-Einträge im Speicher-Puffer pro Prozess (0 = unbegrenzt) |
| `callback.enabled` | `bool` | `false` | Webhook POST bei Endzustand aktivieren |
| `callback.timeout` | `int` | `10` | Webhook HTTP Timeout in Sekunden |
| `default_ttl_minutes` | `int\|null` | `null` | Standard-TTL für neue Prozesse (`null` = keine Ablauf) |
| `job.timeout` | `int` | `300` | Queue-Job Timeout in Sekunden |
| `job.tries` | `int` | `1` | Queue-Job Wiederholungsversuche (unabhängig von Prozess-Versuchen) |
| `job.backoff` | `array` | `[30, 60, 120]` | Queue-Job Backoff-Verzögerungen in Sekunden |
| `command.sleep` | `int` | `5` | `delayed:process` Schlaf wenn untätig (Sekunden) |
| `command.max_iterations` | `int` | `0` | `delayed:process` Iterations-Limit (`0` = unendlich) |
| `command.throttle` | `int` | `100000` | `delayed:process` Drosselung zwischen Iterationen (Mikrosekunden) |
| `clear_chunk_size` | `int` | `500` | `delayed:clear` Batch-Löschgröße |

---

## Datenbankschema

### Tabelle: `delayed_processes`

| Spalte | Typ | Standard | Beschreibung |
|--------|------|---------|-------------|
| `id` | `bigint` PK | Auto-Increment | Primärschlüssel |
| `uuid` | `string(36)` UNIQUE | Auto (UUIDv7) | Eindeutiger Prozess-Identifikator |
| `entity` | `string` nullable | `NULL` | FQCN der Handler-Klasse |
| `method` | `string` | — | Handler-Methodenname |
| `parameters` | `jsonb` / `json` | `[]` | Serialisierte Aufruf-Argumente |
| `data` | `jsonb` / `json` | `[]` | Ausführungs-Ergebnis Payload |
| `logs` | `jsonb` / `json` | `[]` | Erfasste Log-Einträge |
| `status` | `string` | `'new'` | Prozess-Status (`new`, `wait`, `done`, `error`, `expired`, `cancelled`) |
| `attempts` | `tinyint unsigned` | `5` | Maximale Wiederholungsversuche |
| `try` | `tinyint unsigned` | `0` | Aktuelle Versuchsnummer |
| `error_message` | `string(1000)` nullable | `NULL` | Letzte Fehlermeldung (gekürzt mit Indikator) |
| `error_trace` | `text` nullable | `NULL` | Letzte Fehler-Stack-Trace |
| `started_at` | `timestamptz` nullable | `NULL` | Ausführungs-Startzeit |
| `duration_ms` | `bigint unsigned` nullable | `NULL` | Ausführungs-Dauer in Millisekunden |
| `callback_url` | `string(2048)` nullable | `NULL` | Webhook-URL für Benachrichtigung bei Endzustand |
| `progress` | `tinyint unsigned` | `0` | Ausführungsfortschritt (0-100) |
| `expires_at` | `timestamptz` nullable | `NULL` | Prozess-Ablaufzeit (TTL) |
| `created_at` | `timestamptz` | NOW | Erstellungs-Zeitstempel |
| `updated_at` | `timestamptz` | NOW | Letzte Aktualisierungs-Zeitstempel |

### Indizes

**PostgreSQL** (Partial Indexes für optimale Leistung):

| Index | Bedingung |
|-------|-----------|
| `(status, try)` | `WHERE status = 'new'` |
| `(created_at)` | `WHERE status IN ('done', 'error', 'expired', 'cancelled')` |
| `(updated_at)` | `WHERE status = 'wait'` |
| `(expires_at)` | `WHERE status IN ('new', 'wait') AND expires_at IS NOT NULL` |

**MySQL / MariaDB** (zusammengesetzte Indizes):

| Index |
|-------|
| `(status, try)` |
| `(status, created_at)` |
| `(status, updated_at)` |

### Beschränkungen

- `CHECK (status IN ('new', 'wait', 'done', 'error', 'expired', 'cancelled'))` auf allen Datenbanken

---

## Sicherheit

### Entity-Allowlist

Nur Klassen, die in `config('delayed-process.allowed_entities')` aufgeführt sind, können ausgeführt werden. Der Versuch, einen Prozess mit einer nicht aufgelisteten Klasse zu erstellen, wirft `EntityNotAllowedException`.

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\ReportService::class,
    \App\Services\ExportService::class,
    // Nur diese Klassen können als Handler verwendet werden
],
```

### Callable-Validierung

Vor der Ausführung überprüft `CallableResolver`:
1. Entity-Klasse ist in der Allowlist
2. Klasse existiert (`class_exists()`)
3. Methode existiert (`method_exists()`)

Die Instanziierung verwendet `app($entity)` — vollständige Laravel DI-Container-Unterstützung.

### Log-Datenschutz

Setzen Sie `log_sensitive_context` auf `false` (Standard), um Kontext-Arrays aus erfassten Log-Einträgen zu entfernen. Nur Log-Level, Zeitstempel und Nachricht werden gespeichert.

### CSRF-Schutz

Der Frontend-Poller liest automatisch `<meta name="csrf-token">` und fügt es in Poll-Anfrage-Header ein. Stellen Sie sicher, dass Ihr Status-Endpoint hinter CSRF-Middleware steht oder den Token explizit überprüft.

---

## Kochbuch

Für Rezepte, Muster und Fehlerbehebung, siehe das **[Kochbuch](cookbook.de.md)**.

Verfügbar in: [English](cookbook.md) | [Русский](cookbook.ru.md) | [Deutsch](cookbook.de.md) | [中文](cookbook.zh.md)

---

## Frontend-Integrationsleitfaden

Für einen ausführlichen Schritt-für-Schritt-Leitfaden zur Integration von Interceptoren in **Vue.js 3** und **React**-Anwendungen, siehe den **[Frontend-Interceptoren-Leitfaden](frontend-interceptors-guide.de.md)**.

Enthält: Composables/Hooks, Fortschrittsanzeige, Batch-Abfrage, Fehlerbehandlung, SSR-Unterstützung und Testen.

---

## Lizenz

[MIT](../LICENSE.md) &copy; Denis Skripchenko
