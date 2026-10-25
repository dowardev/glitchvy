// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Defensive env detection. SSR-safe (no-op when not in a browser).
 */

function readGlobal(key: string): unknown {
  return (globalThis as Record<string, unknown>)[key];
}

/** Platform label. Returns 'web' today; future versions may detect Capacitor / Electron. */
export function readPlatform(): string {
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
