// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Public API for the glitchvy SDK. Framework-agnostic. The consumer wraps
 * these primitives in their own UI; the SDK ships no UI of its own.
 */

import {
  buildScrubContext,
} from './core/scrub.js';
import {
  buildEvent,
  flushQueue,
  send,
} from './core/report.js';
import {
  getState,
  pushBreadcrumb,
  resetState,
} from './core/state.js';
import {
  clearReportCallback,
  installAutoCapture,
  setReportCallback,
  uninstallAutoCapture,
} from './core/capture.js';
import * as queue from './core/queue.js';
import type { ScrubContext } from './core/scrub.js';
import type { Breadcrumb, ErrorContext, ErrorLevel, InitConfig, QueueStatus } from './types.js';

const DEFAULT_MAX_QUEUE = 100;
const DEFAULT_MAX_RETRIES = 3;
const DEFAULT_RETRY_BASE_MS = 1_000;
/** How many events reported before init are kept in memory until init happens. */
const MAX_PENDING_BEFORE_INIT = 20;

const DEFAULT_CAPTURE = {
  onerror: true,
  unhandledrejection: true,
  consoleError: true,
} as const;

let initialized = false;

/**
 * Events reported before `initErrorReporter` was called. They are kept (up to
 * a small cap) and sent as soon as the SDK is initialized, instead of being
 * dropped.
 */
interface PendingReport {
  err: unknown;
  context?: ErrorContext;
  level: ErrorLevel;
}
let pendingBeforeInit: PendingReport[] = [];

function applyDefaults(config: InitConfig): InitConfig {
  return {
    ...config,
    maxQueueSize: config.maxQueueSize ?? DEFAULT_MAX_QUEUE,
    maxRetries: config.maxRetries ?? DEFAULT_MAX_RETRIES,
    retryBaseMs: config.retryBaseMs ?? DEFAULT_RETRY_BASE_MS,
    capture: { ...DEFAULT_CAPTURE, ...(config.capture ?? {}) },
  };
}

/**
 * Bootstraps the SDK. Idempotent; safe to call once on app start.
 */
export function initErrorReporter(config: InitConfig): void {
  if (initialized) {
    // Re-init overwrites the config but does NOT re-install hooks.
    const state = getState();
    const cfg = applyDefaults(config);
    state.config = cfg;
    state.scrubCtx = buildScrubContext(cfg.scrubRules);
    return;
  }
  initialized = true;

  const cfg = applyDefaults(config);
  const state = getState();
  state.config = cfg;
  state.scrubCtx = buildScrubContext(cfg.scrubRules);

  setReportCallback(async (err: unknown, ctx?: ErrorContext, level?: ErrorLevel) => {
    await reportError(err, ctx, level);
  });

  installAutoCapture(cfg.capture, () => {
    void flush();
  });

  // Send whatever was reported before init instead of losing it. The config
  // captured here is used on purpose: a `shutdown()` racing the drain must
  // not send those events back to the pre-init buffer.
  const pending = pendingBeforeInit;
  pendingBeforeInit = [];
  for (const p of pending) {
    void deliver(p.err, p.context, p.level, cfg, state.scrubCtx);
  }
}

/** Build and send one event with an explicit config. */
async function deliver(
  err: unknown,
  context: ErrorContext | undefined,
  level: ErrorLevel,
  config: InitConfig,
  scrubCtx: ScrubContext,
): Promise<void> {
  const event = buildEvent(config, scrubCtx, err, context, level);
  await send(event, config);
}

/**
 * Report an error explicitly. Never throws.
 *
 * Failures (server down, retry budget exhausted, etc.) queue the event in
 * IndexedDB and are flushed later — see {@link flush}.
 */
export async function reportError(
  err: unknown,
  context?: ErrorContext,
  level: ErrorLevel = 'error',
): Promise<void> {
  const state = getState();
  if (!state.config || !state.scrubCtx) {
    if (typeof console !== 'undefined') {
      console.warn('[glitchvy] reportError called before initErrorReporter; event buffered until init.');
    }
    if (pendingBeforeInit.length < MAX_PENDING_BEFORE_INIT) {
      pendingBeforeInit.push({ err, context, level });
    }
    return;
  }
  const event = buildEvent(state.config, state.scrubCtx, err, context, level);
  await send(event, state.config);
}
/** Add/overrides keys in the user context attached to every report from now on. */
export function setUserContext(partial: Record<string, unknown> | undefined): void {
  const state = getState();
  if (partial === undefined) {
    state.user = {};
    return;
  }
  state.user = { ...state.user, ...partial };
}

export function clearUserContext(): void {
  getState().user = {};
}

/** Push a breadcrumb onto the trail. Reports carry the last 50 by default. */
export function addBreadcrumb(message: string, data?: Record<string, unknown>): void {
  if (typeof message !== 'string' || message.length === 0) return;
  const b: Breadcrumb = {
    message,
    timestamp: new Date().toISOString(),
    data: data ? { ...data } : undefined,
  };
  pushBreadcrumb(b);
}

/** Force-flush the offline queue. Safe to call repeatedly. */
export async function flush(): Promise<void> {
  const state = getState();
  if (!state.config) return;
  await flushQueue(state.config);
}

/**
 * Number of events currently persisted in IndexedDB. Returns 0 if uninitialized.
 *
 * A `0` is ambiguous: it also means "the queue could not be read". Use
 * {@link getQueueStatus} when the difference matters.
 */
export async function getQueueSize(): Promise<number> {
  try {
    return await queue.size();
  } catch {
    return 0;
  }
}

/**
 * State of the offline queue, distinguishing an empty queue from a queue
 * that could not be read (IndexedDB unavailable, blocked, corrupted).
 */
export async function getQueueStatus(): Promise<QueueStatus> {
  try {
    const size = await queue.size();
    return { size, readable: true };
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    return { size: 0, readable: false, error: message };
  }
}

/**
 * Tear down auto-capture, flush the offline queue, reset state, clear the callback.
 * Mostly useful for tests and HMR. Production apps should not need to call this.
 * Safe to call when the SDK was never initialized.
 */
export async function shutdown(): Promise<void> {
  const state = getState();
  if (state.config) {
    await flushQueue(state.config);
  }
  uninstallAutoCapture();
  clearReportCallback();
  resetState();
  pendingBeforeInit = [];
  initialized = false;
}

export type {
  InitConfig,
  CaptureConfig,
  ErrorContext,
  ErrorLevel,
  ScrubRules,
  ReportEvent,
  Breadcrumb,
  QueueStatus,
} from './types.js';
