// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Vitest setup: a clean localStorage and a reset fetch between tests.
 */

import { afterEach, beforeEach } from 'vitest';

beforeEach(() => {
  if (typeof localStorage !== 'undefined') {
    localStorage.clear();
  }
});

afterEach(() => {
  if (typeof localStorage !== 'undefined') {
    localStorage.clear();
  }
});
