export type ProcessStatus = 'new' | 'wait' | 'done' | 'error' | 'expired' | 'cancelled';

export interface DelayedProcessConfig {
  /** URL статус-эндпоинта, напр. '/api/common/delayed-process/status' */
  statusUrl: string;
  /** Интервал поллинга, ms (default: 3000) */
  pollingInterval: number;
  /** Макс. попыток поллинга (default: 100) */
  maxAttempts: number;
  /** Общий таймаут, ms (default: 300_000 = 5 мин) */
  timeout: number;
  /** Доп. заголовки (CSRF и т.д.) */
  headers: Record<string, string>;
  /** Callback при каждом полле */
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
  /** URL для batch-запроса статусов */
  batchStatusUrl: string;
  /** Интервал поллинга, ms (default: 3000) */
  pollingInterval: number;
  /** Общий таймаут, ms (default: 300_000) */
  timeout: number;
  /** Макс. попыток поллинга (default: 100) */
  maxAttempts: number;
  /** Доп. заголовки */
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
