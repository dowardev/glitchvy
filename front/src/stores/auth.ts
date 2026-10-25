// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, isGlitchvyApiError } from '@/api/client'
import type { User, LoginRequest } from '@/types'

const TOKEN_KEY = 'glitchvy_token'
const USER_KEY = 'glitchvy_user'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const token = ref<string | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  const isAuthenticated = computed(() => !!user.value && !!token.value)

  function loadPersisted(): void {
    const storedToken = localStorage.getItem(TOKEN_KEY)
    const storedUser = localStorage.getItem(USER_KEY)
    if (storedToken && storedUser) {
      try {
        token.value = storedToken
        user.value = JSON.parse(storedUser)
      } catch {
        localStorage.removeItem(TOKEN_KEY)
        localStorage.removeItem(USER_KEY)
      }
    }
  }

  async function login(credentials: LoginRequest): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const response = await api.login(credentials)
      user.value = response.user
      token.value = response.token
      localStorage.setItem(USER_KEY, JSON.stringify(response.user))
      localStorage.setItem(TOKEN_KEY, response.token)
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
    const currentToken = token.value;
    loading.value = true;
    try {
      if (currentToken) {
        await api.logout(currentToken);
      }
    } catch {
      // Ignore logout errors
    } finally {
      user.value = null;
      token.value = null;
      localStorage.removeItem(USER_KEY);
      localStorage.removeItem(TOKEN_KEY);
      loading.value = false;
    }
  }

  async function fetchMe(): Promise<void> {
    const currentToken = token.value;
    if (!currentToken) return;
    loading.value = true;
    try {
      const me = await api.getMe(currentToken);
      user.value = me;
      localStorage.setItem(USER_KEY, JSON.stringify(me));
    } catch (err) {
      if (isGlitchvyApiError(err) && err.status === 401) {
        user.value = null;
        token.value = null;
        localStorage.removeItem(USER_KEY);
        localStorage.removeItem(TOKEN_KEY);
      }
      throw err;
    } finally {
      loading.value = false;
    }
  }

  loadPersisted();

  return {
    user,
    token,
    loading,
    error,
    isAuthenticated,
    login,
    logout,
    fetchMe,
  };
});
