// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Public types shared between the API client, stores, and views.
 */

export type Level = 'error' | 'warn' | 'info';
export type Status = 'open' | 'triaged' | 'resolved' | 'closed';

export interface ErrorReportSummary {
  id: number;
  level: Level;
  status: Status;
  environment: string;
  app_version: string;
  platform: string;
  route: string | null;
  error_name: string;
  error_message: string;
  entity_type: string | null;
  entity_id: string | null;
  operation: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface Breadcrumb {
  message: string;
  timestamp: string;
  data?: Record<string, unknown>;
}

export interface ScreenshotMeta {
  id: number;
  mime_type: string;
  size_bytes: number;
  created_at: string | null;
  has_screenshot: true;
}

export interface ErrorReportDetail extends ErrorReportSummary {
  user_agent: string | null;
  error_type: string | null;
  error_stack: string | null;
  tags: Record<string, string> | null;
  data: Record<string, unknown> | null;
  user: Record<string, unknown> | null;
  breadcrumbs: Breadcrumb[] | null;
  screenshots: ScreenshotMeta[];
}

export interface CursorPage<T> {
  data: T[];
  next_cursor: string | null;
  prev_cursor: string | null;
}

export interface ErrorFilters {
  level?: Level;
  status?: Status;
  search?: string;
  from?: string;
  to?: string;
  cursor?: string;
  per_page?: number;
}

export interface AdminUser {
  /** Opaque user identifier, e.g. the consumer app's remember_token. */
  token: string;
}
