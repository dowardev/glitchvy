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
const fakeToken = '1|fake-sanctum-token-abcdef123'

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
    expect(authStore.token).toBeNull()
    expect(authStore.isAuthenticated).toBe(false)
  })

  it('logs in successfully', async () => {
    vi.mocked(api.login).mockResolvedValueOnce({ user: fakeUser, token: fakeToken })

    await authStore.login({ email: 'test@test.com', password: 'password' })

    expect(authStore.user).toEqual(fakeUser)
    expect(authStore.token).toBe(fakeToken)
    expect(authStore.isAuthenticated).toBe(true)
    expect(localStorage.getItem('glitchvy_user')).toBe(JSON.stringify(fakeUser))
    expect(localStorage.getItem('glitchvy_token')).toBe(fakeToken)
    expect(authStore.loading).toBe(false)
    expect(authStore.error).toBeNull()
  })

  it('handles login error with GlitchvyApiError', async () => {
    const error = new GlitchvyApiError(401, 'UNAUTHENTICATED', 'Invalid credentials')
    vi.mocked(api.login).mockRejectedValueOnce(error)

    await expect(authStore.login({ email: 'test@test.com', password: 'wrong' })).rejects.toThrow()

    expect(authStore.user).toBeNull()
    expect(authStore.token).toBeNull()
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

  it('logs out and clears user/token', async () => {
    authStore.user = fakeUser
    authStore.token = fakeToken
    localStorage.setItem('glitchvy_user', JSON.stringify(fakeUser))
    localStorage.setItem('glitchvy_token', fakeToken)

    vi.mocked(api.logout).mockResolvedValueOnce(undefined)

    await authStore.logout()

    expect(authStore.user).toBeNull()
    expect(authStore.token).toBeNull()
    expect(authStore.isAuthenticated).toBe(false)
    expect(localStorage.getItem('glitchvy_user')).toBeNull()
    expect(localStorage.getItem('glitchvy_token')).toBeNull()
  })

  it('fetches me and updates user', async () => {
    authStore.user = fakeUser
    authStore.token = fakeToken
    vi.mocked(api.getMe).mockResolvedValueOnce(fakeUser)

    await authStore.fetchMe()

    expect(authStore.user).toEqual(fakeUser)
  })

  it('clears user+token on 401 from fetchMe', async () => {
    const error = new GlitchvyApiError(401, 'UNAUTHENTICATED', 'Unauthorized')
    authStore.user = fakeUser
    authStore.token = fakeToken
    localStorage.setItem('glitchvy_user', JSON.stringify(authStore.user))
    localStorage.setItem('glitchvy_token', authStore.token)
    vi.mocked(api.getMe).mockRejectedValueOnce(error)

    await expect(authStore.fetchMe()).rejects.toThrow()

    expect(authStore.user).toBeNull()
    expect(authStore.token).toBeNull()
  })

  it('does not fetch me if no token', async () => {
    authStore.user = fakeUser
    authStore.token = null
    await authStore.fetchMe()
    expect(api.getMe).not.toHaveBeenCalled()
  })

  it('restores session from localStorage on init', async () => {
    setActivePinia(createPinia())
    localStorage.setItem('glitchvy_user', JSON.stringify(fakeUser))
    localStorage.setItem('glitchvy_token', fakeToken)
    const { useAuthStore } = await import('@/stores/auth')
    const fresh = useAuthStore()
    expect(fresh.user).toEqual(fakeUser)
    expect(fresh.token).toBe(fakeToken)
    expect(fresh.isAuthenticated).toBe(true)
  })
})
