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

async function request<T>(
  endpoint: string,
  options: RequestInit = {},
  token?: string
): Promise<T> {
  const url = `${API_BASE}${endpoint}`
  const headers: HeadersInit = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...options.headers,
  }

  if (token) {
    ;(headers as Record<string, string>)['Authorization'] = `Bearer ${token}`
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

  async logout(token: string): Promise<void> {
    return request<void>('/auth/logout', {
      method: 'POST',
    }, token)
  },

  async getMe(token: string): Promise<User> {
    return request<User>('/auth/me', {}, token)
  },

  // Projects: /api/projects/*
  async listProjects(token: string): Promise<Project[]> {
    return request<Project[]>('/projects', {}, token)
  },

  async createProject(data: CreateProjectRequest, token: string): Promise<Project> {
    return request<Project>('/projects', {
      method: 'POST',
      body: JSON.stringify(data),
    }, token)
  },

  async listApiKeys(projectId: number, token: string): Promise<ApiKey[]> {
    return request<ApiKey[]>(`/projects/${projectId}/api-keys`, {}, token)
  },

  async createApiKey(projectId: number, data: CreateApiKeyRequest, token: string): Promise<CreateApiKeyResponse> {
    return request<CreateApiKeyResponse>(`/projects/${projectId}/api-keys`, {
      method: 'POST',
      body: JSON.stringify(data),
    }, token)
  },

  // Errors (admin view): /api/glitchvy/errors/*
  async listErrors(filters: ErrorFilters = {}, token: string): Promise<CursorPage<ErrorReportSummary>> {
    const params = new URLSearchParams()
    if (filters.level) params.set('level', filters.level)
    if (filters.status) params.set('status', filters.status)
    if (filters.search) params.set('search', filters.search)
    if (filters.project_id) params.set('project_id', String(filters.project_id))
    if (filters.cursor) params.set('cursor', filters.cursor)
    if (filters.limit) params.set('limit', String(filters.limit))

    const query = params.toString()
    const endpoint = `/glitchvy/errors${query ? `?${query}` : ''}`
    return request<CursorPage<ErrorReportSummary>>(endpoint, {}, token)
  },

  async getError(id: number, token: string): Promise<ErrorReportDetail> {
    return request<ErrorReportDetail>(`/glitchvy/errors/${id}`, {}, token)
  },

  async updateStatus(id: number, status: Status, token: string): Promise<ErrorReportSummary> {
    return request<ErrorReportSummary>(`/glitchvy/errors/${id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ status }),
    }, token)
  },

  screenshotUrl(id: number): string {
    return `${API_BASE}/glitchvy/errors/${id}/screenshot`
  },
}

export { GlitchvyApiErrorImpl as GlitchvyApiError, isGlitchvyApiError }
