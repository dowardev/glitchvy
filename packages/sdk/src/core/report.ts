// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Transport layer. Builds a {@link ReportEvent} from a thrown error (or string),
 * scrubs PII in user-controlled subtrees, then attempts a POST with
 * exponential-backoff retry. On terminal failure the event is enqueued in
 * IndexedDB to be flushed later.
 *
 * Module is browser-fenced: `fetch` is assumed to be the standard Fetch API
 * (production browser / modern Node 18+). Tests use Vitest's jsdom + a fetch
 * polyfill if needed.
 */

import type { ErrorContext, ErrorLevel, InitConfig, ReportEvent } from '../types.js';
import { readPlatform, readRoute, readUserAgent } from './env.js';
import { buildScrubContext, scrub } from './scrub.js';
import type { ScrubContext } from './scrub.js';
import { getState } from './state.js';
import * as queue from './queue.js';

/**
 * Status codes we consider worth retrying. Everything else is queued
 * immediately on first failure to preserve storage space.
 */
const RETRYABLE_STATUS = new Set<number>([408, 425, 429, 500, 502, 503, 504]);

export type SendResult = 'sent' | 'queued' | 'dropped';

/**
 * Coerce an arbitrary thrown value into the `error` shape of a {@link ReportEvent}.
 */
export function normalizeError(err: unknown):
  | { name: string; message: string; stack?: string; type?: string }
  | null {
  if (err === null || err === undefined) return null;

  if (typeof err === 'string') {
    return { name: 'Error', message: err };
  }

  if (err instanceof Error) {
    const out: { name: string; message: string; stack?: string; type?: string } = {
      name: err.name || 'Error',
      message: err.message || '',
    };
    if (typeof err.stack === 'string') out.stack = err.stack;
    const ctorName = (err.constructor as { name?: string } | undefined)?.name;
    if (ctorName) out.type = ctorName;
    return out;
  }

  if (typeof err === 'object') {
    const r = err as Record<string, unknown>;
    let message: string;
    try {
      message = typeof r.message === 'string' ? r.message : JSON.stringify(err);
    } catch {
      message = String(err);
    }
    const out: { name: string; message: string; stack?: string; type?: string } = {
      name: typeof r.name === 'string' ? r.name : 'Error',
      message,
    };
    if (typeof r.stack === 'string') out.stack = r.stack;
    if (typeof r.type === 'string') out.type = r.type;
    return out;
  }

  return { name: 'Error', message: String(err) };
}

function newEventId(): string {
  const c = (globalThis as { crypto?: { randomUUID?: () => string } }).crypto;
  if (c && typeof c.randomUUID === 'function') {
    return c.randomUUID();
  }
  // Acceptable fallback: timestamp + random suffix. Not cryptographically strong
  // but unique per event in practice for the queue's purposes.
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

/** Build a {@link ReportEvent} from a thrown value and optional context. */
export function buildEvent(
  config: InitConfig,
  scrubCtx: ScrubContext,
  err: unknown,
  context: ErrorContext | undefined,
  level: ErrorLevel,
): ReportEvent {
  const state = getState();
  const norm = normalizeError(err);
  const safeError = norm ?? { name: 'Error', message: 'Unknown error' };

  const event: ReportEvent = {
    eventId: newEventId(),
    timestamp: new Date().toISOString(),
    appVersion: config.appVersion,
    environment: config.environment,
    platform: readPlatform(),
    route: readRoute(),
    userAgent: readUserAgent(),
    level,
    error: safeError,
    context: {
      entityType: context?.entityType,
      entityId: context?.entityId,
      operation: context?.operation,
      tags: context?.tags ? { ...context.tags } : undefined,
      data: context?.data ? { ...context.data } : undefined,
    },
    breadcrumbs: state.breadcrumbs.map((b) => ({
      message: b.message,
      timestamp: b.timestamp,
      data: b.data ? { ...b.data } : undefined,
    })),
  };
  if (Object.keys(state.user).length > 0) {
    event.user = { ...state.user };
  }

  // Scrub user-controlled subtrees.
  if (event.context.data) {
    event.context.data = scrub(event.context.data, scrubCtx) as Record<string, unknown>;
  }
  if (event.context.tags) {
    event.context.tags = scrub(event.context.tags, scrubCtx) as Record<string, string>;
  }
  if (event.user) {
    event.user = scrub(event.user, scrubCtx) as Record<string, unknown>;
  }
  for (let i = 0; i < event.breadcrumbs.length; i++) {
    const b = event.breadcrumbs[i]!;
    if (b.data) {
      event.breadcrumbs[i] = {
        message: b.message,
        timestamp: b.timestamp,
        data: scrub(b.data, scrubCtx) as Record<string, unknown>,
      };
    }
  }
  return event;
}

/** Single fetch attempt. Throws on network failure or returns the response. */
export async function sendOnce(event: ReportEvent, config: InitConfig): Promise<Response> {
  // The outbound headers the SDK itself constructs never go through scrubHeaders:
  // the Authorization Bearer MUST reach the server for auth to work, and the
  // event id is opaque. {@link scrubHeaders} is reserved for consumer-supplied
  // headers carried in `context.data`.
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${config.apiKey}`,
    'X-Glitchvy-Event-Id': event.eventId,
  };
  return fetch(config.ingestionUrl, {
    method: 'POST',
    headers,
    body: JSON.stringify(event),
    keepalive: true,
  });
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Attempt to send an event. Retries with exponential backoff for retryable
 * status codes and network failures. On terminal failure, persists in the
 * offline queue.
 */
export async function send(event: ReportEvent, config: InitConfig): Promise<SendResult> {
  const maxRetries = config.maxRetries ?? 3;
  const baseMs = config.retryBaseMs ?? 1000;

  for (let attempt = 0; attempt <= maxRetries; attempt++) {
    try {
      const res = await sendOnce(event, config);
      if (res.ok) return 'sent';
      if (!RETRYABLE_STATUS.has(res.status)) {
        // Permanent (4xx other than 408/425/429) → queue.
        break;
      }
    } catch (_err) {
      if (attempt >= maxRetries) break;
    }
    if (attempt < maxRetries) {
      const delay = baseMs * Math.pow(2, attempt);
      await sleep(delay);
    }
  }
  return persist(event, config);
}

async function persist(event: ReportEvent, config: InitConfig): Promise<SendResult> {
  try {
    const current = await queue.size();
    const max = config.maxQueueSize ?? 100;
    if (current >= max) return 'dropped';
    await queue.enqueue(event);
    return 'queued';
  } catch {
    return 'dropped';
  }
}

/**
 * Drain the offline queue: each event's send is a single attempt (the queue's
 * own retry/queue cycle kicks in if the network is still down for that event).
 * Successful sends remove the entry from the queue; failures re-enqueue via
 * the upsert-on-eventId behavior of the store.
 */
export async function flushQueue(config: InitConfig): Promise<{ sent: number; requeued: number }> {
  let events: ReportEvent[];
  try {
    events = await queue.dequeueAll();
  } catch {
    return { sent: 0, requeued: 0 };
  }

  let sent = 0;
  let requeued = 0;

  for (const event of events) {
    let success = false;
    try {
      const res = await sendOnce(event, config);
      success = res.ok;
    } catch {
      success = false;
    }
    if (success) {
      try {
        await queue.remove(event.eventId);
        sent++;
      } catch {
        // Best-effort cleanup; failure is non-fatal.
      }
    } else {
      try {
        await queue.enqueue(event);
        requeued++;
      } catch {
        // Drop — queue is broken or full.
      }
    }
  }

  return { sent, requeued };
}

/** Test-only: expose the shared scrub context to callers without re-importing. */
export function makeScrubCtx(config: InitConfig): ScrubContext {
  return buildScrubContext(config.scrubRules);
}
