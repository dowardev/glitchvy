// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, isGlitchvyApiError } from '@/api/client'
import type { User, LoginRequest } from '@/types'

/**
 * Admin session store.
 *
 * The session token lives in an httpOnly cookie the browser attaches on
 * its own: this store never reads, writes or persists a credential. The
 * only thing kept in memory is the user profile, and it is restored from
 * the cookie session on boot.
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  /** True once the session restore attempt finished (successfully or not). */
  const ready = ref(false)

  const isAuthenticated = computed(() => !!user.value)

  let restorePromise: Promise<void> | null = null

  /**
   * Restore the session from the httpOnly cookie. Safe to call many
   * times: the first call wins and the result is memoized.
   */
  async function restore(): Promise<void> {
    if (!restorePromise) {
      restorePromise = (async () => {
        try {
          user.value = await api.getMe()
        } catch {
          // No valid session: the SPA stays logged out.
          user.value = null
        } finally {
          ready.value = true
        }
      })()
    }
    return restorePromise
  }

  async function login(credentials: LoginRequest): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const response = await api.login(credentials)
      user.value = response.user
    } catch (err) {
      if (isGlitchvyApiError(err)) {
        error.value = err.message
      } else {
        error.value = 'Error de conexión';
      }
      throw err;
    } finally {
      loading.value = false;
    }
  }

  async function logout(): Promise<void> {
    loading.value = true;
    try {
      await api.logout();
    } catch {
      // Ignore logout errors
    } finally {
      user.value = null;
      loading.value = false;
    }
  }

  async function fetchMe(): Promise<void> {
    loading.value = true;
    try {
      user.value = await api.getMe();
    } catch (err) {
      if (isGlitchvyApiError(err) && err.status === 401) {
        user.value = null;
      }
      throw err;
    } finally {
      loading.value = false;
    }
  }

  return {
    user,
    loading,
    error,
    ready,
    isAuthenticated,
    restore,
    login,
    logout,
    fetchMe,
  };
});
