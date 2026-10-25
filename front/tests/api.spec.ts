// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// Mock fetch globally
const mockFetch = vi.fn()
global.fetch = mockFetch

// Import after mocking
import { api, GlitchvyApiError, isGlitchvyApiError } from '@/api/client'

describe('api client', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    vi.stubEnv('VITE_GLITCHVY_API_URL', '/api')
  })

  afterEach(() => {
    vi.unstubAllEnvs()
  })

  it('sends correct headers for login', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: { id: 1, email: 'test@test.com', name: 'Test', created_at: '', updated_at: '' } }),
    })

    await api.login({ email: 'test@test.com', password: 'password' })

    expect(mockFetch).toHaveBeenCalledWith(
      '/api/auth/login',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        }),
        credentials: 'include',
        body: JSON.stringify({ email: 'test@test.com', password: 'password' }),
      })
    )
  })

  it('includes Authorization header when token provided', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => [],
    })

    await api.listProjects('test-token')

    expect(mockFetch).toHaveBeenCalledWith(
      '/api/projects',
      expect.objectContaining({
        headers: expect.objectContaining({
          'Authorization': 'Bearer test-token',
        }),
      })
    )
  })

  it('throws GlitchvyApiError on non-ok response', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: false,
      status: 401,
      statusText: 'Unauthorized',
      json: async () => ({ code: 'UNAUTHENTICATED', message: 'Invalid credentials' }),
    })

    let caughtError: unknown
    try {
      await api.login({ email: 'test@test.com', password: 'wrong' })
    } catch (err) {
      caughtError = err
    }

    expect(caughtError).toBeInstanceOf(Error)
    expect((caughtError as Error).name).toBe('GlitchvyApiError')
    expect((caughtError as any).status).toBe(401)
    expect((caughtError as any).code).toBe('UNAUTHENTICATED')
    expect((caughtError as any).message).toBe('Invalid credentials')
  })

  it('builds query params for listErrors', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ data: [], next_cursor: null, prev_cursor: null }),
    })

    await api.listErrors({ level: 'error', status: 'open', search: 'test', project_id: 1, cursor: 'abc', limit: 10 }, 'token')

    expect(mockFetch).toHaveBeenCalledWith(
      '/api/glitchvy/errors?level=error&status=open&search=test&project_id=1&cursor=abc&limit=10',
      expect.any(Object)
    )
  })

  it('screenshotUrl returns correct URL', () => {
    const url = api.screenshotUrl(123)
    expect(url).toBe('/api/glitchvy/errors/123/screenshot')
  })

  it('handles missing error response body', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: false,
      status: 500,
      statusText: 'Internal Server Error',
      json: async () => { throw new Error('No JSON') },
    })

    let caughtError: unknown
    try {
      await api.getMe('token')
    } catch (err) {
      caughtError = err
    }

    expect(caughtError).toBeInstanceOf(Error)
    expect((caughtError as Error).name).toBe('GlitchvyApiError')
    expect((caughtError as any).status).toBe(500)
    expect((caughtError as any).code).toBe('UNKNOWN_ERROR')
    expect((caughtError as any).message).toBe('Internal Server Error')
  })
})
