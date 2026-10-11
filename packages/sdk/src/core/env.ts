// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Defensive env detection for browser globals. SSR-safe (all reads no-op when
 * the runtime is not a browser).
 */

type AnyGlobal = Record<string, unknown>;

function readGlobal(key: string): unknown {
  return (globalThis as unknown as AnyGlobal)[key];
}

/**
 * The platform label attached to every report. Today it always returns
 * `'web'`; future versions will branch on Capacitor / Cordova / Electron.
 */
export function readPlatform(): string {
  // Reserved hook for native consumers; intentionally a constant for now.
  return 'web';
}

export function readRoute(): string {
  const win = readGlobal('window') as { location?: { pathname?: unknown } } | undefined;
  if (!win || typeof win !== 'object' || !win.location) {
    return '/';
  }
  const p = win.location.pathname;
  return typeof p === 'string' && p.length > 0 ? p : '/';
}

export function readUserAgent(): string {
  const nav = readGlobal('navigator') as { userAgent?: unknown } | undefined;
  if (!nav || typeof nav !== 'object') return '';
  const ua = nav.userAgent;
  return typeof ua === 'string' ? ua : '';
}
