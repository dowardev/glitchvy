// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import type {
  Status,
  ErrorReportSummary,
  ErrorReportDetail,
  CursorPage,
  ErrorFilters,
  Project,
  ApiKey,
  CreateProjectRequest,
  CreateApiKeyRequest,
  CreateApiKeyResponse,
  LoginRequest,
  LoginResponse,
  User,
  GlitchvyApiError,
} from '@/types';

// The Vite dev server proxies the whole /api tree to Laravel, so the
// base is just /api and each call spells out its full path.
const API_BASE = import.meta.env.VITE_GLITCHVY_API_URL || '/api'

// Methods that change state. They carry the CSRF token: the session
// itself lives in an httpOnly cookie the SPA cannot read, and the XSRF
// token is its readable counterpart, echoed back in a header.
const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE']

class GlitchvyApiErrorImpl extends Error {
  public readonly status: number
  public readonly code: string

  constructor(status: number, code: string, message: string) {
    super(message)
    this.name = 'GlitchvyApiError'
    this.status = status
    this.code = code
  }
}

function isGlitchvyApiError(error: unknown): error is GlitchvyApiErrorImpl {
  return error instanceof GlitchvyApiErrorImpl
}

/**
 * Read a cookie value by name. The session cookie is httpOnly and is
 * therefore invisible here on purpose; only the XSRF token is readable.
 */
export function readCookie(name: string): string | null {
  if (typeof document === 'undefined' || !document.cookie) return null
  const prefix = `${name}=`
  const row = document.cookie.split(';').find((entry) => entry.trim().startsWith(prefix))
  return row ? decodeURIComponent(row.trim().slice(prefix.length)) : null
}

let csrfCookieRequest: Promise<void> | null = null

/**
 * Ask the API for the XSRF cookie, once. Every mutating request needs it
 * before the session cookie is accepted.
 */
export function ensureCsrfCookie(): Promise<void> {
  // The cookie is already there: nothing to bootstrap.
  if (readCookie('XSRF-TOKEN')) return Promise.resolve()

  if (!csrfCookieRequest) {
    csrfCookieRequest = request<{ ok: boolean }>('/glitchvy/csrf-cookie')
      .then(() => undefined)
      .catch((err) => {
        // A failed bootstrap must not poison the next attempt.
        csrfCookieRequest = null
        throw err
      })
  }
  return csrfCookieRequest
}

async function request<T>(
  endpoint: string,
  options: RequestInit = {},
): Promise<T> {
  const url = `${API_BASE}${endpoint}`
  const method = (options.method ?? 'GET').toUpperCase()
  const headers: Record<string, string> = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...(options.headers as Record<string, string> | undefined),
  }

  if (MUTATING_METHODS.includes(method)) {
    await ensureCsrfCookie()
    const xsrfToken = readCookie('XSRF-TOKEN')
    if (xsrfToken) {
      headers['X-XSRF-TOKEN'] = xsrfToken
    }
  }

  let response: Response
  try {
    response = await fetch(url, {
      ...options,
      headers,
      credentials: 'include',
    })
  } catch {
    // fetch() rejects when the proxy target is unreachable (e.g. the
    // Laravel backend is not running) or the browser is offline. Surface
    // it as a 0-status GlitchvyApiError so the UI can show a single
    // "cannot reach the server" message instead of an unhandled TypeError.
    throw new GlitchvyApiErrorImpl(0, 'NETWORK_UNREACHABLE', 'No se puede conectar al servidor')
  }

  if (!response.ok) {
    let code = 'UNKNOWN_ERROR'
    let message = response.statusText
    try {
      const errorData = await response.json()
      code = errorData.code || code
      message = errorData.message || message
    } catch {
      // Ignore JSON parse errors
    }
    throw new GlitchvyApiErrorImpl(response.status, code, message)
  }

  if (response.status === 204) {
    return undefined as T
  }

  return response.json()
}

export const api = {
  // Auth: /api/auth/*
  async login(credentials: LoginRequest): Promise<LoginResponse> {
    return request<LoginResponse>('/auth/login', {
      method: 'POST',
      body: JSON.stringify(credentials),
    })
  },

  async logout(): Promise<void> {
    return request<void>('/auth/logout', {
      method: 'POST',
    })
  },

  async getMe(): Promise<User> {
    return request<User>('/auth/me')
  },

  // Projects: /api/projects/*
  async listProjects(): Promise<Project[]> {
    return request<Project[]>('/projects')
  },

  async createProject(data: CreateProjectRequest): Promise<Project> {
    return request<Project>('/projects', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  },

  async listApiKeys(projectId: number): Promise<ApiKey[]> {
    return request<ApiKey[]>(`/projects/${projectId}/api-keys`)
  },

  async createApiKey(projectId: number, data: CreateApiKeyRequest): Promise<CreateApiKeyResponse> {
    return request<CreateApiKeyResponse>(`/projects/${projectId}/api-keys`, {
      method: 'POST',
      body: JSON.stringify(data),
    })
  },

  // Errors (admin view): /api/glitchvy/errors/*
  async listErrors(filters: ErrorFilters = {}): Promise<CursorPage<ErrorReportSummary>> {
    const params = new URLSearchParams()
    if (filters.level) params.set('level', filters.level)
    if (filters.status) params.set('status', filters.status)
    if (filters.search) params.set('search', filters.search)
    if (filters.project_id) params.set('project_id', String(filters.project_id))
    if (filters.cursor) params.set('cursor', filters.cursor)
    if (filters.per_page) params.set('per_page', String(filters.per_page))

    const query = params.toString()
    const endpoint = `/glitchvy/errors${query ? `?${query}` : ''}`
    return request<CursorPage<ErrorReportSummary>>(endpoint)
  },

  async getError(id: number): Promise<ErrorReportDetail> {
    return request<ErrorReportDetail>(`/glitchvy/errors/${id}`)
  },

  async updateStatus(id: number, status: Status): Promise<ErrorReportSummary> {
    return request<ErrorReportSummary>(`/glitchvy/errors/${id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ status }),
    })
  },

  screenshotUrl(id: number): string {
    return `${API_BASE}/glitchvy/errors/${id}/screenshot`
  },
}

export { GlitchvyApiErrorImpl as GlitchvyApiError, isGlitchvyApiError }
