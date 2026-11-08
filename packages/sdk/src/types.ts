// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Public type surface for the glitchvy SDK. Keep this file dependency-free
 * so consumers can `import type` without pulling the runtime in.
 */

export interface InitConfig {
  /** Required. The SaaS ingestion endpoint. e.g. `https://errors.dowar.xyz/api/glitchvy/errors` */
  ingestionUrl: string;
  /** Required. Per-project API key (Bearer token). */
  apiKey: string;
  /** Required. The consumer app's version. Used for grouping. */
  appVersion: string;
  /** Required. Free-form environment label (e.g. "production", "staging"). */
  environment: string;

  /** Optional. Scrub rule set; defaults redact common PII fields. */
  scrubRules?: ScrubRules;

  /** Optional. Max events the offline queue will hold. Default: 100. */
  maxQueueSize?: number;
  /** Optional. Per-event retry budget. Default: 3. */
  maxRetries?: number;
  /** Optional. Backoff base in ms; doubles each attempt. Default: 1000. */
  retryBaseMs?: number;

  /** Optional. Toggle which global auto-capture hooks are installed. */
  capture?: CaptureConfig;

  /** Optional. Screenshot upload endpoint (used by future SDK helpers). */
  screenshotUploadUrl?: string;
}

export interface CaptureConfig {
  onerror?: boolean;
  unhandledrejection?: boolean;
  consoleError?: boolean;
}

/**
 * Error severity levels accepted by the backend.
 * 'warn' is kept as a public alias for 'warning' for backwards compatibility.
 */
export type ErrorLevel = 'debug' | 'info' | 'warning' | 'error' | 'critical' | 'warn';

export interface ErrorContext {
  entityType?: string;
  entityId?: string | number;
  operation?: string;
  tags?: Record<string, string>;
  data?: Record<string, unknown>;
}

export interface ScrubRules {
  /** Keys (case-insensitive) redacted inside contexts in {@link ScrubRules.redactContexts}. */
  redactFields?: string[];
  /** Top-level subtrees where redactFields apply. */
  redactContexts?: string[];
}

export interface Breadcrumb {
  message: string;
  timestamp: string;
  data?: Record<string, unknown>;
}

/**
 * State of the offline queue.
 *
 * `readable: false` means the queue could not be read at all (IndexedDB
 * unavailable, blocked or corrupted) — `size` is then meaningless, not zero.
 */
export interface QueueStatus {
  /** Number of events persisted in the queue. Only meaningful when `readable` is true. */
  size: number;
  /** False when the queue could not be read. */
  readable: boolean;
  /** Why the queue could not be read, when `readable` is false. */
  error?: string;
}

export interface ReportEvent {
  eventId: string;
  timestamp: string;
  appVersion: string;
  environment: string;
  platform: string;
  /** Always a string; {@link readRoute} returns '/' as fallback, never null. */
  route: string;
  userAgent: string;
  level: ErrorLevel;
  error: {
    name: string;
    message: string;
    type?: string;
    stack?: string;
  };
  context: {
    entityType?: string;
    entityId?: string | number;
    operation?: string;
    tags?: Record<string, string>;
    data?: Record<string, unknown>;
  };
  user?: Record<string, unknown>;
  breadcrumbs: Breadcrumb[];
}
