// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Vitest setup: loads `fake-indexeddb` and resets module state between tests.
 */

import 'fake-indexeddb/auto';

import { afterEach, beforeEach } from 'vitest';
import { resetState, getState } from '../src/core/state.js';
import * as queue from '../src/core/queue.js';

beforeEach(() => {
  if (typeof localStorage !== 'undefined') {
    localStorage.clear();
  }
});

afterEach(async () => {
  resetState();
  try {
    await queue.clear();
  } catch {
    // ignore
  }
  queue.__resetDb();
  void getState;
});
