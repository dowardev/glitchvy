// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Singleton module-scoped state. The SDK is process-singleton (one
 * initializer per app instance). Exposed for tests; tests reset via
 * {@link resetState}.
 */

import type { Breadcrumb, InitConfig } from '../types.js';
import type { ScrubContext } from './scrub.js';

export interface State {
  config: InitConfig | null;
  scrubCtx: ScrubContext | null;
  user: Record<string, unknown>;
  breadcrumbs: Breadcrumb[];
}

const state: State = {
  config: null,
  scrubCtx: null,
  user: {},
  breadcrumbs: [],
};

export function getState(): State {
  return state;
}

export function resetState(): void {
  state.config = null;
  state.scrubCtx = null;
  state.user = {};
  state.breadcrumbs = [];
}

export function pushBreadcrumb(b: Breadcrumb, max = 50): void {
  state.breadcrumbs.push(b);
  if (state.breadcrumbs.length > max) {
    state.breadcrumbs.splice(0, state.breadcrumbs.length - max);
  }
}
