// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { describe, it, expect, vi } from 'vitest';

// The queue is made unavailable, so the flush inside `shutdown()` fails.
vi.mock('../src/core/report.js', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../src/core/report.js')>();
  return {
    ...actual,
    flushQueue: vi.fn(async () => {
      throw new Error('queue unavailable');
    }),
  };
});

import { initErrorReporter, shutdown } from '../src/index.js';

describe('shutdown', () => {
  it('never rejects when the flush fails', async () => {
    initErrorReporter({
      ingestionUrl: '/api/glitchvy/errors',
      apiKey: 'gv_test',
      appVersion: '1.0.0',
      environment: 'test',
    });

    // A consumer that calls `shutdown()` without awaiting it (HMR, tests)
    // must not get an unhandled rejection.
    await expect(shutdown()).resolves.toBeUndefined();
  });
});
