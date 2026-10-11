// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { api, GlitchvyApiError, setBaseUrl } from '../src/api/client.js';

function makeResponse(body: unknown, init: { status?: number; statusText?: string } = {}): Response {
  const status = init.status ?? 200;
  return new Response(JSON.stringify(body), {
    status,
    statusText: init.statusText ?? 'OK',
    headers: { 'Content-Type': 'application/json' },
  });
}

beforeEach(() => {
  setBaseUrl('/api/glitchvy');
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('api.listErrors', () => {
  it('sends a GET with the user token and query params', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      makeResponse({ data: [], next_cursor: null, prev_cursor: null }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await api.listErrors({ level: 'error', per_page: 10 }, 'user-token');

    expect(fetchMock).toHaveBeenCalledOnce();
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toMatch(/^.+errors\?/);
    expect(init.method).toBe('GET');
    expect((init.headers as Record<string, string>)['X-Glitchvy-User-Token']).toBe('user-token');
    expect(init.body).toBeUndefined();
    // Filters in the query string.
    expect(url).toContain('level=error');
    expect(url).toContain('per_page=10');
  });

  it('omits empty/null/undefined filters', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      makeResponse({ data: [], next_cursor: null, prev_cursor: null }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await api.listErrors({ level: '', status: undefined, search: null }, 'tok');

    const [url] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).not.toContain('level');
    expect(url).not.toContain('status');
    expect(url).not.toContain('search');
  });

  it('throws GlitchvyApiError on non-OK responses', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(makeResponse({ error: 'unauthenticated', message: 'no token' }, { status: 401 }));
    vi.stubGlobal('fetch', fetchMock);

    await expect(api.listErrors({}, 'tok')).rejects.toThrow(GlitchvyApiError);
    await expect(api.listErrors({}, 'tok')).rejects.toMatchObject({ status: 401 });
  });
});

describe('api.getError', () => {
  it('unwraps data and forwards the auth token', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      makeResponse({ data: { id: 42, error_name: 'X' } }),
    );
    vi.stubGlobal('fetch', fetchMock);

    const detail = await api.getError(42, 'tok');
    expect(detail).toMatchObject({ id: 42, error_name: 'X' });
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('/api/glitchvy/errors/42');
    expect((init.headers as Record<string, string>)['X-Glitchvy-User-Token']).toBe('tok');
  });
});

describe('api.updateStatus', () => {
  it('sends PATCH with the new status as JSON body', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      makeResponse({ data: { id: 7, status: 'closed' } }),
    );
    vi.stubGlobal('fetch', fetchMock);

    const updated = await api.updateStatus(7, 'closed', 'tok');
    expect(updated).toMatchObject({ id: 7, status: 'closed' });
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('/api/glitchvy/errors/7/status');
    expect(init.method).toBe('PATCH');
    expect(JSON.parse(init.body as string)).toEqual({ status: 'closed' });
    expect((init.headers as Record<string, string>)['Content-Type']).toBe('application/json');
  });

  it('rejects on 422 with GlitchvyApiError', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(makeResponse({ error: 'invalid_status', message: 'bad' }, { status: 422 }));
    vi.stubGlobal('fetch', fetchMock);

    await expect(api.updateStatus(1, 'open', 'tok')).rejects.toBeInstanceOf(GlitchvyApiError);
  });
});

describe('api.screenshotUrl', () => {
  it('returns the absolute URL with the configured base', () => {
    expect(api.screenshotUrl(99)).toBe('/api/glitchvy/errors/99/screenshot');
  });
});
