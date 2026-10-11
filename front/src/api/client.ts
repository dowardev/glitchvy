// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Type-safe API client for the glitchvy-laravel admin endpoints.
 *
 * Auth: every admin request carries `X-Glitchvy-User-Token`. The Laravel SDK
 * resolves that token against the consumer app's guard (Sanctum, session, …).
 */

import type {
  CursorPage,
  ErrorFilters,
  ErrorReportDetail,
  ErrorReportSummary,
  Status,
} from '../types.js';

const DEFAULT_BASE_URL = '/api/glitchvy';

let cachedBaseUrl: string | null = null;

export function setBaseUrl(url: string): void {
  /** Test helper / runtime override. */
  cachedBaseUrl = url;
}

function readBaseUrl(): string {
  if (cachedBaseUrl !== null) return cachedBaseUrl;
  const env = (import.meta as unknown as { env?: Record<string, string | undefined> }).env;
  const fromEnv = env?.VITE_GLITCHVY_API_URL;
  cachedBaseUrl = typeof fromEnv === 'string' && fromEnv.length > 0 ? fromEnv : DEFAULT_BASE_URL;
  return cachedBaseUrl;
}

function absoluteUrl(path: string): string {
  const base = readBaseUrl().replace(/\/$/, '');
  return `${base}/${path.replace(/^\//, '')}`;
}

export class GlitchvyApiError extends Error {
  public readonly status: number;
  public readonly code: string;

  constructor(status: number, code: string, message: string) {
    super(message);
    this.name = 'GlitchvyApiError';
    this.status = status;
    this.code = code;
  }
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  token?: string | null;
  body?: unknown;
  headers?: Record<string, string>;
}

async function call<T>(path: string, opts: RequestOptions = {}): Promise<T> {
  const { token = null, body, headers = {}, method = 'GET' } = opts;
  const finalHeaders: Record<string, string> = {
    Accept: 'application/json',
    ...headers,
  };
  if (body !== undefined && !finalHeaders['Content-Type']) {
    finalHeaders['Content-Type'] = 'application/json';
  }
  if (token) {
    finalHeaders['X-Glitchvy-User-Token'] = token;
  }

  const res = await fetch(absoluteUrl(path), {
    method,
    headers: finalHeaders,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  if (!res.ok) {
    const detail = await safeReadErrorBody(res);
    throw new GlitchvyApiError(
      res.status,
      detail.error ?? 'http_error',
      detail.message ?? res.statusText,
    );
  }

  // 204 No Content short-circuit.
  if (res.status === 204) return undefined as T;

  return (await res.json()) as T;
}

async function safeReadErrorBody(res: Response): Promise<{ error?: string; message?: string }> {
  try {
    const j = (await res.json()) as { error?: string; message?: string };
    return j;
  } catch {
    return { message: res.statusText };
  }
}

export const api = {
  async listErrors(
    filters: ErrorFilters,
    token: string | null,
  ): Promise<CursorPage<ErrorReportSummary>> {
    const params = new URLSearchParams();
    for (const [k, v] of Object.entries(filters)) {
      if (v !== undefined && v !== null && v !== '') {
        params.set(k, String(v));
      }
    }
    const qs = params.toString();
    return call<CursorPage<ErrorReportSummary>>(`errors${qs ? `?${qs}` : ''}`, {
      method: 'GET',
      token,
    });
  },

  async getError(id: number, token: string | null): Promise<ErrorReportDetail> {
    const wrapped = await call<{ data: ErrorReportDetail }>(`errors/${id}`, {
      method: 'GET',
      token,
    });
    return wrapped.data;
  },

  async updateStatus(
    id: number,
    status: Status,
    token: string | null,
  ): Promise<ErrorReportSummary> {
    const wrapped = await call<{ data: ErrorReportSummary }>(
      `errors/${id}/status`,
      { method: 'PATCH', body: { status }, token },
    );
    return wrapped.data;
  },

  /** Returns the URL the admin can drop into <img src=…> for a screenshot. */
  screenshotUrl(id: number): string {
    return absoluteUrl(`errors/${id}/screenshot`);
  },
};
