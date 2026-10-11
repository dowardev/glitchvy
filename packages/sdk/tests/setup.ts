// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Vitest setup file. Loads `fake-indexeddb` once per test file and resets the
 * shared module state from `./src/core/state.ts` between tests.
 */

import 'fake-indexeddb/auto';

import { afterEach } from 'vitest';
import { resetState, getState } from '../src/core/state.js';
import * as queue from '../src/core/queue.js';

afterEach(async () => {
  resetState();
  try {
    // Empty the IDB store between tests so persisted counts are deterministic.
    await queue.clear();
  } catch {
    // ignore — IDB may be unavailable in some tests
  }
  // Reset the lazy IDB connection so the next test re-opens a clean DB.
  queue.__resetDb();
  // Suppress an "unused" warning from lint on the `getState` import side-effect.
  void getState;
});
