import type { BatchPollerConfig, StatusResponsePayload } from '../types';
import { DelayedProcessError } from '../types';

const TERMINAL_STATUSES = new Set(['done', 'error', 'expired', 'cancelled']);

interface PendingProcess {
  uuid: string;
  resolve: (data: unknown) => void;
  reject: (error: Error) => void;
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

export class BatchPoller {
  private readonly config: BatchPollerConfig;
  private readonly pending: Map<string, PendingProcess> = new Map();
  private polling = false;

  constructor(config: BatchPollerConfig) {
    this.config = config;
  }

  add(uuid: string): Promise<unknown> {
    return new Promise<unknown>((resolve, reject) => {
      this.pending.set(uuid, { uuid, resolve, reject });

      if (!this.polling) {
        void this.startPolling();
      }
    });
  }

  private async startPolling(): Promise<void> {
    this.polling = true;
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), this.config.timeout);

    try {
      for (let attempt = 1; attempt <= this.config.maxAttempts; attempt++) {
        if (this.pending.size === 0) {
          break;
        }

        const uuids = Array.from(this.pending.keys());
        const params = new URLSearchParams();

        for (const uuid of uuids) {
          params.append('uuids[]', uuid);
        }

        const response = await fetch(`${this.config.batchStatusUrl}?${params.toString()}`, {
          method: 'GET',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            ...this.config.headers,
          },
          signal: controller.signal,
        });

        if (!response.ok) {
          const error = new Error(`Batch status endpoint returned HTTP ${String(response.status)}`);

          for (const entry of this.pending.values()) {
            entry.reject(error);
          }

          this.pending.clear();

          break;
        }

        const json = (await response.json()) as { success: boolean; payload: StatusResponsePayload[] };

        if (json.success && Array.isArray(json.payload)) {
          for (const item of json.payload) {
            const entry = this.pending.get(item.uuid);

            if (!entry) {
              continue;
            }

            if (item.status === 'done') {
              entry.resolve(item.data);
              this.pending.delete(item.uuid);
            } else if (TERMINAL_STATUSES.has(item.status) && item.status !== 'done') {
              entry.reject(new DelayedProcessError(item.uuid, item.status, item.error_message ?? null));
              this.pending.delete(item.uuid);
            }
          }
        }

        if (this.pending.size === 0) {
          break;
        }

        if (attempt < this.config.maxAttempts) {
          await sleep(this.config.pollingInterval);
        }
      }

      for (const entry of this.pending.values()) {
        entry.reject(new DelayedProcessError(entry.uuid, 'wait', `Max attempts (${String(this.config.maxAttempts)}) exceeded`));
      }

      this.pending.clear();
    } finally {
      clearTimeout(timeoutId);
      this.polling = false;
    }
  }
}
