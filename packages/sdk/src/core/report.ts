// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Transport. Builds a {@link ReportEvent} from a thrown value + optional
 * context, scrubs PII in user-controlled subtrees, then attempts a POST
 * with exponential-backoff retry. On terminal failure the event is
 * persisted in IndexedDB to be flushed later.
 */

import type { ErrorContext, ErrorLevel, InitConfig, ReportEvent } from '../types.js';
import { readPlatform, readRoute, readUserAgent } from './env.js';
import { scrub, buildScrubContext } from './scrub.js';
import type { ScrubContext } from './scrub.js';
import { getState } from './state.js';
import * as queue from './queue.js';

/** Status codes we consider worth retrying. */
const RETRYABLE_STATUS = new Set<number>([408, 425, 429, 500, 502, 503, 504]);

/**
 * Browsers cap `keepalive` request bodies at roughly 64KB. A body above the
 * cap makes the request fail silently, so the event is lost even though the
 * SDK believes it was sent.
 */
const KEEPALIVE_BODY_LIMIT_BYTES = 64 * 1024;

export type SendResult = 'sent' | 'queued' | 'dropped';

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

function utf8ByteLength(value: string): number {
  return new TextEncoder().encode(value).length;
}

interface FittedPayload {
  event: ReportEvent;
  body: string;
  keepalive: boolean;
}

/**
 * Fit the payload under the `keepalive` body cap. The breadcrumb trail is
 * trimmed oldest-first because it is the only unbounded part of an event;
 * if the payload still does not fit, `keepalive` is dropped so the request
 * at least has a chance to complete normally.
 */
function fitForKeepalive(event: ReportEvent): FittedPayload {
  const body = JSON.stringify(event);
  if (utf8ByteLength(body) <= KEEPALIVE_BODY_LIMIT_BYTES) {
    return { event, body, keepalive: true };
  }

  const breadcrumbs = event.breadcrumbs ?? [];
  let keep = breadcrumbs.length;
  while (keep > 0) {
    keep = Math.floor(keep / 2);
    const trimmed: ReportEvent = { ...event, breadcrumbs: breadcrumbs.slice(breadcrumbs.length - keep) };
    const trimmedBody = JSON.stringify(trimmed);
    if (utf8ByteLength(trimmedBody) <= KEEPALIVE_BODY_LIMIT_BYTES) {
      return { event: trimmed, body: trimmedBody, keepalive: true };
    }
  }

  const stripped: ReportEvent = { ...event, breadcrumbs: [] };
  const strippedBody = JSON.stringify(stripped);
  return {
    event: stripped,
    body: strippedBody,
    keepalive: utf8ByteLength(strippedBody) <= KEEPALIVE_BODY_LIMIT_BYTES,
  };
}

/** Single fetch attempt. Throws on network failure or returns the response. */
export async function sendOnce(event: ReportEvent, config: InitConfig): Promise<Response> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${config.apiKey}`,
    'X-Glitchvy-Event-Id': event.eventId,
  };
  const payload = fitForKeepalive(event);
  return fetch(config.ingestionUrl, {
    method: 'POST',
    headers,
    body: payload.body,
    keepalive: payload.keepalive,
  });
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Send an event, retrying with exponential backoff on retryable status codes
 * and network failures. Returns `true` when the event was accepted.
 */
async function attemptSend(
  event: ReportEvent,
  config: InitConfig,
  maxRetries: number,
  baseMs: number,
): Promise<boolean> {
  for (let attempt = 0; attempt <= maxRetries; attempt++) {
    try {
      const res = await sendOnce(event, config);
      if (res.ok) return true;
      if (!RETRYABLE_STATUS.has(res.status)) return false;
    } catch (_err) {
      if (attempt >= maxRetries) return false;
    }
    if (attempt < maxRetries) {
      const delay = baseMs * Math.pow(2, attempt);
      await sleep(delay);
    }
  }
  return false;
}

/**
 * Attempt to send an event. Retries with exponential backoff for retryable
 * status codes and network failures. On terminal failure, persists in the
 * offline queue.
 */
export async function send(event: ReportEvent, config: InitConfig): Promise<SendResult> {
  const maxRetries = config.maxRetries ?? 3;
  const baseMs = config.retryBaseMs ?? 1000;

  const delivered = await attemptSend(event, config, maxRetries, baseMs);
  if (delivered) return 'sent';
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
 * Drain the offline queue: each event is sent with the same retry budget the
 * consumer configured (`maxRetries` / `retryBaseMs`); failures are
 * re-enqueued (upsert on eventId avoids duplicates) as long as the queue is
 * below `maxQueueSize`.
 */
export async function flushQueue(
  config: InitConfig,
): Promise<{ sent: number; requeued: number }> {
  let events: ReportEvent[];
  try {
    events = await queue.dequeueAll();
  } catch {
    return { sent: 0, requeued: 0 };
  }

  const maxRetries = config.maxRetries ?? 3;
  const baseMs = config.retryBaseMs ?? 1000;
  const maxQueueSize = config.maxQueueSize ?? 100;

  let sent = 0;
  let requeued = 0;

  for (const event of events) {
    const success = await attemptSend(event, config, maxRetries, baseMs);
    if (success) {
      try {
        await queue.remove(event.eventId);
        sent++;
      } catch {
        // best-effort cleanup
      }
      continue;
    }

    try {
      // Respect the same cap as `persist()`: a failing backend must not make
      // the offline queue grow without bound.
      const current = await queue.size();
      if (current >= maxQueueSize) continue;
      await queue.enqueue(event);
      requeued++;
    } catch {
      // drop
    }
  }

  return { sent, requeued };
}

/** Build a {@link ScrubContext} from a config. */
export function makeScrubCtx(config: InitConfig): ScrubContext {
  return buildScrubContext(config.scrubRules);
}
