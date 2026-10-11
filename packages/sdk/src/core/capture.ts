// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Auto-capture layer: install (and remove) global hooks for `error`,
 * `unhandledrejection`, and `console.error`. The capture module does not
 * know how to send — it forwards to a callback registered by the public API.
 */

import type { CaptureConfig, ErrorContext, ErrorLevel } from '../types.js';

export type ReportCallback = (
  err: unknown,
  context?: ErrorContext,
  level?: ErrorLevel,
) => Promise<void> | void;

let reportCb: ReportCallback | null = null;

export function setReportCallback(fn: ReportCallback): void {
  reportCb = fn;
}

export function clearReportCallback(): void {
  reportCb = null;
}

function getGlobal(): Window | undefined {
  if (typeof window !== 'undefined') return window;
  return undefined;
}

let installed = false;
let prevConsoleError: typeof console.error | null = null;
/** Best-effort storage so uninstall can remove the listeners we added. */
let errorListener: ((ev: Event) => void) | null = null;
let rejectionListener: ((ev: Event) => void) | null = null;
let onlineListener: (() => void) | null = null;

export function installAutoCapture(
  cfg: CaptureConfig | undefined,
  onOnline?: () => void,
): void {
  if (installed) return;
  installed = true;

  const win = getGlobal();
  if (!win) return; // SSR/Node: nothing to install.

  const capture: Required<CaptureConfig> = {
    onerror: cfg?.onerror ?? true,
    unhandledrejection: cfg?.unhandledrejection ?? true,
    consoleError: cfg?.consoleError ?? true,
  };

  if (capture.onerror) {
    errorListener = (ev: Event) => {
      const e = ev as ErrorEvent;
      const err = (e.error as unknown) ?? new Error(e.message || 'window.onerror');
      void reportCb?.(err, { tags: { source: 'window.onerror' } });
    };
    win.addEventListener('error', errorListener);
  }

  if (capture.unhandledrejection) {
    rejectionListener = (ev: Event) => {
      const e = ev as PromiseRejectionEvent;
      const reason = e.reason as unknown;
      const err =
        reason instanceof Error
          ? reason
          : new Error(
              typeof reason === 'string'
                ? reason
                : safeStringify(reason),
            );
      void reportCb?.(err, { tags: { source: 'unhandledrejection' } });
    };
    win.addEventListener('unhandledrejection', rejectionListener);
  }

  if (capture.consoleError && typeof console !== 'undefined' && console.error) {
    // Hold the original reference (no .bind — console.error doesn't rely on `this`)
    // so uninstall restores the exact same function reference.
    prevConsoleError = console.error;
    console.error = (...args: unknown[]) => {
      const err =
        args.length > 0 && args[0] instanceof Error
          ? args[0]
          : new Error(
              args
                .map((a) => (typeof a === 'string' ? a : safeStringify(a)))
                .join(' '),
            );
      void reportCb?.(err, { tags: { source: 'console.error' } });
      prevConsoleError?.(...(args as Parameters<typeof console.error>));
    };
  }

  if (onOnline) {
    onlineListener = () => onOnline();
    win.addEventListener('online', onlineListener);
  }
}

export function uninstallAutoCapture(): void {
  if (!installed) return;
  installed = false;

  const win = getGlobal();
  if (win) {
    if (errorListener) win.removeEventListener('error', errorListener);
    if (rejectionListener) win.removeEventListener('unhandledrejection', rejectionListener);
    if (onlineListener) win.removeEventListener('online', onlineListener);
    if (prevConsoleError && typeof console !== 'undefined') {
      console.error = prevConsoleError;
    }
  }
  errorListener = null;
  rejectionListener = null;
  onlineListener = null;
  prevConsoleError = null;
  reportCb = null;
}

function safeStringify(v: unknown): string {
  try {
    if (typeof v === 'string') return v;
    return JSON.stringify(v);
  } catch {
    return String(v);
  }
}
