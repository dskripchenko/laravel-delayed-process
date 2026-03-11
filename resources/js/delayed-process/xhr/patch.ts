import type { DelayedProcessConfig } from '../types';
import { resolveConfig } from '../core/config';
import { POLL_HEADER, pollUntilDone } from '../core/poller';

let isPatched = false;

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

export function patchXHR(config?: Partial<DelayedProcessConfig>): () => void {
  if (isPatched) {
    console.warn('[delayed-process] XHR is already patched. Skipping duplicate patch.');

    return () => {};
  }

  isPatched = true;
  const resolved = resolveConfig(config);

  const OriginalXHR = window.XMLHttpRequest;
  const originalOpen = OriginalXHR.prototype.open;
  const originalSend = OriginalXHR.prototype.send;
  const originalSetRequestHeader = OriginalXHR.prototype.setRequestHeader;

  OriginalXHR.prototype.open = function (
    this: XMLHttpRequest,
    method: string,
    url: string | URL,
    async?: boolean,
    username?: string | null,
    password?: string | null,
  ): void {
    (this as unknown as Record<string, unknown>)['_dpHeaders'] = {};
    originalOpen.call(this, method, url, async ?? true, username ?? null, password ?? null);
  };

  OriginalXHR.prototype.setRequestHeader = function (
    this: XMLHttpRequest,
    name: string,
    value: string,
  ): void {
    const headers = (this as unknown as Record<string, unknown>)['_dpHeaders'] as Record<string, string> | undefined;

    if (headers) {
      headers[name] = value;
    }

    originalSetRequestHeader.call(this, name, value);
  };

  OriginalXHR.prototype.send = function (
    this: XMLHttpRequest,
    body?: Document | XMLHttpRequestBodyInit | null,
  ): void {
    const headers = (this as unknown as Record<string, unknown>)['_dpHeaders'] as Record<string, string> | undefined;

    if (headers?.[POLL_HEADER] === '1') {
      originalSend.call(this, body);

      return;
    }

    const xhr = this;
    const originalOnLoad = xhr.onload;
    const originalOnReadyStateChange = xhr.onreadystatechange;

    function handleResponse(originalHandler: ((ev: Event) => void) | null, ev: Event): void {
      if (xhr.readyState !== XMLHttpRequest.DONE) {
        originalHandler?.call(xhr, ev);

        return;
      }

      const contentType = xhr.getResponseHeader('content-type');

      if (!contentType || !contentType.includes('application/json')) {
        originalHandler?.call(xhr, ev);

        return;
      }

      let json: unknown;

      try {
        json = JSON.parse(xhr.responseText) as unknown;
      } catch {
        originalHandler?.call(xhr, ev);

        return;
      }

      const uuid = hasDelayedUuid(json);

      if (uuid === null) {
        originalHandler?.call(xhr, ev);

        return;
      }

      pollUntilDone(uuid, resolved)
        .then((result: unknown) => {
          const newBody = JSON.stringify({
            success: true,
            payload: result,
          });

          Object.defineProperty(xhr, 'responseText', { value: newBody, writable: false, configurable: true });
          Object.defineProperty(xhr, 'response', { value: newBody, writable: false, configurable: true });

          originalHandler?.call(xhr, ev);
        })
        .catch((error: unknown) => {
          const errorBody = JSON.stringify({
            success: false,
            payload: {
              errorKey: 'delayed_process_error',
              message: error instanceof Error ? error.message : 'Unknown polling error',
            },
          });

          Object.defineProperty(xhr, 'responseText', { value: errorBody, writable: false, configurable: true });
          Object.defineProperty(xhr, 'response', { value: errorBody, writable: false, configurable: true });

          originalHandler?.call(xhr, ev);
        });
    }

    xhr.onload = function (ev: Event): void {
      handleResponse(originalOnLoad, ev);
    };

    xhr.onreadystatechange = function (ev: Event): void {
      handleResponse(originalOnReadyStateChange, ev);
    };

    originalSend.call(this, body);
  };

  return (): void => {
    OriginalXHR.prototype.open = originalOpen;
    OriginalXHR.prototype.send = originalSend;
    OriginalXHR.prototype.setRequestHeader = originalSetRequestHeader;
    isPatched = false;
  };
}
