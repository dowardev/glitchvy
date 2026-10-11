// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Public type surface for the glitchvy SDK.
 *
 * Keep this file dependency-free so consumers can `import type` from it without
 * pulling in the runtime.
 */

export interface InitConfig {
  /** Required. Where the SDK POSTs. URL-absolute or root-relative. */
  ingestionUrl: string;
  /** Required. Bearer token shared with the glitchvy-laravel server SDK. */
  apiKey: string;
  /** Required. The consumer app's version, used for error grouping. */
  appVersion: string;
  /** Required. Free-form environment label (e.g. "production", "staging"). */
  environment: string;

  /** Optional. Scrub rule set; defaults redact common PII fields. */
  scrubRules?: ScrubRules;

  /** Optional. Max events the offline queue will hold. Default: 100. */
  maxQueueSize?: number;
  /** Optional. Per-event retry budget. Default: 3. */
  maxRetries?: number;
  /** Optional. Backoff base (ms); doubles each attempt. Default: 1000. */
  retryBaseMs?: number;

  /** Optional. Toggle which global auto-capture hooks are installed. */
  capture?: CaptureConfig;

  /** Optional. Endpoint for screenshot uploads (used by future SDK helpers). */
  screenshotUploadUrl?: string;
}

export interface CaptureConfig {
  onerror?: boolean;
  unhandledrejection?: boolean;
  consoleError?: boolean;
}

export type ErrorLevel = 'error' | 'warn' | 'info';

export interface ErrorContext {
  entityType?: string;
  entityId?: string | number;
  operation?: string;
  tags?: Record<string, string>;
  data?: Record<string, unknown>;
}

export interface ScrubRules {
  /**
   * Keys (case-insensitive) to redact whenever we encounter them, and especially
   * inside the contexts listed in {@link ScrubRules.redactContexts}.
   */
  redactFields?: string[];
  /**
   * Top-level subtrees of the payload in which {@link ScrubRules.redactFields}
   * apply. A field whose **parent** is one of these contexts gets redacted.
   */
  redactContexts?: string[];
}

export interface Breadcrumb {
  message: string;
  timestamp: string;
  data?: Record<string, unknown>;
}

export interface ReportEvent {
  eventId: string;
  timestamp: string;
  appVersion: string;
  environment: string;
  platform: string;
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
