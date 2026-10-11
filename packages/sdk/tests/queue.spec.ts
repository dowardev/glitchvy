// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { describe, it, expect } from 'vitest';
import { clear, dequeueAll, enqueue, remove, size } from '../src/core/queue.js';
import type { ReportEvent } from '../src/types.js';

function makeEvent(id: string): ReportEvent {
  return {
    eventId: id,
    timestamp: new Date().toISOString(),
    appVersion: '0.1.0',
    environment: 'test',
    platform: 'web',
    route: '/x',
    userAgent: 'test',
    level: 'error',
    error: { name: 'Error', message: id },
    context: {},
    breadcrumbs: [],
  };
}

describe('queue (IndexedDB)', () => {
  it('starts empty and grows on enqueue', async () => {
    await clear();
    expect(await size()).toBe(0);
    await enqueue(makeEvent('a'));
    await enqueue(makeEvent('b'));
    expect(await size()).toBe(2);
  });

  it('dequeueAll returns enqueued events and removes them', async () => {
    await clear();
    await enqueue(makeEvent('x'));
    await enqueue(makeEvent('y'));
    const all = await dequeueAll();
    expect(all.map((e) => e.eventId).sort()).toEqual(['x', 'y']);
    expect(await size()).toBe(0);
  });

  it('upsert on enqueue (same eventId replaces)', async () => {
    await clear();
    await enqueue(makeEvent('dup'));
    await enqueue(makeEvent('dup')); // same eventId
    expect(await size()).toBe(1);
  });

  it('remove() drops a single event by id', async () => {
    await clear();
    await enqueue(makeEvent('keep'));
    await enqueue(makeEvent('drop'));
    await remove('drop');
    const all = await dequeueAll();
    expect(all.map((e) => e.eventId)).toEqual(['keep']);
  });

  it('clear() empties the store', async () => {
    await enqueue(makeEvent('a'));
    await enqueue(makeEvent('b'));
    await clear();
    expect(await size()).toBe(0);
  });
});
