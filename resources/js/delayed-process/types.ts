export type ProcessStatus = 'new' | 'wait' | 'done' | 'error' | 'expired' | 'cancelled';

export interface DelayedProcessConfig {
  /** The URL of the status endpoint, e.g. '/api/common/delayed-process/status' */
  statusUrl: string;
  /** The polling interval, ms (default: 3000) */
  pollingInterval: number;
  /** The maximum number of polls (default: 100) */
  maxAttempts: number;
  /** The overall timeout, ms (default: 300_000 = 5 minutes) */
  timeout: number;
  /** Extra headers (CSRF and the like) */
  headers: Record<string, string>;
  /** A callback fired on every poll */
  onPoll?: (uuid: string, attempt: number) => void;
}

export interface StatusResponsePayload {
  uuid: string;
  status: ProcessStatus;
  data?: unknown;
  error_message?: string;
  is_error_truncated?: boolean;
  progress: number;
  started_at?: string;
  duration_ms?: number;
  attempts: number;
  current_try: number;
}

export interface StatusResponse {
  success: boolean;
  payload: StatusResponsePayload;
}

export interface DelayedPayload {
  uuid: string;
  status: ProcessStatus;
}

export interface DelayedApiResponse {
  success: boolean;
  payload: {
    delayed: DelayedPayload;
    [key: string]: unknown;
  };
}

export interface BatchStatusResponse {
  success: boolean;
  payload: StatusResponsePayload[];
}

export interface BatchPollerConfig {
  /** The URL for the batch status request */
  batchStatusUrl: string;
  /** The polling interval, ms (default: 3000) */
  pollingInterval: number;
  /** The overall timeout, ms (default: 300_000) */
  timeout: number;
  /** The maximum number of polls (default: 100) */
  maxAttempts: number;
  /** Extra headers */
  headers: Record<string, string>;
}

export class DelayedProcessError extends Error {
  readonly uuid: string;
  readonly status: ProcessStatus;
  readonly errorMessage: string | null;

  constructor(uuid: string, status: ProcessStatus, errorMessage: string | null) {
    super(errorMessage ?? `Delayed process ${uuid} failed with status "${status}"`);
    this.name = 'DelayedProcessError';
    this.uuid = uuid;
    this.status = status;
    this.errorMessage = errorMessage;
  }
}
