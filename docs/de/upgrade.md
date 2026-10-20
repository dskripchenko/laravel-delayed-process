# Upgrade-Leitfaden

Verfügbar in: [English](../en/upgrade.md) | [Русский](../ru/upgrade.md) | [Deutsch](upgrade.md) | [中文](../zh/upgrade.md)

Jeder Abschnitt beschreibt, was sich zwischen zwei Versionen geändert hat und
was zu tun ist, die neueste Version zuerst. Bei einem Upgrade über mehrere
Versionen hinweg werden alle dazwischenliegenden Abschnitte von unten nach oben
angewendet.

- [2.1 → 2.2](#21--22)
- [2.0 → 2.1](#20--21)
- [1.x → 2.x](#1x--2x)

---

## 2.1 → 2.2

Keine Änderungen an Schema oder Konfiguration.

**Benannte Parameter werden per Name gebunden.** Vor 2.2 wurde ein
Parametersatz mit String-Schlüsseln dem Handler immer als ein einziges
Array-Argument übergeben. Wenn jetzt jeder Schlüssel einen Parameter der
Handler-Methode benennt, werden die Schlüssel per Name gebunden:

```php
$factory->make(ReportService::class, 'build', ...['from' => $from, 'to' => $to]);

// 2.1: build(['from' => ..., 'to' => ...])
// 2.2: build(from: ..., to: ...)
```

Ein als `handle(array $params)` deklarierter Handler erhält weiterhin das
ganze Array, es sei denn, die Schlüssel stimmen zufällig mit seinen
Parameternamen überein: Ein Parametersatz `['params' => ...]`, der an
`handle(array $params)` geht, wird jetzt per Name gebunden.
Eine Liste wird wie bisher positionsweise übergeben.

**2.1.2: Konstruktor von `DelayedProcessRunner`.** Das vierte Argument ist
jetzt ein optionales `ProcessProgressInterface` statt eines
`DelayedProcessProgress`. Das betrifft nur Code, der den Runner selbst
erzeugt; wird er aus dem Container aufgelöst, ist keine Änderung nötig.

---

## 2.0 → 2.1

Am Code ist nichts zu ändern.

2.0.0 setzte PHP ^8.5 und Laravel 12 voraus. Ab 2.1.0 unterstützt das Paket
PHP ^8.2 mit Laravel 11, 12 und 13.

---

## 1.x → 2.x

2.0 ist eine Neuentwicklung. Tabellenname, Job-Klasse und Artisan-Befehle
haben ihre Namen behalten, aber fast alles um sie herum hat sich geändert:
Das Verhalten des Modells ist in Services gewandert, Status sind jetzt ein
Enum, nur Klassen aus der Allowlist können ausgeführt werden, und die Tabelle
hat neue Spalten, JSON-Typen und Indizes erhalten.

### Anforderungen

| | 1.x | 2.x |
|---|---|---|
| PHP | jede, die die Laravel-Version zulässt | ^8.2 (2.0.0: ^8.5) |
| Laravel | 6 – 12 | 11, 12, 13 (2.0.0: nur 12) |
| `dskripchenko/php-array-helper` | erforderlich | nicht erforderlich |

Das Paket zieht `dskripchenko/php-array-helper` nicht mehr mit. Wenn Ihr Code
`array_is_associative()`, `array_merge_deep()` oder andere Helfer daraus
aufruft, binden Sie dieses Paket direkt ein:

```bash
composer require dskripchenko/php-array-helper
```

### Schritt 1. Queue leeren und Worker stoppen

Von 1.x eingereihte Jobs erreichen einen Runner, der die Allowlist prüft
(Schritt 3) und Spalten schreibt, die erst ab Schritt 4 existieren. Vor dem
Deployment:

1. Keine neuen Prozesse mehr anlegen oder die Anwendung in den Wartungsmodus versetzen.
2. Queue-Worker und `delayed:process` die laufende Arbeit abschließen lassen.
3. Worker und Scheduler stoppen.

Prozesse, die in `new` verbleiben, werden nach dem Upgrade erneut aufgegriffen.
Prozesse, die in `wait` verbleiben, setzt `php artisan delayed:unstuck` nach
Abschluss des Upgrades zurück.

### Schritt 2. Paket aktualisieren

```bash
composer require dskripchenko/laravel-delayed-process:^2.2
```

### Schritt 3. Konfiguration veröffentlichen und Allowlist befüllen

```bash
php artisan vendor:publish --tag=delayed-process-config
```

**Dieser Schritt ist Pflicht.** In 2.x können nur Klassen ausgeführt werden,
die in `allowed_entities` aufgeführt sind. Die Liste ist standardmäßig leer,
ohne sie wirft also jeder `make()`-Aufruf `EntityNotAllowedException`.

```php
// config/delayed-process.php
'allowed_entities' => [
    \App\Services\ReportService::class,
    // Queue-Einstellungen pro Klasse
    \App\Services\HeavyExport::class => ['queue' => 'heavy', 'timeout' => 600],
],
```

Um die verwendeten Klassen zu finden, suchen Sie in Ihrem Code nach
`DelayedProcess::make(` und sehen Sie sich die Spalte `entity` der
vorhandenen Zeilen an:

```sql
SELECT DISTINCT entity, method FROM delayed_processes;
```

Der Handler muss eine Klasse mit einer öffentlichen Methode sein. 1.x
akzeptierte auch eine leere Entity mit dem Namen einer globalen Funktion oder
eine Klasse ohne Methode (ein Invokable). 2.x akzeptiert beides nicht:
Kapseln Sie solchen Code in einer Klassenmethode.

Weitere Einstellungen (Versuche, Bereinigungsalter, Stuck-Timeout,
Job-Timeout usw.) sind in der [Konfigurationsreferenz](README.md#konfigurationsreferenz) beschrieben.

### Schritt 4. Tabelle aktualisieren

**Die Reihenfolge ist wichtig: `delayed:migrate-v1` vor `migrate` ausführen.**

```bash
php artisan delayed:migrate-v1 --force
php artisan migrate --force
```

`delayed:migrate-v1` bringt die 1.x-Tabelle auf das 2.0-Schema:

- fügt `error_message` und `error_trace` hinzu;
- fügt eine CHECK-Beschränkung auf `status` hinzu (PostgreSQL, MySQL 8.0.16+, MariaDB);
- PostgreSQL: konvertiert `parameters`, `data`, `logs` nach `jsonb` und
  `created_at`, `updated_at` nach `timestamptz`, legt Partial Indexes an;
- MySQL/MariaDB: konvertiert die drei Spalten nach `json`, legt zusammengesetzte Indizes an;
- SQLite: fügt nur die beiden Spalten hinzu.

Anschließend führt `migrate` die Paketmigration
`2026_03_11_000001_add_extended_columns_to_delayed_processes_table` aus, die
`started_at`, `duration_ms`, `callback_url`, `progress`, `expires_at`
hinzufügt und die CHECK-Beschränkung um `expired` und `cancelled` erweitert.

In umgekehrter Reihenfolge schlägt das Upgrade fehl:

- **MySQL/MariaDB:** Die Migration platziert ihre Spalten nach `error_trace`,
  das noch nicht existiert, und bricht mit "Unknown column" ab.
- **PostgreSQL:** Die Migration läuft durch, danach fügt `delayed:migrate-v1`
  die beiden Spalten hinzu und scheitert an der CHECK-Beschränkung, die die
  Migration bereits angelegt hat. Bei einem erneuten Lauf meldet der Befehl
  "Already migrated", weil `error_message` jetzt existiert, und die
  `jsonb`-Konvertierung sowie die Indizes werden nie angewendet.

Wenn das Deployment `migrate` selbstständig ausführt, führen Sie
`delayed:migrate-v1` vor diesem Schritt aus.

Vor der Ausführung auf Produktionsdaten prüfen:

- **Zeitzone (PostgreSQL).** Zeitstempel werden als UTC konvertiert
  (`AT TIME ZONE 'UTC'`). Hat die Anwendung in `created_at`/`updated_at`
  Ortszeit gespeichert, verschieben sich die konvertierten Werte um den Offset.
- **Ungültiges JSON.** Die Konvertierung schlägt bei einer Zeile fehl, deren
  `parameters`, `data` oder `logs` kein gültiges JSON ist. 1.x hat dort immer
  JSON geschrieben, aber von Hand bearbeitete Zeilen sollten vorab geprüft werden.
- **Status.** Die CHECK-Beschränkung akzeptiert `new`, `wait`, `done`, `error`.
  Zeilen mit einem anderen Status müssen vor dem Upgrade korrigiert werden.
- **Backup.** Unter MySQL sind Schemaänderungen nicht transaktional: Ein
  Fehler mittendrin hinterlässt die Tabelle teilweise aktualisiert. Sichern
  Sie die Tabelle vorher.

Die Erstellungsmigration von 1.x und die von 2.x tragen denselben Namen, daher
betrachtet Laravel sie als bereits ausgeführt und legt die Tabelle nicht erneut an.

### Schritt 5. Code aktualisieren

#### Einen Prozess erstellen

`DelayedProcess::make()` gibt es nicht mehr. Prozesse werden über
`ProcessFactoryInterface` mit denselben Argumenten erstellt:

```php
// 1.x
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

$process = DelayedProcess::make(ReportService::class, 'build', $from, $to);

// 2.x
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;

$process = app(ProcessFactoryInterface::class)->make(ReportService::class, 'build', $from, $to);
```

Injizieren Sie `ProcessFactoryInterface` nach Möglichkeit in einen Konstruktor
oder eine Controller-Methode, statt `app()` aufzurufen. Die Factory prüft
Allowlist und Methode vor dem Speichern, ein Tippfehler schlägt also jetzt
bei der Erstellung fehl und nicht erst im Worker.

`DelayedProcessFactory::makeWithCallback($entity, $method, $url, ...$params)`
sendet zusätzlich einen Webhook, wenn der Prozess abgeschlossen ist; siehe README.

#### Entfernte Modellmethoden

| 1.x | 2.x |
|---|---|
| `DelayedProcess::make($entity, $method, ...$params)` | `ProcessFactoryInterface::make()` |
| `$process->run()` | `app(ProcessRunnerInterface::class)->run($process)` |
| `$process->log($level, $message, $context)` | über `Log::...()` im Handler schreiben; der Job sammelt die Zeilen |
| `$process->toResponse()` | siehe [Antworten](#antworten) |
| `DelayedProcess::STATUS_NEW` … `STATUS_ERROR` | `ProcessStatus::New` … `ProcessStatus::Error` |
| `DelayedProcess::DEFAULT_ATTEMPTS` | `config('delayed-process.default_attempts')` |

#### Status ist ein Enum

`$process->status` ist jetzt ein `ProcessStatus`-Enum, kein String. Code, der
ihn als String vergleicht oder ausgibt, funktioniert nicht mehr:

```php
// 1.x
if ($process->status === DelayedProcess::STATUS_ERROR) { ... }
strtoupper($process->status);

// 2.x
use Dskripchenko\DelayedProcess\Enums\ProcessStatus;

if ($process->status === ProcessStatus::Error) { ... }
strtoupper($process->status->value);
```

Datenbankabfragen können weiterhin die String-Werte verwenden
(`where('status', 'new')`) oder die Builder-Scopes: `whereNew()`,
`whereTerminal()`, `whereStuck()`, `whereExpired()`, `whereUuids()`,
`olderThanDays()`.

Zwei Status sind hinzugekommen, `expired` und `cancelled`. Beide sind
Endzustände: Code, der „nicht `done` und nicht `error`“ als „läuft noch“
behandelt, muss stattdessen `$process->status->isTerminal()` verwenden.

#### Antworten

`Resources\DelayedProcess` wurde durch `Resources\DelayedProcessResource` mit
einer anderen Struktur ersetzt. `toResponse()` in 1.x lieferte:

```json
{ "delayed": { "uuid": "..." } }
```

Um diese Antwort für einen Endpoint beizubehalten, der einen Prozess startet,
bauen Sie sie selbst. Die mitgelieferten Frontend-Interceptoren suchen nach
`delayed.uuid`:

```php
return response()->json([
    'delayed' => ['uuid' => $process->uuid, 'status' => $process->status->value],
]);
```

`DelayedProcessResource` ist für den Status-Endpoint gedacht und liefert
`uuid`, `status`, `data` (nur bei Endzuständen), `error_message`,
`progress`, `started_at`, `duration_ms`, `attempts`, `current_try`,
`created_at`, `updated_at`.

#### Das Modell erweitern

Eine Unterklasse von `DelayedProcess` funktioniert für Abfragen und
Admin-Oberflächen weiterhin, mit folgenden Unterschieden:

- die Factory erzeugt das Basismodell `DelayedProcess`, nicht Ihre
  Unterklasse, daher laufen bei der Erstellung keine Modell-Events und
  Accessoren, die in der Unterklasse definiert sind;
- Casts werden in `casts()` deklariert. Eine `$casts`-Eigenschaft der
  Unterklasse wird mit ihnen zusammengeführt und ersetzt den Enum-Cast von
  `status` nicht;
- `$fillable` ist jetzt `entity`, `method`, `parameters`, `callback_url`.
  `uuid`, `status`, `data`, `logs`, `attempts`, `try` sind nicht mehr per
  Mass Assignment zuweisbar: `create([... 'status' => ...])` verwirft sie stillschweigend.

### Verhaltensänderungen

Diese erfordern keine Codeänderungen, können aber das beobachtete Verhalten ändern.

| | 1.x | 2.x |
|---|---|---|
| Abgefangene Fehler | nur `Exception`; ein `Error` (`TypeError` usw.) ließ den Prozess in `wait` | jedes `Throwable`; Meldung und Trace landen in `error_message`/`error_trace` |
| Abgeschlossenen Prozess erneut ausführen | `run()` auf einem `done`/`error`-Prozess führte ihn erneut aus | abgeschlossene Prozesse werden übersprungen |
| Zwei Worker, ein Prozess | konnten ihn beide ausführen | der Prozess wird per atomarem `UPDATE` beansprucht |
| `uuid` | UUID v4 | UUID v7 (alte Werte bleiben gültig) |
| Log-Einträge | mit der Zeit als Schlüssel, `Level`/`Date`/`Message`/`Context` | eine Liste aus `level`/`date`/`message`, geschrieben am Ende des Laufs |
| Log-Kontext | immer gespeichert | verworfen, sofern `log_sensitive_context` nicht `true` ist |
| `created_at` in JSON | `Y-m-d H:i:s` | ISO 8601 (Standard-Serialisierung des Modells) |
| Job-Wiederholungen | `--tries` des Workers | `job.tries` in der Config, standardmäßig 1 |

Von 1.x geschriebene Zeilen behalten ihre Log-Einträge im alten Format. Code,
der `logs` liest, muss beide Formate verarbeiten.

#### `delayed:process` beendet sich nicht mehr

In 1.x verarbeitete `delayed:process` alle `new`-Prozesse und beendete sich.
In 2.x läuft der Befehl, bis er gestoppt wird, und schläft `command.sleep`
Sekunden, wenn die Queue leer ist.

Wenn der Scheduler ihn jede Minute startet, bleibt jetzt jeder Lauf am Leben
und die Instanzen häufen sich an. Wählen Sie eine Variante:

```php
// im Scheduler belassen: beenden, sobald nichts mehr übrig ist
$schedule->command('delayed:process --max-iterations=1000')
    ->everyMinute()
    ->withoutOverlapping();
```

oder führen Sie `php artisan delayed:process` als langlebigen Prozess unter
Supervisor oder systemd aus und entfernen Sie ihn aus dem Scheduler.

#### Neue Befehle

Fügen Sie die benötigten dem Scheduler hinzu:

```php
$schedule->command('delayed:clear')->daily();      // wie in 1.x
$schedule->command('delayed:unstuck')->everyTenMinutes();
$schedule->command('delayed:expire')->everyMinute(); // nur mit default_ttl_minutes
```

`delayed:clear` akzeptiert jetzt `--days` und `--chunk` und löscht in
Blöcken; das Standardalter ist `clear_after_days` (30, wie in 1.x).

### Checkliste

- [ ] Queue geleert, Worker gestoppt
- [ ] `composer require dskripchenko/laravel-delayed-process:^2.2`
- [ ] `dskripchenko/php-array-helper` direkt eingebunden, falls seine Helfer verwendet werden
- [ ] Config veröffentlicht, jede Handler-Klasse in `allowed_entities`
- [ ] Tabelle gesichert
- [ ] `delayed:migrate-v1 --force`, **danach** `migrate --force`
- [ ] `DelayedProcess::make()` → `ProcessFactoryInterface::make()`
- [ ] `STATUS_*`-Konstanten und String-Vergleiche → `ProcessStatus`
- [ ] `toResponse()` → explizite Antwort mit `delayed.uuid`
- [ ] Code, der `logs` liest, verarbeitet beide Formate
- [ ] `delayed:process` mit `--max-iterations` im Scheduler oder als Daemon betrieben
- [ ] `delayed:unstuck` einmal nach dem Deployment ausgeführt, dann im Scheduler eingeplant
