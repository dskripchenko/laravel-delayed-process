import type { DelayedProcessConfig, StatusResponse } from '../types';
import { DelayedProcessError } from '../types';

export const POLL_HEADER = 'X-Delayed-Process-Poll';

const TERMINAL_ERROR_STATUSES = new Set(['error', 'expired', 'cancelled']);

function isStatusResponse(value: unknown): value is StatusResponse {
  if (typeof value !== 'object' || value === null) {
    return false;
  }

  const obj = value as Record<string, unknown>;

  if (typeof obj['success'] !== 'boolean' || typeof obj['payload'] !== 'object' || obj['payload'] === null) {
    return false;
  }

  const payload = obj['payload'] as Record<string, unknown>;

  return typeof payload['uuid'] === 'string' && typeof payload['status'] === 'string';
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

export async function pollUntilDone(uuid: string, config: DelayedProcessConfig): Promise<unknown> {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), config.timeout);

  try {
    for (let attempt = 1; attempt <= config.maxAttempts; attempt++) {
      config.onPoll?.(uuid, attempt);

      const url = `${config.statusUrl}?uuid=${encodeURIComponent(uuid)}`;

      const response = await fetch(url, {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          [POLL_HEADER]: '1',
          ...config.headers,
        },
        signal: controller.signal,
      });

      if (!response.ok) {
        throw new DelayedProcessError(uuid, 'error', `Status endpoint returned HTTP ${String(response.status)}`);
      }

      const json: unknown = await response.json();

      if (!isStatusResponse(json)) {
        throw new DelayedProcessError(uuid, 'error', 'Invalid status response format');
      }

      const { status, data, error_message } = json.payload;

      if (status === 'done') {
        return data;
      }

      if (TERMINAL_ERROR_STATUSES.has(status)) {
        throw new DelayedProcessError(uuid, status, error_message ?? null);
      }

      if (attempt < config.maxAttempts) {
        await sleep(config.pollingInterval);
      }
    }

    throw new DelayedProcessError(uuid, 'wait', `Max attempts (${String(config.maxAttempts)}) exceeded`);
  } finally {
    clearTimeout(timeoutId);
  }
}
