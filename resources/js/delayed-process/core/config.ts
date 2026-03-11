import type { DelayedProcessConfig } from '../types';

export const DEFAULT_CONFIG: DelayedProcessConfig = {
  statusUrl: '/api/common/delayed-process/status',
  pollingInterval: 3000,
  maxAttempts: 100,
  timeout: 300_000,
  headers: {},
};

function getCsrfToken(): string | null {
  if (typeof document === 'undefined') {
    return null;
  }

  const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');

  return meta?.content ?? null;
}

export function resolveConfig(partial?: Partial<DelayedProcessConfig>): DelayedProcessConfig {
  const merged: DelayedProcessConfig = {
    ...DEFAULT_CONFIG,
    ...partial,
    headers: {
      ...DEFAULT_CONFIG.headers,
      ...partial?.headers,
    },
  };

  const csrf = getCsrfToken();

  if (csrf && !merged.headers['X-CSRF-TOKEN']) {
    merged.headers['X-CSRF-TOKEN'] = csrf;
  }

  return merged;
}
