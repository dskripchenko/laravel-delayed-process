import type { DelayedProcessConfig } from '../types';
import { resolveConfig } from '../core/config';
import { POLL_HEADER, pollUntilDone } from '../core/poller';

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

function isPollRequest(input: RequestInfo | URL, init?: RequestInit): boolean {
  if (init?.headers) {
    const headers = new Headers(init.headers);

    if (headers.get(POLL_HEADER) === '1') {
      return true;
    }
  }

  if (input instanceof Request && input.headers.get(POLL_HEADER) === '1') {
    return true;
  }

  return false;
}

export function patchFetch(config?: Partial<DelayedProcessConfig>): () => void {
  const resolved = resolveConfig(config);
  const originalFetch = window.fetch;

  window.fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    if (isPollRequest(input, init)) {
      return originalFetch.call(window, input, init);
    }

    const response = await originalFetch.call(window, input, init);
    const contentType = response.headers.get('content-type');

    if (!contentType || !contentType.includes('application/json')) {
      return response;
    }

    const cloned = response.clone();
    let json: unknown;

    try {
      json = await cloned.json();
    } catch {
      return response;
    }

    const uuid = hasDelayedUuid(json);

    if (uuid === null) {
      return response;
    }

    const result = await pollUntilDone(uuid, resolved);

    const body = JSON.stringify({
      success: true,
      payload: result,
    });

    return new Response(body, {
      status: response.status,
      statusText: response.statusText,
      headers: response.headers,
    });
  };

  return (): void => {
    window.fetch = originalFetch;
  };
}
