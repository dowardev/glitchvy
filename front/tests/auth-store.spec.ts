// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { beforeEach, describe, expect, it } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

import { useAuthStore } from '../src/stores/auth.js';

describe('useAuthStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    if (typeof localStorage !== 'undefined') localStorage.clear();
  });

  it('starts unauthenticated', () => {
    const auth = useAuthStore();
    expect(auth.isAuthenticated).toBe(false);
    expect(auth.token).toBeNull();
  });

  it('setToken persists and updates isAuthenticated', () => {
    const auth = useAuthStore();
    auth.setToken('abc');
    expect(auth.token).toBe('abc');
    expect(auth.isAuthenticated).toBe(true);
    expect(localStorage.getItem('glitchvy-admin-token')).toBe('abc');
  });

  it('clear() removes the token and updates isAuthenticated', () => {
    const auth = useAuthStore();
    auth.setToken('abc');
    auth.clear();
    expect(auth.token).toBeNull();
    expect(auth.isAuthenticated).toBe(false);
    expect(localStorage.getItem('glitchvy-admin-token')).toBeNull();
  });

  it('reads the token from localStorage on store init', () => {
    localStorage.setItem('glitchvy-admin-token', 'persistent');
    const fresh = useAuthStore();
    expect(fresh.token).toBe('persistent');
    expect(fresh.isAuthenticated).toBe(true);
  });
});
