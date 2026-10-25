// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { describe, it, expect } from 'vitest';
import { readPlatform, readRoute, readUserAgent } from '../src/core/env.js';

describe('env', () => {
  it('readPlatform() returns "web" today', () => {
    expect(readPlatform()).toBe('web');
  });

  it('readRoute() returns a string starting with "/"', () => {
    expect(readRoute()).toMatch(/^\//);
  });

  it('readUserAgent() returns a non-empty string under jsdom', () => {
    const ua = readUserAgent();
    expect(typeof ua).toBe('string');
    expect(ua.length).toBeGreaterThan(0);
  });

  it('readRoute() is SSR-safe (returns "/" without window)', () => {
    const originalWindow = (globalThis as Record<string, unknown>)['window'];
    (globalThis as Record<string, unknown>)['window'] = undefined;
    try {
      expect(readRoute()).toBe('/');
    } finally {
      (globalThis as Record<string, unknown>)['window'] = originalWindow;
    }
  });
});
