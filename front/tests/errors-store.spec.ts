// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

import { useAuthStore } from '../src/stores/auth.js';
import { useErrorsStore } from '../src/stores/errors.js';

function makeResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    statusText: 'OK',
    headers: { 'Content-Type': 'application/json' },
  });
}

describe('useErrorsStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    if (typeof localStorage !== 'undefined') localStorage.clear();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('fetchPage populates the list with the API response', async () => {
    const auth = useAuthStore();
    auth.setToken('tok');

    const fetchMock = vi.fn().mockResolvedValue(
      makeResponse({
        data: [
          { id: 1, level: 'error', status: 'open', error_name: 'X', error_message: 'm' },
        ],
        next_cursor: 'nxt',
        prev_cursor: null,
      }),
    );
    vi.stubGlobal('fetch', fetchMock);

    const store = useErrorsStore();
    await store.fetchPage();

    expect(store.list).toHaveLength(1);
    expect(store.list[0]?.id).toBe(1);
    expect(store.nextCursor).toBe('nxt');
    expect(store.error).toBeNull();
  });

  it('fetchPage stores the error message on failure', async () => {
    const auth = useAuthStore();
    auth.setToken('tok');

    const fetchMock = vi.fn().mockResolvedValue(
      makeResponse({ error: 'http_error', message: 'boom' }, 500),
    );
    vi.stubGlobal('fetch', fetchMock);

    const store = useErrorsStore();
    await store.fetchPage();

    expect(store.error).toBe('boom');
    expect(store.list).toEqual([]);
  });

  it('fetchPage refuses to run without an auth token', async () => {
    const store = useErrorsStore();
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);

    await store.fetchPage();
    expect(store.error).toBe('Not authenticated.');
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('setFilters merges with existing filters (does not clobber)', () => {
    const store = useErrorsStore();
    store.setFilters({ per_page: 50, search: 'x' });
    store.setFilters({ search: 'y' });
    expect(store.filters).toMatchObject({ per_page: 50, search: 'y' });
  });

  it('reset clears list + cursors but keeps filters', () => {
    const store = useErrorsStore();
    store.list = [
      { id: 1, level: 'error', status: 'open', error_name: 'X', error_message: 'm', environment: 'p', app_version: '1', platform: 'web', route: null, entity_type: null, entity_id: null, operation: null, created_at: null, updated_at: null },
    ];
    store.nextCursor = 'nxt';
    store.prevCursor = 'p';
    store.reset();
    expect(store.list).toEqual([]);
    expect(store.nextCursor).toBeNull();
    expect(store.prevCursor).toBeNull();
    expect(store.filters).toEqual({ per_page: 25 });
  });

  it('setStatus throws when the API rejects and does NOT update cache', async () => {
    const auth = useAuthStore();
    auth.setToken('tok');

    const fetchMock = vi.fn().mockResolvedValue(
      makeResponse({ error: 'invalid_status', message: 'bad' }, 422),
    );
    vi.stubGlobal('fetch', fetchMock);

    const store = useErrorsStore();
    const original = {
      id: 5,
      level: 'error' as const,
      status: 'open' as const,
      error_name: 'X',
      error_message: 'm',
      environment: 'p',
      app_version: '1',
      platform: 'web',
      route: null,
      entity_type: null,
      entity_id: null,
      operation: null,
      created_at: null,
      updated_at: null,
    };
    store.list = [original];

    await expect(store.setStatus(5, 'closed')).rejects.toThrow(/bad/);
    expect(store.list[0]?.status).toBe('open');
  });

  it('setStatus updates local cache on success', async () => {
    const auth = useAuthStore();
    auth.setToken('tok');

    const fetchMock = vi.fn().mockResolvedValue(
      makeResponse({ data: { id: 7, status: 'triaged' } }),
    );
    vi.stubGlobal('fetch', fetchMock);

    const store = useErrorsStore();
    const original = {
      id: 7,
      level: 'error' as const,
      status: 'open' as const,
      error_name: 'X',
      error_message: 'm',
      environment: 'p',
      app_version: '1',
      platform: 'web',
      route: null,
      entity_type: null,
      entity_id: null,
      operation: null,
      created_at: null,
      updated_at: null,
    };
    store.list = [original];

    await store.setStatus(7, 'triaged');
    expect(store.list[0]?.status).toBe('triaged');
  });
});
