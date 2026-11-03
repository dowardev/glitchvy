// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// Mock fetch globally
const mockFetch = vi.fn()
global.fetch = mockFetch

// The client keeps module-level state (the CSRF bootstrap promise), so
// every test loads a fresh module instance.
async function loadClient() {
  vi.resetModules()
  return import('@/api/client')
}

function clearCookies(): void {
  for (const entry of document.cookie.split(';')) {
    const name = entry.split('=')[0]?.trim()
    if (name) {
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`
    }
  }
}

describe('api client', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    vi.stubEnv('VITE_GLITCHVY_API_URL', '/api')
    clearCookies()
  })

  afterEach(() => {
    vi.unstubAllEnvs()
  })

  it('sends correct headers for login', async () => {
    const { api } = await loadClient()
    mockFetch.mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ user: { id: 1, email: 'test@test.com', name: 'Test', created_at: '', updated_at: '' }, token: null }),
    })

    await api.login({ email: 'test@test.com', password: 'password' })

    const loginCall = mockFetch.mock.calls.find(([url]) => url === '/api/auth/login')
    expect(loginCall).toBeDefined()
    const [url, options] = loginCall!
    expect(options).toEqual(expect.objectContaining({
      method: 'POST',
      headers: expect.objectContaining({
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      }),
      credentials: 'include',
      body: JSON.stringify({ email: 'test@test.com', password: 'password' }),
    }))
  })

  it('never sends the session token: the cookie carries the session', async () => {
    const { api } = await loadClient()
    mockFetch.mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ id: 1, email: 'test@test.com', name: 'Test', created_at: '', updated_at: '' }),
    })

    await api.getMe()

    const [, options] = mockFetch.mock.calls[0]
    expect(options.headers).not.toHaveProperty('Authorization')
    expect(options.credentials).toBe('include')
  })

  it('bootstraps the CSRF cookie before a mutating request and echoes the token', async () => {
    const { api } = await loadClient()
    mockFetch.mockImplementation(async (url: string) => {
      if (url === '/api/glitchvy/csrf-cookie') {
        document.cookie = 'XSRF-TOKEN=token-value; path=/'
        return { ok: true, status: 200, json: async () => ({ ok: true }) }
      }
      return { ok: true, status: 200, json: async () => ({ message: 'Logged out' }) }
    })

    await api.logout()

    expect(mockFetch.mock.calls[0][0]).toBe('/api/glitchvy/csrf-cookie')
    const logoutCall = mockFetch.mock.calls.find(([url]) => url === '/api/auth/logout')
    expect(logoutCall).toBeDefined()
    expect(logoutCall![1].headers['X-XSRF-TOKEN']).toBe('token-value')
  })

  it('throws GlitchvyApiError on non-ok response', async () => {
    const { api, GlitchvyApiError } = await loadClient()
    mockFetch.mockResolvedValue({
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
    const { api } = await loadClient()
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ data: [], next_cursor: null, prev_cursor: null }),
    })

    await api.listErrors({ level: 'error', status: 'open', search: 'test', project_id: 1, cursor: 'abc', per_page: 10 })

    expect(mockFetch).toHaveBeenCalledWith(
      '/api/glitchvy/errors?level=error&status=open&search=test&project_id=1&cursor=abc&per_page=10',
      expect.any(Object)
    )
  })

  it('sends the page size as per_page, the parameter the API reads', async () => {
    const { api } = await loadClient()
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ data: [], next_cursor: null, prev_cursor: null }),
    })

    await api.listErrors({ per_page: 50 })

    const [url] = mockFetch.mock.calls[0]
    expect(url).toContain('per_page=50')
    expect(url).not.toContain('limit=')
  })

  it('retries a mutating request once with a fresh CSRF token after a 419', async () => {
    const { api } = await loadClient()

    let calls = 0
    mockFetch.mockImplementation(async (url: string) => {
      if (url === '/api/glitchvy/csrf-cookie') {
        document.cookie = 'XSRF-TOKEN=stale-token; path=/'
        return { ok: true, status: 200, json: async () => ({ ok: true }) }
      }
      calls++
      if (calls === 1) {
        return { ok: false, status: 419, statusText: 'CSRF token mismatch', json: async () => ({ message: 'CSRF token mismatch.' }) }
      }
      return { ok: true, status: 200, json: async () => ({ message: 'Logged out' }) }
    })

    await api.logout()

    expect(calls).toBe(2)
  })

  it('screenshotUrl returns correct URL', async () => {
    const { api } = await loadClient()
    const url = api.screenshotUrl(123)
    expect(url).toBe('/api/glitchvy/errors/123/screenshot')
  })

  it('handles missing error response body', async () => {
    const { api } = await loadClient()
    mockFetch.mockResolvedValue({
      ok: false,
      status: 500,
      statusText: 'Internal Server Error',
      json: async () => { throw new Error('No JSON') },
    })

    let caughtError: unknown
    try {
      await api.getMe()
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
