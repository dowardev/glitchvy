// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { Mock } from 'vitest';
import { buildEvent, flushQueue, makeScrubCtx, normalizeError, send, sendOnce } from '../src/core/report.js';
import type { InitConfig, ReportEvent } from '../src/types.js';
import * as queue from '../src/core/queue.js';
import { resetState, getState, pushBreadcrumb } from '../src/core/state.js';

const BASE_CONFIG: InitConfig = {
  ingestionUrl: '/api/glitchvy/errors',
  apiKey: 'gvy_test',
  appVersion: '0.1.0',
  environment: 'test',
  scrubRules: {
    redactFields: ['name', 'amount', 'balance', 'notes'],
    redactContexts: ['entry', 'supplier', 'account'],
  },
  maxRetries: 1,
  retryBaseMs: 1,
};

function makeEvent(id = '1'): ReportEvent {
  return {
    eventId: id,
    timestamp: '2026-01-01T00:00:00.000Z',
    appVersion: '0.1.0',
    environment: 'test',
    platform: 'web',
    route: '/',
    userAgent: 'jest',
    level: 'error',
    error: { name: 'Error', message: 'x' },
    context: {},
    breadcrumbs: [],
  };
}

beforeEach(() => {
  resetState();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('normalizeError', () => {
  it('null/undefined → null', () => {
    expect(normalizeError(null)).toBeNull();
    expect(normalizeError(undefined)).toBeNull();
  });

  it('string → Error shape with that message', () => {
    expect(normalizeError('boom')).toEqual({ name: 'Error', message: 'boom' });
  });

  it('Error → keeps name/message/stack, adds type from constructor', () => {
    class CustomErr extends Error {
      constructor() {
        super('m');
        this.name = 'CustomErr';
      }
    }
    const out = normalizeError(new CustomErr());
    expect(out?.name).toBe('CustomErr');
    expect(out?.message).toBe('m');
    expect(out?.type).toBe('CustomErr');
    expect(typeof out?.stack).toBe('string');
  });

  it('plain object → shape with JSON-stringified message', () => {
    const out = normalizeError({ name: 'X', message: 'y', stack: 's' });
    expect(out?.name).toBe('X');
    expect(out?.message).toBe('y');
    expect(out?.stack).toBe('s');
  });

  it('circular object → describes its own keys instead of "[object Object]"', () => {
    const circ: Record<string, unknown> = { name: 'C' };
    circ.self = circ;
    const out = normalizeError(circ);
    expect(out?.name).toBe('C');
    expect(out?.message).toContain('self:');
    expect(out?.message).not.toBe('[object Object]');
  });

  it('circular object without a message → keeps the key/value summary', () => {
    const circ: Record<string, unknown> = { code: 'E_X' };
    circ.self = circ;
    const out = normalizeError(circ);
    expect(out?.name).toBe('Error');
    expect(out?.message).toContain('code: E_X');
  });

  it('number/boolean → Error shape', () => {
    expect(normalizeError(42)).toEqual({ name: 'Error', message: '42' });
    expect(normalizeError(true)).toEqual({ name: 'Error', message: 'true' });
  });
});

describe('buildEvent', () => {
  it('carries config + env + level + error info', () => {
    const ctx = makeScrubCtx(BASE_CONFIG);
    const ev = buildEvent(BASE_CONFIG, ctx, new Error('boom'), { entityType: 'x' }, 'error');
    expect(ev.appVersion).toBe('0.1.0');
    expect(ev.environment).toBe('test');
    expect(ev.error.message).toBe('boom');
    expect(ev.context.entityType).toBe('x');
    expect(ev.eventId).toMatch(/.+/);
    expect(ev.timestamp).toMatch(/\d{4}-\d{2}-\d{2}T/);
  });

  it('scrubs context.data using the scrub rules', () => {
    const ctx = makeScrubCtx(BASE_CONFIG);
    const ev = buildEvent(BASE_CONFIG, ctx, new Error('boom'), {
      data: {
        entry: { name: 'Comida', amount: 100 },
        balance: 100,
      },
    }, 'error');
    const data = ev.context.data as Record<string, unknown>;
    const entry = data.entry as Record<string, unknown>;
    expect(entry.name).toBe('[REDACTED]');
    expect(entry.amount).toBe('[REDACTED]');
    expect(data.balance).toBe(100);
  });

  it('attaches user context + breadcrumb trail', () => {
    const state = getState();
    state.user = { userId: '42' };
    pushBreadcrumb({ message: 'a', timestamp: 't1' });
    pushBreadcrumb({ message: 'b', timestamp: 't2' });
    const ctx = makeScrubCtx(BASE_CONFIG);
    const ev = buildEvent(BASE_CONFIG, ctx, new Error('x'), undefined, 'error');
    expect(ev.user).toEqual({ userId: '42' });
    expect(ev.breadcrumbs.map((b) => b.message)).toEqual(['a', 'b']);
  });

  it('falls back to a v4 UUID when crypto.randomUUID is unavailable', () => {
    vi.stubGlobal('crypto', {
      getRandomValues: (arr: Uint8Array) => {
        for (let i = 0; i < arr.length; i++) arr[i] = i;
        return arr;
      },
    });
    const ctx = makeScrubCtx(BASE_CONFIG);
    const ev = buildEvent(BASE_CONFIG, ctx, new Error('boom'), undefined, 'error');
    expect(ev.eventId).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
  });
});

describe('sendOnce', () => {
  it('POSTs the flat ingest payload with bearer + event id headers', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200 });
    vi.stubGlobal('fetch', fetchMock);
    const event = makeEvent();
    await sendOnce(event, BASE_CONFIG);
    expect(fetchMock).toHaveBeenCalledOnce();
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe(BASE_CONFIG.ingestionUrl);
    expect(init.method).toBe('POST');
    expect((init.headers as Record<string, string>)['Authorization']).toBe('Bearer gvy_test');
    expect((init.headers as Record<string, string>)['X-Glitchvy-Event-Id']).toBe(event.eventId);
    const body = JSON.parse(init.body as string) as Record<string, unknown>;
    expect(body).toMatchObject({
      event_id: event.eventId,
      app_version: '0.1.0',
      environment: 'test',
      level: 'error',
      error_name: 'Error',
      error_message: 'x',
    });
  });

  it('keeps keepalive for a payload under the 64KB cap', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200 });
    vi.stubGlobal('fetch', fetchMock);
    await sendOnce(makeEvent(), BASE_CONFIG);
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.keepalive).toBe(true);
  });

  it('trims the breadcrumb trail so a huge payload stays under the keepalive cap', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200 });
    vi.stubGlobal('fetch', fetchMock);
    const event = makeEvent();
    event.breadcrumbs = Array.from({ length: 200 }, (_, i) => ({
      message: `crumb-${i}-${'x'.repeat(1000)}`,
      timestamp: '2026-01-01T00:00:00.000Z',
    }));
    await sendOnce(event, BASE_CONFIG);
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const body = init.body as string;
    expect(new TextEncoder().encode(body).length).toBeLessThanOrEqual(64 * 1024);
    expect(init.keepalive).toBe(true);
  });

  it('drops keepalive when the payload cannot be trimmed under the cap', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200 });
    vi.stubGlobal('fetch', fetchMock);
    const event = makeEvent();
    event.context.data = { blob: 'y'.repeat(100 * 1024) };
    await sendOnce(event, BASE_CONFIG);
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.keepalive).toBe(false);
  });
});

describe('send', () => {
  it('returns "sent" on 2xx response', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 202 });
    vi.stubGlobal('fetch', fetchMock);
    const res = await send(makeEvent('a'), { ...BASE_CONFIG, maxRetries: 0 });
    expect(res).toBe('sent');
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(await queue.size()).toBe(0);
  });

  it('returns "queued" after retry budget exhausted for retryable status', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: false, status: 503 });
    vi.stubGlobal('fetch', fetchMock);
    const res = await send(makeEvent('a'), { ...BASE_CONFIG, maxRetries: 1, retryBaseMs: 1 });
    expect(res).toBe('queued');
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(await queue.size()).toBe(1);
  });

  it('returns "queued" for non-retryable 4xx', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: false, status: 400 });
    vi.stubGlobal('fetch', fetchMock);
    const res = await send(makeEvent('a'), { ...BASE_CONFIG, maxRetries: 3, retryBaseMs: 1 });
    expect(res).toBe('queued');
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('returns "queued" on network failure', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error('offline'));
    vi.stubGlobal('fetch', fetchMock);
    const res = await send(makeEvent('a'), { ...BASE_CONFIG, maxRetries: 1, retryBaseMs: 1 });
    expect(res).toBe('queued');
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('returns "dropped" when queue is at maxQueueSize', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error('offline'));
    vi.stubGlobal('fetch', fetchMock);
    await queue.enqueue(makeEvent('preload'));
    const res = await send(makeEvent('a'), { ...BASE_CONFIG, maxQueueSize: 1, maxRetries: 0 });
    expect(res).toBe('dropped');
  });
});

describe('flushQueue', () => {
  it('drains the queue and removes successfully-sent events', async () => {
    const fetchMock = vi
      .fn()
      .mockImplementation((_url: string, init: RequestInit) => {
        const body = JSON.parse(init.body as string) as { event_id: string };
        return Promise.resolve({ ok: body.event_id !== 'fail', status: 200 });
      });
    vi.stubGlobal('fetch', fetchMock as Mock);
    await queue.enqueue(makeEvent('a'));
    await queue.enqueue(makeEvent('fail'));
    await queue.enqueue(makeEvent('b'));
    const stats = await flushQueue(BASE_CONFIG);
    expect(stats.sent).toBe(2);
    expect(stats.requeued).toBe(1);
    expect(await queue.size()).toBe(1);
  });

  it('does nothing when the queue is empty', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    const stats = await flushQueue(BASE_CONFIG);
    expect(stats).toEqual({ sent: 0, requeued: 0 });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('retries a queued event with the configured retry budget', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: false, status: 503 });
    vi.stubGlobal('fetch', fetchMock);
    await queue.enqueue(makeEvent('a'));
    const stats = await flushQueue({ ...BASE_CONFIG, maxRetries: 1, retryBaseMs: 1 });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(stats.sent).toBe(0);
    expect(stats.requeued).toBe(1);
  });

  it('does not retry a queued event on a non-retryable status', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: false, status: 400 });
    vi.stubGlobal('fetch', fetchMock);
    await queue.enqueue(makeEvent('a'));
    await flushQueue({ ...BASE_CONFIG, maxRetries: 3, retryBaseMs: 1 });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('does not grow the queue past maxQueueSize when the backend keeps failing', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error('offline'));
    vi.stubGlobal('fetch', fetchMock);
    await queue.enqueue(makeEvent('a'));
    await queue.enqueue(makeEvent('b'));
    await queue.enqueue(makeEvent('c'));
    const stats = await flushQueue({ ...BASE_CONFIG, maxQueueSize: 1, maxRetries: 0 });
    expect(stats.requeued).toBe(1);
    expect(await queue.size()).toBe(1);
  });
});
