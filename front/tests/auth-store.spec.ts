// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/api/client', () => {
  const GlitchvyApiError = class extends Error {
    constructor(public status: number, public code: string, message: string) {
      super(message)
      this.name = 'GlitchvyApiError'
    }
  }
  return {
    api: {
      login: vi.fn(),
      logout: vi.fn(),
      getMe: vi.fn(),
    },
    isGlitchvyApiError: (err: unknown): err is InstanceType<typeof GlitchvyApiError> => err instanceof GlitchvyApiError,
    GlitchvyApiError,
  }
})

import { api, GlitchvyApiError } from '@/api/client'

const fakeUser = { id: 1, email: 'test@test.com', name: 'Test', created_at: '', updated_at: '' }

describe('auth store', () => {
  let authStore: ReturnType<typeof import('@/stores/auth').useAuthStore>

  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
    localStorage.clear()
    const { useAuthStore } = await import('@/stores/auth')
    authStore = useAuthStore()
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('initializes with no user', () => {
    expect(authStore.user).toBeNull()
    expect(authStore.isAuthenticated).toBe(false)
    expect(authStore.ready).toBe(false)
  })

  it('logs in successfully', async () => {
    vi.mocked(api.login).mockResolvedValueOnce({ user: fakeUser, token: null })

    await authStore.login({ email: 'test@test.com', password: 'password' })

    expect(authStore.user).toEqual(fakeUser)
    expect(authStore.isAuthenticated).toBe(true)
    expect(authStore.loading).toBe(false)
    expect(authStore.error).toBeNull()
  })

  it('never stores the credential in the browser', async () => {
    vi.mocked(api.login).mockResolvedValueOnce({ user: fakeUser, token: null })

    await authStore.login({ email: 'test@test.com', password: 'password' })

    expect(localStorage.getItem('glitchvy_token')).toBeNull()
    expect(localStorage.getItem('glitchvy_user')).toBeNull()
  })

  it('handles login error with GlitchvyApiError', async () => {
    const error = new GlitchvyApiError(401, 'UNAUTHENTICATED', 'Invalid credentials')
    vi.mocked(api.login).mockRejectedValueOnce(error)

    await expect(authStore.login({ email: 'test@test.com', password: 'wrong' })).rejects.toThrow()

    expect(authStore.user).toBeNull()
    expect(authStore.isAuthenticated).toBe(false)
    expect(authStore.error).toBe('Invalid credentials')
    expect(authStore.loading).toBe(false)
  })

  it('handles login error with generic error', async () => {
    const error = new Error('Network error')
    vi.mocked(api.login).mockRejectedValueOnce(error)

    await expect(authStore.login({ email: 'test@test.com', password: 'wrong' })).rejects.toThrow()

    expect(authStore.error).toBe('Error de conexión')
  })

  it('logs out and clears the user', async () => {
    authStore.user = fakeUser

    vi.mocked(api.logout).mockResolvedValueOnce(undefined)

    await authStore.logout()

    expect(authStore.user).toBeNull()
    expect(authStore.isAuthenticated).toBe(false)
    expect(localStorage.getItem('glitchvy_token')).toBeNull()
    expect(localStorage.getItem('glitchvy_user')).toBeNull()
  })

  it('restores the session from the cookie on boot', async () => {
    vi.mocked(api.getMe).mockResolvedValueOnce(fakeUser)

    await authStore.restore()

    expect(api.getMe).toHaveBeenCalledTimes(1)
    expect(authStore.user).toEqual(fakeUser)
    expect(authStore.isAuthenticated).toBe(true)
    expect(authStore.ready).toBe(true)
  })

  it('restores the session only once', async () => {
    vi.mocked(api.getMe).mockResolvedValue(fakeUser)

    await authStore.restore()
    await authStore.restore()

    expect(api.getMe).toHaveBeenCalledTimes(1)
  })

  it('stays logged out when the cookie session is gone', async () => {
    vi.mocked(api.getMe).mockRejectedValueOnce(new Error('Unauthorized'))

    await authStore.restore()

    expect(authStore.user).toBeNull()
    expect(authStore.isAuthenticated).toBe(false)
    expect(authStore.ready).toBe(true)
  })

  it('fetches me and updates user', async () => {
    authStore.user = fakeUser
    vi.mocked(api.getMe).mockResolvedValueOnce(fakeUser)

    await authStore.fetchMe()

    expect(authStore.user).toEqual(fakeUser)
  })

  it('clears the user on 401 from fetchMe', async () => {
    const error = new GlitchvyApiError(401, 'UNAUTHENTICATED', 'Unauthorized')
    authStore.user = fakeUser
    vi.mocked(api.getMe).mockRejectedValueOnce(error)

    await expect(authStore.fetchMe()).rejects.toThrow()

    expect(authStore.user).toBeNull()
  })
})
