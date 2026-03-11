# Integrationsleitfaden für Frontend-Interceptoren

**Sprache:** [English](frontend-interceptors-guide.md) | [Русский](frontend-interceptors-guide.ru.md) | Deutsch | [中文](frontend-interceptors-guide.zh.md) | Zurück zu [README](README.de.md)

Detaillierter Leitfaden zur Integration von `laravel-delayed-process` Frontend-Interceptoren in **Vue.js 3** und **React** Anwendungen.

---

## Inhaltsverzeichnis

- [Übersicht](#übersicht)
- [Wie Interceptoren funktionieren](#wie-interceptoren-funktionieren)
- [Verfügbare Interceptoren](#verfügbare-interceptoren)
- [Vue.js 3-Integration](#vuejs-3-integration)
  - [Projekt-Setup](#vue-projekt-setup)
  - [Axios-Plugin](#vue-axios-plugin)
  - [Composable: useDelayedProcess](#composable-usedelayedprocess)
  - [Progress-Komponente](#vue-progress-komponente)
  - [Fehlerbehandlung](#vue-fehlerbehandlung)
  - [Batch-Operationen](#vue-batch-operationen)
  - [Vollständiges Beispiel: Report-Seite](#vue-vollständiges-beispiel)
- [React-Integration](#react-integration)
  - [Projekt-Setup](#react-projekt-setup)
  - [Axios-Instanz](#react-axios-instanz)
  - [Hook: useDelayedProcess](#hook-usedelayedprocess)
  - [Progress-Komponente](#react-progress-komponente)
  - [Fehlerbehandlung](#react-fehlerbehandlung)
  - [Batch-Operationen](#react-batch-operationen)
  - [Vollständiges Beispiel: Export-Seite](#react-vollständiges-beispiel)
- [Fortgeschrittene Themen](#fortgeschrittene-themen)
  - [Mehrere API-Instanzen](#mehrere-api-instanzen)
  - [SSR-Überlegungen](#ssr-überlegungen)
  - [Tests](#tests)
  - [TypeScript-Typen](#typescript-typen)

---

## Übersicht

Das Frontend-Modul `delayed-process` fängt transparent API-Antworten ab, die eine Delayed-Process-UUID enthalten, pollt den Status-Endpoint bis zur Fertigstellung und gibt das Endergebnis so zurück, als würde die Operation synchron ablaufen.

**Unterstützte Interceptoren:**

| Interceptor | HTTP-Client | Beste für |
|-------------|-------------|----------|
| `applyAxiosInterceptor()` | Axios | Vue.js, React, jedes Framework mit Axios |
| `patchFetch()` | nativer `fetch` | React (SWR, React Query), Next.js |
| `patchXHR()` | XMLHttpRequest | Legacy-Code, jQuery AJAX |
| `BatchPoller` | nativer `fetch` | Bulk-Operationen mit mehreren UUIDs |

**Empfohlener Ansatz:** Verwende `applyAxiosInterceptor()` mit Axios für Vue.js und React Projekte.

---

## Wie Interceptoren funktionieren

```
1. Client sendet POST /api/reports/generate
2. Server antwortet: { success: true, payload: { delayed: { uuid: "abc-123" } } }
3. Interceptor erkennt die "delayed" Payload
4. Interceptor startet Polling: GET /api/common/delayed-process/status?uuid=abc-123
5. Poll-Antwort: { success: true, payload: { uuid: "abc-123", status: "wait", progress: 45 } }
6. ... pollt alle N ms weiter ...
7. Poll-Antwort: { success: true, payload: { uuid: "abc-123", status: "done", data: { url: "..." } } }
8. Interceptor ersetzt die ursprüngliche Antwort mit den finalen Daten
9. Client erhält das Ergebnis, als ob die Anfrage normal abgeschlossen wäre
```

**Terminale Status, die das Polling stoppen:**
- `done` — erfolg, gibt `data` zurück
- `error` — wirft `DelayedProcessError`
- `expired` — wirft `DelayedProcessError`
- `cancelled` — wirft `DelayedProcessError`

---

## Verfügbare Interceptoren

### Konfigurationsoptionen

Alle Interceptoren akzeptieren die gleiche `DelayedProcessConfig`:

```typescript
interface DelayedProcessConfig {
  statusUrl: string;           // Standard: '/api/common/delayed-process/status'
  pollingInterval: number;     // Standard: 3000 (ms)
  maxAttempts: number;         // Standard: 100
  timeout: number;             // Standard: 300_000 (5 min)
  headers: Record<string, string>;
  onPoll?: (uuid: string, attempt: number) => void;
}
```

CSRF-Token aus `<meta name="csrf-token">` wird automatisch eingebunden.

---

## Vue.js 3-Integration

### Vue Projekt-Setup

#### 1. Modul kopieren

Kopiere `resources/js/delayed-process/` in das `src/shared/lib/delayed-process/` Verzeichnis deines Vue-Projekts (nach FSD-Architektur) oder `src/lib/delayed-process/`.

#### 2. Axios installieren (falls noch nicht geschehen)

```bash
npm install axios
```

### Vue Axios-Plugin

Erstelle eine zentralisierte Axios-Instanz mit dem Delayed-Process-Interceptor:

**`src/shared/api/http.ts`**

```typescript
import axios from 'axios';
import { applyAxiosInterceptor } from '@/shared/lib/delayed-process';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
});

// Delayed-Process-Interceptor anwenden
applyAxiosInterceptor(api, {
  statusUrl: '/api/common/delayed-process/status',
  pollingInterval: 2000,
  maxAttempts: 150,
  timeout: 600_000,  // 10 min für schwere Operationen
});

export { api };
```

**`src/app/plugins/api.ts`** (Vue-Plugin, optional)

```typescript
import type { App } from 'vue';
import { api } from '@/shared/api/http';

export const apiPlugin = {
  install(app: App): void {
    app.config.globalProperties.$api = api;
    app.provide('api', api);
  },
};
```

**`src/app/main.ts`**

```typescript
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { apiPlugin } from '@/app/plugins/api';
import App from '@/app/App.vue';

const app = createApp(App);
app.use(createPinia());
app.use(apiPlugin);
app.mount('#app');
```

### Composable: useDelayedProcess

Ein reaktives Composable, das den Zustand eines Delayed-Process-Aufrufs verfolgt:

**`src/shared/composables/useDelayedProcess.ts`**

```typescript
import { ref, type Ref } from 'vue';
import { api } from '@/shared/api/http';
import { DelayedProcessError } from '@/shared/lib/delayed-process';

interface UseDelayedProcessReturn<T> {
  data: Ref<T | null>;
  error: Ref<string | null>;
  isLoading: Ref<boolean>;
  execute: (...args: unknown[]) => Promise<T | null>;
  reset: () => void;
}

export function useDelayedProcess<T = unknown>(
  url: string,
  method: 'get' | 'post' | 'put' | 'delete' = 'post',
): UseDelayedProcessReturn<T> {
  const data = ref<T | null>(null) as Ref<T | null>;
  const error = ref<string | null>(null);
  const isLoading = ref(false);

  async function execute(...args: unknown[]): Promise<T | null> {
    isLoading.value = true;
    error.value = null;
    data.value = null;

    try {
      const response = await api.request({
        url,
        method,
        data: args[0] ?? undefined,
        params: method === 'get' ? args[0] : undefined,
      });

      // Interceptor hat bereits Delayed Process aufgelöst
      const result = response.data?.payload ?? response.data;
      data.value = result as T;

      return result as T;
    } catch (err: unknown) {
      if (err instanceof DelayedProcessError) {
        error.value = err.errorMessage ?? `Process ${err.status}: ${err.uuid}`;
      } else if (err instanceof Error) {
        error.value = err.message;
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

interface ReportResult {
  url: string;
  rows: number;
}

const { data, error, isLoading, execute } = useDelayedProcess<ReportResult>(
  '/reports/generate',
);

async function generateReport(): Promise<void> {
  await execute({ user_id: 1, format: 'pdf' });
}
</script>

<template>
  <div>
    <button :disabled="isLoading" @click="generateReport">
      {{ isLoading ? 'Generating...' : 'Generate Report' }}
    </button>

    <div v-if="error" class="error">{{ error }}</div>

    <div v-if="data">
      <a :href="data.url">Download ({{ data.rows }} rows)</a>
    </div>
  </div>
</template>
```

### Vue Progress-Komponente

Verfolge den Fortschritt während des Polling mit dem `onPoll` Callback:

**`src/shared/composables/useDelayedProcessWithProgress.ts`**

```typescript
import { ref, type Ref } from 'vue';
import { api } from '@/shared/api/http';
import type { DelayedProcessConfig, StatusResponsePayload } from '@/shared/lib/delayed-process';
import { DelayedProcessError, pollUntilDone, resolveConfig } from '@/shared/lib/delayed-process';

interface UseDelayedProcessProgressReturn<T> {
  data: Ref<T | null>;
  error: Ref<string | null>;
  isLoading: Ref<boolean>;
  progress: Ref<number>;
  execute: (payload?: Record<string, unknown>) => Promise<T | null>;
}

export function useDelayedProcessWithProgress<T = unknown>(
  url: string,
  configOverrides?: Partial<DelayedProcessConfig>,
): UseDelayedProcessProgressReturn<T> {
  const data = ref<T | null>(null) as Ref<T | null>;
  const error = ref<string | null>(null);
  const isLoading = ref(false);
  const progress = ref(0);

  async function execute(payload?: Record<string, unknown>): Promise<T | null> {
    isLoading.value = true;
    error.value = null;
    data.value = null;
    progress.value = 0;

    try {
      const response = await api.post(url, payload);
      const responseData = response.data as Record<string, unknown>;

      // Prüfe, ob dies eine verzögerte Antwort war
      const delayedPayload = responseData?.payload as Record<string, unknown> | undefined;
      const delayed = delayedPayload?.delayed as { uuid: string } | undefined;

      if (delayed?.uuid) {
        // Manuelles Polling mit Fortschritts-Tracking
        const config = resolveConfig({
          ...configOverrides,
          onPoll: async (uuid: string) => {
            try {
              const statusResp = await api.get('/api/common/delayed-process/status', {
                params: { uuid },
              });
              const statusPayload = statusResp.data?.payload as StatusResponsePayload | undefined;

              if (statusPayload?.progress !== undefined) {
                progress.value = statusPayload.progress;
              }
            } catch {
              // Ignoriere Fehler beim Abrufen des Fortschritts
            }
          },
        });

        const result = await pollUntilDone(delayed.uuid, config);
        progress.value = 100;
        data.value = result as T;

        return result as T;
      }

      // Keine verzögerte Antwort
      data.value = (responseData?.payload ?? responseData) as T;

      return data.value;
    } catch (err: unknown) {
      if (err instanceof DelayedProcessError) {
        error.value = err.errorMessage ?? `Process ${err.status}`;
      } else if (err instanceof Error) {
        error.value = err.message;
      }

      return null;
    } finally {
      isLoading.value = false;
    }
  }

  return { data, error, isLoading, progress, execute };
}
```

**Progress-Bar-Komponente:**

```vue
<script setup lang="ts">
import { useDelayedProcessWithProgress } from '@/shared/composables/useDelayedProcessWithProgress';

const { data, isLoading, progress, error, execute } = useDelayedProcessWithProgress<{
  url: string;
}>('/exports/generate');
</script>

<template>
  <div>
    <button :disabled="isLoading" @click="execute({ format: 'csv' })">Export</button>

    <div v-if="isLoading" class="progress-bar">
      <div class="progress-fill" :style="{ width: `${progress}%` }" />
      <span>{{ progress }}%</span>
    </div>

    <div v-if="error" class="error">{{ error }}</div>
    <a v-if="data" :href="data.url">Download</a>
  </div>
</template>

<style scoped>
.progress-bar {
  width: 100%;
  height: 24px;
  background: #e5e7eb;
  border-radius: 4px;
  position: relative;
  overflow: hidden;
}
.progress-fill {
  height: 100%;
  background: #3b82f6;
  transition: width 0.3s ease;
}
.progress-bar span {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 600;
}
</style>
```

### Vue Fehlerbehandlung

Globaler Error Handler für Delayed-Process-Fehler:

**`src/shared/api/http.ts`** (Error-Interceptor hinzufügen)

```typescript
import { DelayedProcessError } from '@/shared/lib/delayed-process';

api.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    if (error instanceof DelayedProcessError) {
      // Behandle spezifische Status
      switch (error.status) {
        case 'expired':
          console.warn(`Process ${error.uuid} expired`);
          break;
        case 'cancelled':
          console.warn(`Process ${error.uuid} was cancelled`);
          break;
        case 'error':
          console.error(`Process ${error.uuid} failed: ${error.errorMessage ?? 'unknown'}`);
          break;
      }
    }

    return Promise.reject(error);
  },
);
```

### Vue Batch-Operationen

Für Operationen, die mehrere verzögerte Prozesse erstellen:

```vue
<script setup lang="ts">
import { ref } from 'vue';
import { BatchPoller } from '@/shared/lib/delayed-process';
import { api } from '@/shared/api/http';

const results = ref<unknown[]>([]);
const isLoading = ref(false);

async function exportAll(ids: number[]): Promise<void> {
  isLoading.value = true;

  try {
    // Alle Prozesse erstellen
    const responses = await Promise.all(
      ids.map((id) => api.post('/exports/generate', { id })),
    );

    // UUIDs extrahieren
    const uuids = responses.map(
      (r) => (r.data.payload.delayed as { uuid: string }).uuid,
    );

    // Batch-Polling
    const poller = new BatchPoller({
      batchStatusUrl: '/api/common/delayed-process/batch-status',
      pollingInterval: 3000,
      timeout: 600_000,
      maxAttempts: 200,
      headers: {},
    });

    results.value = await Promise.all(uuids.map((uuid) => poller.add(uuid)));
  } finally {
    isLoading.value = false;
  }
}
</script>
```

### Vue Vollständiges Beispiel

**`src/pages/ReportPage.vue`**

```vue
<script setup lang="ts">
import { ref } from 'vue';
import { useDelayedProcess } from '@/shared/composables/useDelayedProcess';

interface ReportResult {
  url: string;
  rows: number;
  generated_at: string;
}

const format = ref<'pdf' | 'csv' | 'xlsx'>('pdf');
const userId = ref(1);

const {
  data: report,
  error,
  isLoading,
  execute: generateReport,
} = useDelayedProcess<ReportResult>('/reports/generate');

async function onSubmit(): Promise<void> {
  await generateReport({
    user_id: userId.value,
    format: format.value,
  });
}
</script>

<template>
  <div class="report-page">
    <h1>Report Generator</h1>

    <form @submit.prevent="onSubmit">
      <label>
        Format:
        <select v-model="format">
          <option value="pdf">PDF</option>
          <option value="csv">CSV</option>
          <option value="xlsx">Excel</option>
        </select>
      </label>

      <label>
        User ID:
        <input v-model.number="userId" type="number" min="1" />
      </label>

      <button type="submit" :disabled="isLoading">
        {{ isLoading ? 'Generating...' : 'Generate Report' }}
      </button>
    </form>

    <div v-if="error" class="error-message">
      {{ error }}
    </div>

    <div v-if="report" class="report-result">
      <p>Report generated: {{ report.rows }} rows</p>
      <a :href="report.url" target="_blank">Download Report</a>
      <small>Generated at {{ report.generated_at }}</small>
    </div>
  </div>
</template>
```

---

## React-Integration

### React Projekt-Setup

#### 1. Modul kopieren

Kopiere `resources/js/delayed-process/` in das `src/lib/delayed-process/` oder `src/shared/delayed-process/` Verzeichnis deines React-Projekts.

#### 2. Axios installieren

```bash
npm install axios
```

### React Axios-Instanz

**`src/lib/api.ts`**

```typescript
import axios from 'axios';
import { applyAxiosInterceptor } from '@/lib/delayed-process';

export const api = axios.create({
  baseURL: process.env.REACT_APP_API_URL ?? '/api',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
});

applyAxiosInterceptor(api, {
  statusUrl: '/api/common/delayed-process/status',
  pollingInterval: 2000,
  maxAttempts: 150,
  timeout: 600_000,
});
```

### Hook: useDelayedProcess

**`src/hooks/useDelayedProcess.ts`**

```typescript
import { useCallback, useRef, useState } from 'react';
import { api } from '@/lib/api';
import { DelayedProcessError } from '@/lib/delayed-process';

interface UseDelayedProcessReturn<T> {
  data: T | null;
  error: string | null;
  isLoading: boolean;
  execute: (payload?: Record<string, unknown>) => Promise<T | null>;
  reset: () => void;
}

export function useDelayedProcess<T = unknown>(
  url: string,
  method: 'get' | 'post' | 'put' | 'delete' = 'post',
): UseDelayedProcessReturn<T> {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const abortRef = useRef<AbortController | null>(null);

  const execute = useCallback(
    async (payload?: Record<string, unknown>): Promise<T | null> => {
      // Brich vorherige Anfrage ab
      abortRef.current?.abort();
      abortRef.current = new AbortController();

      setIsLoading(true);
      setError(null);
      setData(null);

      try {
        const response = await api.request({
          url,
          method,
          data: method !== 'get' ? payload : undefined,
          params: method === 'get' ? payload : undefined,
          signal: abortRef.current.signal,
        });

        const result = (response.data?.payload ?? response.data) as T;
        setData(result);

        return result;
      } catch (err: unknown) {
        if (err instanceof DelayedProcessError) {
          setError(err.errorMessage ?? `Process ${err.status}: ${err.uuid}`);
        } else if (err instanceof Error) {
          if (err.name !== 'CanceledError') {
            setError(err.message);
          }
        } else {
          setError('Unknown error');
        }

        return null;
      } finally {
        setIsLoading(false);
      }
    },
    [url, method],
  );

  const reset = useCallback(() => {
    abortRef.current?.abort();
    setData(null);
    setError(null);
    setIsLoading(false);
  }, []);

  return { data, error, isLoading, execute, reset };
}
```

**Verwendung:**

```tsx
import { useDelayedProcess } from '@/hooks/useDelayedProcess';

interface ReportResult {
  url: string;
  rows: number;
}

function ReportButton() {
  const { data, error, isLoading, execute } = useDelayedProcess<ReportResult>(
    '/reports/generate',
  );

  const handleClick = async () => {
    await execute({ user_id: 1, format: 'pdf' });
  };

  return (
    <div>
      <button onClick={handleClick} disabled={isLoading}>
        {isLoading ? 'Generating...' : 'Generate Report'}
      </button>
      {error && <p className="error">{error}</p>}
      {data && <a href={data.url}>Download ({data.rows} rows)</a>}
    </div>
  );
}
```

### React Progress-Komponente

**`src/hooks/useDelayedProcessWithProgress.ts`**

```typescript
import { useCallback, useRef, useState } from 'react';
import { api } from '@/lib/api';
import type { DelayedProcessConfig, StatusResponsePayload } from '@/lib/delayed-process';
import { DelayedProcessError, pollUntilDone, resolveConfig } from '@/lib/delayed-process';

interface UseProgressReturn<T> {
  data: T | null;
  error: string | null;
  isLoading: boolean;
  progress: number;
  execute: (payload?: Record<string, unknown>) => Promise<T | null>;
}

export function useDelayedProcessWithProgress<T = unknown>(
  url: string,
  configOverrides?: Partial<DelayedProcessConfig>,
): UseProgressReturn<T> {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [progress, setProgress] = useState(0);
  const progressRef = useRef(0);

  const execute = useCallback(
    async (payload?: Record<string, unknown>): Promise<T | null> => {
      setIsLoading(true);
      setError(null);
      setData(null);
      setProgress(0);
      progressRef.current = 0;

      try {
        const response = await api.post(url, payload);
        const responseData = response.data as Record<string, unknown>;
        const delayedPayload = responseData?.payload as Record<string, unknown> | undefined;
        const delayed = delayedPayload?.delayed as { uuid: string } | undefined;

        if (delayed?.uuid) {
          const config = resolveConfig({
            ...configOverrides,
            onPoll: async (uuid: string) => {
              try {
                const statusResp = await api.get('/api/common/delayed-process/status', {
                  params: { uuid },
                });
                const p = (statusResp.data?.payload as StatusResponsePayload)?.progress;

                if (typeof p === 'number' && p > progressRef.current) {
                  progressRef.current = p;
                  setProgress(p);
                }
              } catch {
                // Ignorieren
              }
            },
          });

          const result = (await pollUntilDone(delayed.uuid, config)) as T;
          setProgress(100);
          setData(result);

          return result;
        }

        const result = (responseData?.payload ?? responseData) as T;
        setData(result);

        return result;
      } catch (err: unknown) {
        if (err instanceof DelayedProcessError) {
          setError(err.errorMessage ?? `Process ${err.status}`);
        } else if (err instanceof Error) {
          setError(err.message);
        }

        return null;
      } finally {
        setIsLoading(false);
      }
    },
    [url, configOverrides],
  );

  return { data, error, isLoading, progress, execute };
}
```

**Progress-Bar-Komponente:**

```tsx
interface ProgressBarProps {
  progress: number;
}

function ProgressBar({ progress }: ProgressBarProps) {
  return (
    <div style={{
      width: '100%',
      height: 24,
      background: '#e5e7eb',
      borderRadius: 4,
      position: 'relative',
      overflow: 'hidden',
    }}>
      <div style={{
        height: '100%',
        width: `${progress}%`,
        background: '#3b82f6',
        transition: 'width 0.3s ease',
      }} />
      <span style={{
        position: 'absolute',
        inset: 0,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: 12,
        fontWeight: 600,
      }}>
        {progress}%
      </span>
    </div>
  );
}

// Verwendung:
function ExportPage() {
  const { data, isLoading, progress, error, execute } =
    useDelayedProcessWithProgress<{ url: string }>('/exports/generate');

  return (
    <div>
      <button onClick={() => execute({ format: 'csv' })} disabled={isLoading}>
        Export
      </button>
      {isLoading && <ProgressBar progress={progress} />}
      {error && <p className="error">{error}</p>}
      {data && <a href={data.url}>Download</a>}
    </div>
  );
}
```

### React Fehlerbehandlung

Erstelle einen Error Boundary für Delayed-Process-Fehler:

**`src/components/DelayedProcessErrorBoundary.tsx`**

```tsx
import { Component, type ErrorInfo, type ReactNode } from 'react';
import { DelayedProcessError } from '@/lib/delayed-process';

interface Props {
  children: ReactNode;
  fallback?: (error: DelayedProcessError) => ReactNode;
}

interface State {
  error: DelayedProcessError | null;
}

export class DelayedProcessErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State | null {
    if (error instanceof DelayedProcessError) {
      return { error };
    }

    return null;
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    if (error instanceof DelayedProcessError) {
      console.error('[DelayedProcess]', error.uuid, error.status, error.errorMessage);
    }
  }

  render(): ReactNode {
    if (this.state.error) {
      if (this.props.fallback) {
        return this.props.fallback(this.state.error);
      }

      return (
        <div className="delayed-process-error">
          <h3>Operation Failed</h3>
          <p>Status: {this.state.error.status}</p>
          {this.state.error.errorMessage && <p>{this.state.error.errorMessage}</p>}
        </div>
      );
    }

    return this.props.children;
  }
}
```

### React Batch-Operationen

```tsx
import { useState } from 'react';
import { BatchPoller } from '@/lib/delayed-process';
import { api } from '@/lib/api';

function BulkExport({ ids }: { ids: number[] }) {
  const [results, setResults] = useState<unknown[]>([]);
  const [isLoading, setIsLoading] = useState(false);

  const exportAll = async () => {
    setIsLoading(true);

    try {
      // Alle Prozesse erstellen
      const responses = await Promise.all(
        ids.map((id) => api.post('/exports/generate', { id })),
      );

      const uuids = responses.map(
        (r) => (r.data.payload.delayed as { uuid: string }).uuid,
      );

      // Batch-Polling für alle auf einmal
      const poller = new BatchPoller({
        batchStatusUrl: '/api/common/delayed-process/batch-status',
        pollingInterval: 3000,
        timeout: 600_000,
        maxAttempts: 200,
        headers: {},
      });

      const results = await Promise.all(uuids.map((uuid) => poller.add(uuid)));
      setResults(results);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div>
      <button onClick={exportAll} disabled={isLoading}>
        Export All ({ids.length})
      </button>
      {results.length > 0 && <p>Completed: {results.length} exports</p>}
    </div>
  );
}
```

### React Vollständiges Beispiel

**`src/pages/ExportPage.tsx`**

```tsx
import { useState } from 'react';
import { useDelayedProcess } from '@/hooks/useDelayedProcess';

interface ExportResult {
  url: string;
  rows: number;
  format: string;
}

export function ExportPage() {
  const [format, setFormat] = useState<'csv' | 'xlsx' | 'pdf'>('csv');
  const [dateRange, setDateRange] = useState({ from: '', to: '' });

  const { data, error, isLoading, execute, reset } = useDelayedProcess<ExportResult>(
    '/exports/generate',
  );

  const handleExport = async () => {
    await execute({
      format,
      date_from: dateRange.from,
      date_to: dateRange.to,
    });
  };

  return (
    <div className="export-page">
      <h1>Data Export</h1>

      <div className="form">
        <label>
          Format:
          <select value={format} onChange={(e) => setFormat(e.target.value as typeof format)}>
            <option value="csv">CSV</option>
            <option value="xlsx">Excel</option>
            <option value="pdf">PDF</option>
          </select>
        </label>

        <label>
          From:
          <input
            type="date"
            value={dateRange.from}
            onChange={(e) => setDateRange((prev) => ({ ...prev, from: e.target.value }))}
          />
        </label>

        <label>
          To:
          <input
            type="date"
            value={dateRange.to}
            onChange={(e) => setDateRange((prev) => ({ ...prev, to: e.target.value }))}
          />
        </label>

        <button onClick={handleExport} disabled={isLoading}>
          {isLoading ? 'Exporting...' : 'Export Data'}
        </button>
      </div>

      {error && (
        <div className="error">
          <p>{error}</p>
          <button onClick={reset}>Dismiss</button>
        </div>
      )}

      {data && (
        <div className="result">
          <p>Export complete: {data.rows} rows in {data.format.toUpperCase()}</p>
          <a href={data.url} download>Download File</a>
        </div>
      )}
    </div>
  );
}
```

---

## Fortgeschrittene Themen

### Mehrere API-Instanzen

Wenn verschiedene API-Endpoints unterschiedliche Status-URLs verwenden:

```typescript
import axios from 'axios';
import { applyAxiosInterceptor } from '@/lib/delayed-process';

// Haupt-API
const mainApi = axios.create({ baseURL: '/api/v1' });
applyAxiosInterceptor(mainApi, {
  statusUrl: '/api/v1/delayed-process/status',
});

// Admin-API mit längerem Timeout
const adminApi = axios.create({ baseURL: '/api/admin' });
applyAxiosInterceptor(adminApi, {
  statusUrl: '/api/admin/delayed-process/status',
  timeout: 900_000,  // 15 min
  pollingInterval: 5000,
});
```

### SSR-Überlegungen

Die Interceptoren basieren auf Browser-APIs (`window.fetch`, `XMLHttpRequest`, DOM für CSRF). In SSR-Umgebungen (Nuxt, Next.js):

**Nuxt 3:**

```typescript
// plugins/delayed-process.client.ts
import { applyAxiosInterceptor } from '@/lib/delayed-process';

export default defineNuxtPlugin(() => {
  const { $api } = useNuxtApp();
  applyAxiosInterceptor($api, { statusUrl: '/api/common/delayed-process/status' });
});
```

**Next.js:**

```typescript
// Nur in Client-Komponenten anwenden
'use client';

import { useEffect } from 'react';
import { api } from '@/lib/api';
import { applyAxiosInterceptor } from '@/lib/delayed-process';

export function DelayedProcessProvider({ children }: { children: React.ReactNode }) {
  useEffect(() => {
    const id = applyAxiosInterceptor(api, {
      statusUrl: '/api/common/delayed-process/status',
    });

    return () => {
      api.interceptors.response.eject(id);
    };
  }, []);

  return <>{children}</>;
}
```

### Tests

#### Vue (Vitest)

```typescript
import { vi } from 'vitest';
import { useDelayedProcess } from '@/shared/composables/useDelayedProcess';

vi.mock('@/shared/api/http', () => ({
  api: {
    request: vi.fn().mockResolvedValue({
      data: { payload: { url: '/test.pdf', rows: 100 } },
    }),
  },
}));

it('returns resolved data', async () => {
  const { data, execute } = useDelayedProcess<{ url: string }>('/reports/generate');
  await execute({ user_id: 1 });
  expect(data.value?.url).toBe('/test.pdf');
});
```

#### React (Jest / React Testing Library)

```typescript
import { renderHook, act } from '@testing-library/react';
import { useDelayedProcess } from '@/hooks/useDelayedProcess';

jest.mock('@/lib/api', () => ({
  api: {
    request: jest.fn().mockResolvedValue({
      data: { payload: { url: '/test.pdf', rows: 100 } },
    }),
  },
}));

it('returns resolved data', async () => {
  const { result } = renderHook(() =>
    useDelayedProcess<{ url: string }>('/reports/generate'),
  );

  await act(async () => {
    await result.current.execute({ user_id: 1 });
  });

  expect(result.current.data?.url).toBe('/test.pdf');
});
```

### TypeScript-Typen

Alle Typen werden vom Haupt-Entry-Point exportiert:

```typescript
import type {
  ProcessStatus,           // 'new' | 'wait' | 'done' | 'error' | 'expired' | 'cancelled'
  DelayedProcessConfig,    // Konfiguration für Interceptoren
  StatusResponsePayload,   // Shape der Status-Endpoint-Antwort
  StatusResponse,          // Wrapper mit Success-Flag
  DelayedPayload,          // Shape der anfänglichen verzögerten Antwort
  DelayedApiResponse,      // Vollständige API-Antwort mit verzögerter Payload
  BatchStatusResponse,     // Batch-Status-Endpoint-Antwort
  BatchPollerConfig,       // Konfiguration für BatchPoller
} from '@/lib/delayed-process';

import {
  applyAxiosInterceptor,  // Axios-Interceptor
  patchFetch,              // Fetch Monkey-Patch
  patchXHR,               // XHR Monkey-Patch
  BatchPoller,             // Batch-Polling-Klasse
  pollUntilDone,           // Manuelle Polling-Funktion
  resolveConfig,           // Config Resolver mit CSRF
  DEFAULT_CONFIG,          // Standardkonfigurationswerte
  POLL_HEADER,             // 'X-Delayed-Process-Poll'
  DelayedProcessError,     // Error-Klasse mit uuid/status
} from '@/lib/delayed-process';
```
