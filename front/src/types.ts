// SPDX-License-Identifier: MIT
// Copyright (c) 2024 Glitchvy

export type Level = 'debug' | 'info' | 'warning' | 'error' | 'critical'

export type Status = 'open' | 'in_progress' | 'resolved' | 'ignored'

export interface ErrorReportSummary {
  id: number
  project_id: number
  level: Level
  message: string
  type: string
  status: Status
  count: number
  last_seen_at: string
  first_seen_at: string
}

export interface ErrorReportDetail extends ErrorReportSummary {
  stack_trace: string | null
  breadcrumbs: Breadcrumb[]
  context: Record<string, unknown>
  tags: Record<string, string>
  screenshots: ScreenshotMeta[]
}

export interface CursorPage<T> {
  data: T[]
  next_cursor: string | null
  prev_cursor: string | null
}

export interface ErrorFilters {
  level?: Level
  status?: Status
  search?: string
  project_id?: number
  cursor?: string
  limit?: number
}

export interface Breadcrumb {
  timestamp: string
  type: string
  category: string
  message: string
  data?: Record<string, unknown>
  level?: Level
}

export interface ScreenshotMeta {
  id: number
  error_report_id: number
  filename: string
  content_type: string
  size_bytes: number
  created_at: string
  url: string
}

export interface Project {
  id: number
  name: string
  slug: string
  created_at: string
  updated_at: string
  api_keys_count?: number
}

export interface ApiKey {
  id: number
  project_id: number
  name: string
  prefix: string
  last_used_at: string | null
  created_at: string
}

export interface CreateProjectRequest {
  name: string
}

export interface CreateApiKeyRequest {
  name: string
}

export interface CreateApiKeyResponse {
  api_key: ApiKey
  plaintext: string
}

export interface LoginRequest {
  email: string
  password: string
}

export interface LoginResponse {
  user: User
  token: string
}

export interface User {
  id: number
  email: string
  name: string
  created_at: string
  updated_at: string
}

export interface GlitchvyApiError {
  status: number
  code: string
  message: string
}