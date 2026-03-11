# Руководство по интеграции Frontend-перехватчиков

**Язык:** [English](frontend-interceptors-guide.md) | Русский | [Deutsch](frontend-interceptors-guide.de.md) | [中文](frontend-interceptors-guide.zh.md) | Назад к [README](README.ru.md)

Подробное руководство по интеграции frontend-перехватчиков `laravel-delayed-process` в приложения **Vue.js 3** и **React**.

---

## Оглавление

- [Обзор](#обзор)
- [Как работают перехватчики](#как-работают-перехватчики)
- [Доступные перехватчики](#доступные-перехватчики)
- [Интеграция Vue.js 3](#интеграция-vuejs-3)
  - [Настройка проекта](#настройка-проекта-vue)
  - [Axios плагин](#axios-плагин-vue)
  - [Composable: useDelayedProcess](#composable-usedelayedprocess)
  - [Компонент прогресса](#компонент-прогресса-vue)
  - [Обработка ошибок](#обработка-ошибок-vue)
  - [Массовые операции](#массовые-операции-vue)
  - [Полный пример: страница отчётов](#полный-пример-vue)
- [Интеграция React](#интеграция-react)
  - [Настройка проекта](#настройка-проекта-react)
  - [Экземпляр Axios](#экземпляр-axios-react)
  - [Hook: useDelayedProcess](#hook-usedelayedprocess)
  - [Компонент прогресса](#компонент-прогресса-react)
  - [Обработка ошибок](#обработка-ошибок-react)
  - [Массовые операции](#массовые-операции-react)
  - [Полный пример: страница экспорта](#полный-пример-react)
- [Продвинутые темы](#продвинутые-темы)
  - [Несколько API-экземпляров](#несколько-api-экземпляров)
  - [Соображения SSR](#соображения-ssr)
  - [Тестирование](#тестирование)
  - [TypeScript типы](#typescript-типы)

---

## Обзор

Модуль `delayed-process` для frontend'а прозрачно перехватывает API-ответы, содержащие UUID отложенного процесса, опрашивает статус-endpoint до завершения и возвращает окончательный результат так, как если бы операция была синхронной.

**Поддерживаемые перехватчики:**

| Перехватчик | HTTP-клиент | Лучше всего для |
|-------------|-------------|-----------------|
| `applyAxiosInterceptor()` | Axios | Vue.js, React, любой фреймворк с Axios |
| `patchFetch()` | native `fetch` | React (SWR, React Query), Next.js |
| `patchXHR()` | XMLHttpRequest | Устаревший код, jQuery AJAX |
| `BatchPoller` | native `fetch` | Массовые операции с несколькими UUID |

**Рекомендуемый подход:** используй `applyAxiosInterceptor()` с Axios для обоих проектов Vue.js и React.

---

## Как работают перехватчики

```
1. Клиент отправляет POST /api/reports/generate
2. Сервер возвращает: { success: true, payload: { delayed: { uuid: "abc-123" } } }
3. Перехватчик обнаруживает payload "delayed"
4. Перехватчик начинает опрос: GET /api/common/delayed-process/status?uuid=abc-123
5. Ответ опроса: { success: true, payload: { uuid: "abc-123", status: "wait", progress: 45 } }
6. ... продолжает опрос каждые N мс ...
7. Ответ опроса: { success: true, payload: { uuid: "abc-123", status: "done", data: { url: "..." } } }
8. Перехватчик заменяет исходный ответ на окончательные данные
9. Клиент получает результат так, как если бы запрос завершился нормально
```

**Терминальные статусы, которые останавливают опрос:**
- `done` — успешно, возвращает `data`
- `error` — выбрасывает `DelayedProcessError`
- `expired` — выбрасывает `DelayedProcessError`
- `cancelled` — выбрасывает `DelayedProcessError`

---

## Доступные перехватчики

### Параметры конфигурации

Все перехватчики принимают одну и ту же `DelayedProcessConfig`:

```typescript
interface DelayedProcessConfig {
  statusUrl: string;           // По умолчанию: '/api/common/delayed-process/status'
  pollingInterval: number;     // По умолчанию: 3000 (мс)
  maxAttempts: number;         // По умолчанию: 100
  timeout: number;             // По умолчанию: 300_000 (5 мин)
  headers: Record<string, string>;
  onPoll?: (uuid: string, attempt: number) => void;
}
```

CSRF-токен из `<meta name="csrf-token">` включается автоматически.

---

## Интеграция Vue.js 3

### Настройка проекта Vue

#### 1. Копирование модуля

Скопируй `resources/js/delayed-process/` в каталог `src/shared/lib/delayed-process/` (следуя архитектуре FSD) или `src/lib/delayed-process/` твоего Vue-проекта.

#### 2. Установка Axios (если ещё не установлен)

```bash
npm install axios
```

### Axios плагин Vue

Создай централизованный экземпляр Axios с перехватчиком отложенного процесса:

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

// Примени перехватчик отложенного процесса
applyAxiosInterceptor(api, {
  statusUrl: '/api/common/delayed-process/status',
  pollingInterval: 2000,
  maxAttempts: 150,
  timeout: 600_000,  // 10 мин для тяжёлых операций
});

export { api };
```

**`src/app/plugins/api.ts`** (Vue плагин, опционально)

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

Реактивный composable, отслеживающий состояние вызова отложенного процесса:

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

      // Перехватчик уже разрешил отложенный процесс
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

**Использование в компоненте:**

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

### Компонент прогресса Vue

Отслеживай прогресс во время опроса с помощью обратного вызова `onPoll`:

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

      // Проверь, был ли это отложенный ответ
      const delayedPayload = responseData?.payload as Record<string, unknown> | undefined;
      const delayed = delayedPayload?.delayed as { uuid: string } | undefined;

      if (delayed?.uuid) {
        // Опрашивай вручную с отслеживанием прогресса
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
              // Игнорируй ошибки получения прогресса
            }
          },
        });

        const result = await pollUntilDone(delayed.uuid, config);
        progress.value = 100;
        data.value = result as T;

        return result as T;
      }

      // Не отложенный ответ
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

**Компонент полосы прогресса:**

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

### Обработка ошибок Vue

Глобальный обработчик ошибок для ошибок отложенного процесса:

**`src/shared/api/http.ts`** (добавь перехватчик ошибок)

```typescript
import { DelayedProcessError } from '@/shared/lib/delayed-process';

api.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    if (error instanceof DelayedProcessError) {
      // Обработай специфичные статусы
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

### Массовые операции Vue

Для операций, которые создают несколько отложенных процессов:

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
    // Создай все процессы
    const responses = await Promise.all(
      ids.map((id) => api.post('/exports/generate', { id })),
    );

    // Извлеки UUID
    const uuids = responses.map(
      (r) => (r.data.payload.delayed as { uuid: string }).uuid,
    );

    // Массовый опрос
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

### Полный пример Vue

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

## Интеграция React

### Настройка проекта React

#### 1. Копирование модуля

Скопируй `resources/js/delayed-process/` в каталог `src/lib/delayed-process/` или `src/shared/delayed-process/` твоего React-проекта.

#### 2. Установка Axios

```bash
npm install axios
```

### Экземпляр Axios React

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
      // Отмени предыдущий запрос
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

**Использование:**

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

### Компонент прогресса React

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
                // Игнорируй ошибки
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

**Компонент полосы прогресса:**

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

// Использование:
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

### Обработка ошибок React

Создай Error Boundary для ошибок отложенного процесса:

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

### Массовые операции React

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
      // Создай все процессы
      const responses = await Promise.all(
        ids.map((id) => api.post('/exports/generate', { id })),
      );

      const uuids = responses.map(
        (r) => (r.data.payload.delayed as { uuid: string }).uuid,
      );

      // Массовый опрос всех одновременно
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

### Полный пример React

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

## Продвинутые темы

### Несколько API-экземпляров

Когда разные API-endpoints используют разные URL статусов:

```typescript
import axios from 'axios';
import { applyAxiosInterceptor } from '@/lib/delayed-process';

// Основной API
const mainApi = axios.create({ baseURL: '/api/v1' });
applyAxiosInterceptor(mainApi, {
  statusUrl: '/api/v1/delayed-process/status',
});

// Admin API с более длительным таймаутом
const adminApi = axios.create({ baseURL: '/api/admin' });
applyAxiosInterceptor(adminApi, {
  statusUrl: '/api/admin/delayed-process/status',
  timeout: 900_000,  // 15 мин
  pollingInterval: 5000,
});
```

### Соображения SSR

Перехватчики полагаются на browser APIs (`window.fetch`, `XMLHttpRequest`, DOM для CSRF). В SSR-окружениях (Nuxt, Next.js):

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
// Применяй только в клиентских компонентах
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

### Тестирование

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

### TypeScript типы

Все типы экспортируются из главной точки входа:

```typescript
import type {
  ProcessStatus,           // 'new' | 'wait' | 'done' | 'error' | 'expired' | 'cancelled'
  DelayedProcessConfig,    // Конфигурация для перехватчиков
  StatusResponsePayload,   // Форма ответа endpoint'а статуса
  StatusResponse,          // Обёртка с флагом успеха
  DelayedPayload,          // Форма начального отложенного ответа
  DelayedApiResponse,      // Полный API-ответ с отложенным payload'ом
  BatchStatusResponse,     // Ответ endpoint'а массового статуса
  BatchPollerConfig,       // Конфигурация для BatchPoller
} from '@/lib/delayed-process';

import {
  applyAxiosInterceptor,  // Axios перехватчик
  patchFetch,              // Monkey-patch для Fetch
  patchXHR,               // Monkey-patch для XHR
  BatchPoller,             // Класс массового опроса
  pollUntilDone,           // Функция ручного опроса
  resolveConfig,           // Резолвер конфигурации с CSRF
  DEFAULT_CONFIG,          // Значения конфигурации по умолчанию
  POLL_HEADER,             // 'X-Delayed-Process-Poll'
  DelayedProcessError,     // Класс ошибки с uuid/status
} from '@/lib/delayed-process';
```
