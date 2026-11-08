// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  addBreadcrumb,
  flush,
  getQueueSize,
  getQueueStatus,
  initErrorReporter,
  reportError,
  setUserContext,
  shutdown,
} from '../src/index.js';
import * as queue from '../src/core/queue.js';

const CFG = {
  ingestionUrl: '/api/glitchvy/errors',
  apiKey: 'gvy_test',
  appVersion: '0.1.0',
  environment: 'test',
  scrubRules: {
    redactFields: ['name', 'amount'],
    redactContexts: ['entry'],
  },
  maxRetries: 1,
  retryBaseMs: 1,
};

beforeEach(async () => {
  await shutdown();
  vi.unstubAllGlobals();
});

afterEach(async () => {
  await shutdown();
  vi.unstubAllGlobals();
});

describe('initErrorReporter', () => {
  it('is idempotent (re-init does not double-install hooks)', async () => {
    initErrorReporter(CFG);
    initErrorReporter(CFG);
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200 });
    vi.stubGlobal('fetch', fetchMock);
    await reportError('hello');
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('reportError before init warns and buffers the event until init', async () => {
    const originalWarn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    await reportError('orphan');
    expect(originalWarn).toHaveBeenCalled();
    expect(await queue.size()).toBe(0);
    originalWarn.mockRestore();
  });

  it('sends the buffered pre-init event once the SDK is initialized', async () => {
    const originalWarn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 202 });
    vi.stubGlobal('fetch', fetchMock);
    await reportError(new Error('early-boot'));
    expect(fetchMock).not.toHaveBeenCalled();

    initErrorReporter(CFG);
    await new Promise((r) => setTimeout(r, 10));
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const body = JSON.parse(init.body as string) as { error_message: string };
    expect(body.error_message).toBe('early-boot');
    originalWarn.mockRestore();
  });
  it('delivers the buffered pre-init event even if shutdown() runs right after init', async () => {
    const originalWarn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 202 });
    vi.stubGlobal('fetch', fetchMock);
    await reportError(new Error('early-boot'));
    initErrorReporter(CFG);
    shutdown();
    await new Promise((r) => setTimeout(r, 10));
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const body = JSON.parse(init.body as string) as { error_message: string };
    expect(body.error_message).toBe('early-boot');
    originalWarn.mockRestore();
  });
});

describe('reportError', () => {
  it('POSTs an event and resolves cleanly on 2xx', async () => {
    initErrorReporter(CFG);
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 202 });
    vi.stubGlobal('fetch', fetchMock);
    await reportError(new Error('hi'), { entityType: 'budget' });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const body = JSON.parse(init.body as string) as Record<string, unknown>;
    expect(body).toMatchObject({
      app_version: '0.1.0',
      environment: 'test',
      level: 'error',
      error_name: 'Error',
      error_message: 'hi',
      entity_type: 'budget',
    });
    expect((init.headers as Record<string, string>)['Authorization']).toBe('Bearer gvy_test');
  });

  it('scrubs context.data per configured rules', async () => {
    initErrorReporter(CFG);
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 202 });
    vi.stubGlobal('fetch', fetchMock);
    await reportError(new Error('hi'), {
      data: { entry: { name: 'Comida', qty: 2 }, balance: 100 },
    });
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const body = JSON.parse(init.body as string) as {
      data: { entry: { name: string; qty: number }; balance: number };
    };
    expect(body.data.entry.name).toBe('[REDACTED]');
    expect(body.data.entry.qty).toBe(2);
    expect(body.data.balance).toBe(100);
  });

  it('redacts a JWT bearer token from the outgoing payload', async () => {
    initErrorReporter(CFG);
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 202 });
    vi.stubGlobal('fetch', fetchMock);
    const jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.abc+/def==';
    await reportError(new Error('hi'), {
      data: { headers: { authorization: `Bearer ${jwt}` } },
    });
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const raw = init.body as string;
    expect(raw).not.toContain('abc+/def==');
    const body = JSON.parse(raw) as { data: { headers: { authorization: string } } };
    expect(body.data.headers.authorization).toBe('Bearer [REDACTED]');
  });

  it('attaches user context + breadcrumb trail', async () => {
    initErrorReporter(CFG);
    setUserContext({ userId: '42', tier: 'pro' });
    addBreadcrumb('opened form');
    addBreadcrumb('clicked save');
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 202 });
    vi.stubGlobal('fetch', fetchMock);
    await reportError(new Error('hi'));
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const body = JSON.parse(init.body as string) as {
      user_payload: Record<string, unknown>;
      breadcrumbs: { message: string }[];
    };
    expect(body.user_payload).toEqual({ userId: '42', tier: 'pro' });
    expect(body.breadcrumbs.map((b) => b.message)).toEqual(['opened form', 'clicked save']);
  });

  it('queues offline events when the server is down', async () => {
    initErrorReporter(CFG);
    const fetchMock = vi.fn().mockRejectedValue(new Error('offline'));
    vi.stubGlobal('fetch', fetchMock);
    await reportError(new Error('will be queued'));
    // maxRetries: 1 means 2 attempts (attempt 0 + attempt 1) before queuing
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(await queue.size()).toBe(1);
  });
});

describe('flush / getQueueSize', () => {
  it('flush() drains queued events when the server is back', async () => {
    let serveUp = false;
    const fetchMock = vi.fn().mockImplementation(() => {
      if (!serveUp) return Promise.reject(new Error('offline'));
      return Promise.resolve({ ok: true, status: 202 });
    });
    vi.stubGlobal('fetch', fetchMock);
    initErrorReporter(CFG);
    await reportError(new Error('queued'));
    // 2 failed attempts before queuing
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(await queue.size()).toBe(1);

    serveUp = true;
    await flush();
    // flush makes 1 successful attempt
    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect(await queue.size()).toBe(0);
  });

  it('getQueueSize returns 0 on a healthy queue', async () => {
    initErrorReporter(CFG);
    expect(await getQueueSize()).toBe(0);
  });

  it('getQueueStatus reports an empty queue as readable', async () => {
    initErrorReporter(CFG);
    const status = await getQueueStatus();
    expect(status).toEqual({ size: 0, readable: true });
  });

  it('getQueueStatus reports an unreadable queue instead of masking it', async () => {
    initErrorReporter(CFG);
    queue.__resetDb();
    const original = globalThis.indexedDB;
    // @ts-expect-error - simulating a runtime without IndexedDB
    delete globalThis.indexedDB;
    try {
      const status = await getQueueStatus();
      expect(status.readable).toBe(false);
      expect(status.size).toBe(0);
      expect(typeof status.error).toBe('string');
    } finally {
      globalThis.indexedDB = original;
      queue.__resetDb();
    }
  });
});

describe('shutdown (S6)', () => {
  it('is async and returns a Promise<void>', async () => {
    initErrorReporter(CFG);
    const result = shutdown();
    expect(result).toBeInstanceOf(Promise);
    await result;
  });

  it('flushes the offline queue before tearing down state', async () => {
    let serveUp = false;
    const fetchMock = vi.fn().mockImplementation(() => {
      if (!serveUp) return Promise.reject(new Error('offline'));
      return Promise.resolve({ ok: true, status: 202 });
    });
    vi.stubGlobal('fetch', fetchMock);
    initErrorReporter(CFG);
    await reportError(new Error('queued-for-shutdown'));
    // 2 failed attempts before queuing
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(await queue.size()).toBe(1);

    serveUp = true;
    await shutdown();
    // shutdown flushes: 1 successful attempt
    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect(await queue.size()).toBe(0);
  });

  it('is safe to call when the SDK was never initialized', async () => {
    await expect(shutdown()).resolves.toBeUndefined();
  });

  it('clears auto-capture hooks (console.error no longer triggers report)', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 202 });
    vi.stubGlobal('fetch', fetchMock);
    initErrorReporter(CFG);
    await shutdown();

    const original = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    // eslint-disable-next-line no-console
    console.error('boom');
    await new Promise((r) => setTimeout(r, 5));
    expect(fetchMock).not.toHaveBeenCalled();
    original.mockRestore();
  });
});

describe('capture install flag (S7)', () => {
  it('init without window leaves capture uninstalled; later init with window installs hooks', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 202 });
    vi.stubGlobal('fetch', fetchMock);

    // Simulate a runtime without a window (SSR).
    const realWindow = globalThis.window;
    vi.stubGlobal('window', undefined);

    // First init without window
    initErrorReporter(CFG);
    await reportError(new Error('without-window'));
    expect(fetchMock).toHaveBeenCalledTimes(1);

    // Shutdown to reset the initialized flag, then bring the window back
    await shutdown();
    vi.stubGlobal('window', realWindow);

    // Set up spy BEFORE init so we catch the addEventListener calls
    const addEventListenerSpy = vi.spyOn(window, 'addEventListener');
    initErrorReporter(CFG);

    // Trigger a console.error to verify hooks are installed
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    console.error('test-after-window');
    await new Promise((r) => setTimeout(r, 5));
    consoleSpy.mockRestore();

    // Should have installed error listener
    const registered = addEventListenerSpy.mock.calls.map((args) => args[0]).filter((t) => typeof t === 'string');
    expect(registered).toContain('error');
    addEventListenerSpy.mockRestore();
  });
});
