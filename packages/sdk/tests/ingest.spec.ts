// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { describe, it, expect } from 'vitest';
import { toIngestPayload } from '../src/core/ingest.js';
import type { ReportEvent, ErrorLevel } from '../src/types.js';

function makeEvent(overrides: Partial<ReportEvent> = {}): ReportEvent {
  return {
    eventId: '11111111-1111-4111-8111-111111111111',
    timestamp: '2026-01-01T00:00:00.000Z',
    appVersion: '0.1.0',
    environment: 'test',
    platform: 'web',
    route: '/',
    userAgent: 'jest',
    level: 'error',
    error: { name: 'Error', message: 'boom' },
    context: {},
    breadcrumbs: [],
    ...overrides,
  };
}

const BACKEND_LEVELS: ErrorLevel[] = ['debug', 'info', 'warning', 'error', 'critical'];

describe('toIngestPayload', () => {
  it('flattens the nested SDK event into the documented ingest contract', () => {
    const payload = toIngestPayload(
      makeEvent({
        error: { name: 'TypeError', message: 'boom', type: 'TypeError', stack: 'TypeError: boom' },
        context: { entityType: 'budget', entityId: 7, operation: 'create', tags: { a: 'b' }, data: { k: 'v' } },
        user: { userId: 42, tier: 'pro' },
        breadcrumbs: [{ message: 'opened', timestamp: 't1', data: { x: 1 } }],
      }),
    );

    expect(payload).toEqual({
      event_id: '11111111-1111-4111-8111-111111111111',
      app_version: '0.1.0',
      environment: 'test',
      platform: 'web',
      route: '/',
      user_agent: 'jest',
      level: 'error',
      error_name: 'TypeError',
      error_message: 'boom',
      error_type: 'TypeError',
      error_stack: 'TypeError: boom',
      entity_type: 'budget',
      entity_id: 7,
      operation: 'create',
      tags: { a: 'b' },
      data: { k: 'v' },
      user_id: 42,
      user_payload: { userId: 42, tier: 'pro' },
      breadcrumbs: [{ message: 'opened', timestamp: 't1', data: { x: 1 } }],
    });
  });

  it('never sends camelCase keys', () => {
    const payload = toIngestPayload(makeEvent({ context: { entityType: 'x', entityId: 1 } }));
    const keys = Object.keys(payload);
    for (const camelKey of ['eventId', 'appVersion', 'userAgent', 'entityType', 'error', 'context', 'user']) {
      expect(keys).not.toContain(camelKey);
    }
  });

  it('keeps error_message non-empty (the backend rejects empty strings)', () => {
    const payload = toIngestPayload(makeEvent({ error: { name: 'TypeError', message: '' } }));
    expect(payload.error_message).toBe('TypeError');
  });

  it('drops a non-integer entity_id instead of failing the whole event', () => {
    const payload = toIngestPayload(
      makeEvent({ context: { entityId: 'abc-123' } }),
    );
    expect(payload.entity_id).toBeUndefined();
  });

  it('coerces a numeric string entity_id to an integer', () => {
    const payload = toIngestPayload(makeEvent({ context: { entityId: '123' } }));
    expect(payload.entity_id).toBe(123);
  });

  it('truncates over-long fields to the limits the backend enforces', () => {
    const payload = toIngestPayload(
      makeEvent({
        appVersion: 'v'.repeat(80),
        environment: 'e'.repeat(80),
        route: '/'.repeat(300),
        error: { name: 'E'.repeat(300), message: 'boom', type: 'T'.repeat(300) },
        context: { entityType: 'x'.repeat(200), operation: 'y'.repeat(200) },
      }),
    );
    expect(payload.app_version).toHaveLength(50);
    expect(payload.environment).toHaveLength(50);
    expect(payload.route).toHaveLength(255);
    expect(payload.error_name).toHaveLength(255);
    expect(payload.error_type).toHaveLength(255);
    expect(payload.entity_type).toHaveLength(100);
    expect(payload.operation).toHaveLength(100);
  });

  it('omits empty optional collections', () => {
    const payload = toIngestPayload(makeEvent({ context: { tags: {}, data: {} } }));
    expect(payload.tags).toBeUndefined();
    expect(payload.data).toBeUndefined();
    expect(payload.breadcrumbs).toBeUndefined();
    expect(payload.user_payload).toBeUndefined();
  });

  it('keeps the user payload even when the user id is not an integer', () => {
    const payload = toIngestPayload(makeEvent({ user: { userId: 'abc' } }));
    expect(payload.user_id).toBeUndefined();
    expect(payload.user_payload).toEqual({ userId: 'abc' });
  });

  describe('level mapping (S1)', () => {
    it('maps each SDK level to a backend-accepted value', () => {
      for (const level of BACKEND_LEVELS) {
        const payload = toIngestPayload(makeEvent({ level }));
        expect(BACKEND_LEVELS).toContain(payload.level);
      }
    });

    it('maps warn alias to warning in the payload', () => {
      const payload = toIngestPayload(makeEvent({ level: 'warn' }));
      expect(payload.level).toBe('warning');
    });
  });
});
