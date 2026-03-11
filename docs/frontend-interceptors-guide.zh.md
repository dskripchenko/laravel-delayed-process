# 前端拦截器集成指南

**语言:** [English](frontend-interceptors-guide.md) | [Русский](frontend-interceptors-guide.ru.md) | [Deutsch](frontend-interceptors-guide.de.md) | 中文 | 返回 [README](README.zh.md)

详细指南，用于将 `laravel-delayed-process` 前端拦截器集成到 **Vue.js 3** 和 **React** 应用程序中。

---

## 目录

- [概述](#概述)
- [拦截器如何工作](#拦截器如何工作)
- [可用的拦截器](#可用的拦截器)
- [Vue.js 3 集成](#vuejs-3-集成)
  - [项目设置](#vue-项目设置)
  - [Axios 插件](#vue-axios-插件)
  - [组合式：useDelayedProcess](#组合式usedelayedprocess)
  - [进度组件](#vue-进度组件)
  - [错误处理](#vue-错误处理)
  - [批量操作](#vue-批量操作)
  - [完整示例：报告页面](#vue-完整示例)
- [React 集成](#react-集成)
  - [项目设置](#react-项目设置)
  - [Axios 实例](#react-axios-实例)
  - [Hook：useDelayedProcess](#hookusedelayedprocess)
  - [进度组件](#react-进度组件)
  - [错误处理](#react-错误处理)
  - [批量操作](#react-批量操作)
  - [完整示例：导出页面](#react-完整示例)
- [高级主题](#高级主题)
  - [多个 API 实例](#多个-api-实例)
  - [SSR 注意事项](#ssr-注意事项)
  - [测试](#测试)
  - [TypeScript 类型](#typescript-类型)

---

## 概述

`delayed-process` 前端模块透明地拦截包含延迟过程 UUID 的 API 响应，轮询状态端点直到完成，并返回最终结果，就像操作是同步的一样。

**支持的拦截器：**

| 拦截器 | HTTP 客户端 | 最适用于 |
|--------|-----------|--------|
| `applyAxiosInterceptor()` | Axios | Vue.js、React、任何使用 Axios 的框架 |
| `patchFetch()` | 原生 `fetch` | React (SWR, React Query)、Next.js |
| `patchXHR()` | XMLHttpRequest | 旧版代码、jQuery AJAX |
| `BatchPoller` | 原生 `fetch` | 多个 UUID 的批量操作 |

**推荐方法：** 在 Vue.js 和 React 项目中都使用 Axios 的 `applyAxiosInterceptor()`。

---

## 拦截器如何工作

```
1. 客户端发送 POST /api/reports/generate
2. 服务器返回：{ success: true, payload: { delayed: { uuid: "abc-123" } } }
3. 拦截器检测到 "delayed" 负载
4. 拦截器开始轮询：GET /api/common/delayed-process/status?uuid=abc-123
5. 轮询响应：{ success: true, payload: { uuid: "abc-123", status: "wait", progress: 45 } }
6. ... 每隔 N 毫秒继续轮询 ...
7. 轮询响应：{ success: true, payload: { uuid: "abc-123", status: "done", data: { url: "..." } } }
8. 拦截器用最终数据替换原始响应
9. 客户端收到结果，就像请求正常完成一样
```

**停止轮询的终止状态：**
- `done` — 成功，返回 `data`
- `error` — 抛出 `DelayedProcessError`
- `expired` — 抛出 `DelayedProcessError`
- `cancelled` — 抛出 `DelayedProcessError`

---

## 可用的拦截器

### 配置选项

所有拦截器接受相同的 `DelayedProcessConfig`：

```typescript
interface DelayedProcessConfig {
  statusUrl: string;           // 默认：'/api/common/delayed-process/status'
  pollingInterval: number;     // 默认：3000 (ms)
  maxAttempts: number;         // 默认：100
  timeout: number;             // 默认：300_000 (5 分钟)
  headers: Record<string, string>;
  onPoll?: (uuid: string, attempt: number) => void;
}
```

CSRF 令牌自动从 `<meta name="csrf-token">` 中包含。

---

## Vue.js 3 集成

### Vue 项目设置

#### 1. 复制模块

将 `resources/js/delayed-process/` 复制到你的 Vue 项目的 `src/shared/lib/delayed-process/`（遵循 FSD 架构）或 `src/lib/delayed-process/`。

#### 2. 安装 Axios（如果还未安装）

```bash
npm install axios
```

### Vue Axios 插件

创建一个集中的 Axios 实例，带有延迟过程拦截器：

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

// 应用延迟过程拦截器
applyAxiosInterceptor(api, {
  statusUrl: '/api/common/delayed-process/status',
  pollingInterval: 2000,
  maxAttempts: 150,
  timeout: 600_000,  // 10 分钟用于重型操作
});

export { api };
```

**`src/app/plugins/api.ts`**（Vue 插件，可选）

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

### 组合式：useDelayedProcess

一个反应式组合式，跟踪延迟过程调用的状态：

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

      // 拦截器已经解决了延迟过程
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

**在组件中的使用：**

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

### Vue 进度组件

通过 `onPoll` 回调跟踪轮询期间的进度：

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

      // 检查这是否是延迟响应
      const delayedPayload = responseData?.payload as Record<string, unknown> | undefined;
      const delayed = delayedPayload?.delayed as { uuid: string } | undefined;

      if (delayed?.uuid) {
        // 手动轮询，跟踪进度
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
              // 忽略进度获取错误
            }
          },
        });

        const result = await pollUntilDone(delayed.uuid, config);
        progress.value = 100;
        data.value = result as T;

        return result as T;
      }

      // 不是延迟响应
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

**进度条组件：**

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

### Vue 错误处理

用于延迟过程错误的全局错误处理程序：

**`src/shared/api/http.ts`**（添加错误拦截器）

```typescript
import { DelayedProcessError } from '@/shared/lib/delayed-process';

api.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    if (error instanceof DelayedProcessError) {
      // 处理特定状态
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

### Vue 批量操作

对于创建多个延迟过程的操作：

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
    // 创建所有进程
    const responses = await Promise.all(
      ids.map((id) => api.post('/exports/generate', { id })),
    );

    // 提取 UUID
    const uuids = responses.map(
      (r) => (r.data.payload.delayed as { uuid: string }).uuid,
    );

    // 批量轮询
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

### Vue 完整示例

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

## React 集成

### React 项目设置

#### 1. 复制模块

将 `resources/js/delayed-process/` 复制到你的 React 项目的 `src/lib/delayed-process/` 或 `src/shared/delayed-process/`。

#### 2. 安装 Axios

```bash
npm install axios
```

### React Axios 实例

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

### Hook：useDelayedProcess

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
      // 取消之前的请求
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

**使用方法：**

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

### React 进度组件

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
                // 忽略
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

**进度条组件：**

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

// 使用方法：
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

### React 错误处理

为延迟过程错误创建错误边界：

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

### React 批量操作

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
      // 创建所有进程
      const responses = await Promise.all(
        ids.map((id) => api.post('/exports/generate', { id })),
      );

      const uuids = responses.map(
        (r) => (r.data.payload.delayed as { uuid: string }).uuid,
      );

      // 一次性批量轮询所有
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

### React 完整示例

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

## 高级主题

### 多个 API 实例

当不同的 API 端点使用不同的状态 URL 时：

```typescript
import axios from 'axios';
import { applyAxiosInterceptor } from '@/lib/delayed-process';

// 主 API
const mainApi = axios.create({ baseURL: '/api/v1' });
applyAxiosInterceptor(mainApi, {
  statusUrl: '/api/v1/delayed-process/status',
});

// 管理员 API，超时时间更长
const adminApi = axios.create({ baseURL: '/api/admin' });
applyAxiosInterceptor(adminApi, {
  statusUrl: '/api/admin/delayed-process/status',
  timeout: 900_000,  // 15 分钟
  pollingInterval: 5000,
});
```

### SSR 注意事项

拦截器依赖浏览器 API（`window.fetch`、`XMLHttpRequest`、用于 CSRF 的 DOM）。在 SSR 环境（Nuxt、Next.js）中：

**Nuxt 3：**

```typescript
// plugins/delayed-process.client.ts
import { applyAxiosInterceptor } from '@/lib/delayed-process';

export default defineNuxtPlugin(() => {
  const { $api } = useNuxtApp();
  applyAxiosInterceptor($api, { statusUrl: '/api/common/delayed-process/status' });
});
```

**Next.js：**

```typescript
// 仅在客户端组件中应用
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

### 测试

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

### TypeScript 类型

所有类型都从主入口点导出：

```typescript
import type {
  ProcessStatus,           // 'new' | 'wait' | 'done' | 'error' | 'expired' | 'cancelled'
  DelayedProcessConfig,    // 拦截器配置
  StatusResponsePayload,   // 状态端点响应形状
  StatusResponse,          // 带有成功标志的包装
  DelayedPayload,          // 初始延迟响应形状
  DelayedApiResponse,      // 带有延迟负载的完整 API 响应
  BatchStatusResponse,     // 批量状态端点响应
  BatchPollerConfig,       // BatchPoller 配置
} from '@/lib/delayed-process';

import {
  applyAxiosInterceptor,  // Axios 拦截器
  patchFetch,              // Fetch 猴子补丁
  patchXHR,               // XHR 猴子补丁
  BatchPoller,             // 批量轮询类
  pollUntilDone,           // 手动轮询函数
  resolveConfig,           // 配置解析器，带有 CSRF
  DEFAULT_CONFIG,          // 默认配置值
  POLL_HEADER,             // 'X-Delayed-Process-Poll'
  DelayedProcessError,     // 带有 uuid/status 的错误类
} from '@/lib/delayed-process';
```
