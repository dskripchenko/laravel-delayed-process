import type { AxiosInstance, AxiosResponse } from 'axios';
import type { DelayedProcessConfig } from '../types';
import { resolveConfig } from '../core/config';
import { pollUntilDone } from '../core/poller';

function hasDelayedUuid(data: unknown): string | null {
  if (typeof data !== 'object' || data === null) {
    return null;
  }

  const obj = data as Record<string, unknown>;

  if (typeof obj['success'] !== 'boolean' || typeof obj['payload'] !== 'object' || obj['payload'] === null) {
    return null;
  }

  const payload = obj['payload'] as Record<string, unknown>;

  if (typeof payload['delayed'] !== 'object' || payload['delayed'] === null) {
    return null;
  }

  const delayed = payload['delayed'] as Record<string, unknown>;

  if (typeof delayed['uuid'] === 'string' && delayed['uuid'].length > 0) {
    return delayed['uuid'];
  }

  return null;
}

export function applyAxiosInterceptor(
  instance: AxiosInstance,
  config?: Partial<DelayedProcessConfig>,
): number {
  const resolved = resolveConfig(config);

  return instance.interceptors.response.use(
    async (response: AxiosResponse): Promise<AxiosResponse> => {
      const uuid = hasDelayedUuid(response.data);

      if (uuid === null) {
        return response;
      }

      const result = await pollUntilDone(uuid, resolved);

      response.data = {
        success: true,
        payload: result,
      };

      return response;
    },
  );
}
