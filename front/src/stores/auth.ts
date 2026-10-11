// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Auth store. Tracks the `X-Glitchvy-User-Token` for the admin SPA.
 * Persists across reloads via localStorage.
 */

import { defineStore } from 'pinia';

const STORAGE_KEY = 'glitchvy-admin-token';

function readPersistedToken(): string | null {
  if (typeof localStorage === 'undefined') return null;
  try {
    return localStorage.getItem(STORAGE_KEY);
  } catch {
    return null;
  }
}

function persistToken(token: string | null): void {
  if (typeof localStorage === 'undefined') return;
  try {
    if (token === null) localStorage.removeItem(STORAGE_KEY);
    else localStorage.setItem(STORAGE_KEY, token);
  } catch {
    // localStorage may be disabled (private mode); ignore.
  }
}

export interface AuthState {
  token: string | null;
}

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    token: readPersistedToken(),
  }),

  getters: {
    isAuthenticated: (state) => state.token !== null,
  },

  actions: {
    setToken(token: string | null): void {
      this.token = token;
      persistToken(token);
    },
    clear(): void {
      this.setToken(null);
    },
  },
});
