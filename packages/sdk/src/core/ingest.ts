// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Wire format adapter.
 *
 * The SDK's internal {@link ReportEvent} is a nested, camelCase structure.
 * The ingestion endpoint (`POST /api/glitchvy/errors`) speaks the flat,
 * snake_case contract documented in `back/README.md`. This module translates
 * one into the other, at the transport boundary, so the public SDK API and
 * the internal event shape stay untouched.
 *
 * The backend validates the payload strictly, so anything that would make it
 * reject the whole event (a non-UUID event id, an over-long version string,
 * a non-integer entity id) is normalized here instead.
 */

import type { ErrorLevel, ReportEvent } from '../types.js';

/** A breadcrumb as accepted by the ingestion endpoint. */
export interface IngestBreadcrumb {
  message: string;
  timestamp: string;
  data?: Record<string, unknown>;
}

/** The flat, snake_case payload accepted by `POST /api/glitchvy/errors`. */
export interface IngestPayload {
  event_id?: string;
  app_version?: string;
  environment: string;
  platform?: string;
  route?: string;
  user_agent?: string;
  level?: ErrorLevel;
  error_name: string;
  error_message: string;
  error_type?: string;
  error_stack?: string;
  entity_type?: string;
  entity_id?: number;
  operation?: string;
  tags?: Record<string, string>;
  data?: Record<string, unknown>;
  user_id?: number;
  user_payload?: Record<string, unknown>;
  breadcrumbs?: IngestBreadcrumb[];
}

/** Length limits enforced by the ingestion endpoint. */
const MAX_APP_VERSION = 50;
const MAX_ENVIRONMENT = 50;
const MAX_ROUTE = 255;
const MAX_ERROR_NAME = 255;
const MAX_ERROR_TYPE = 255;
const MAX_ENTITY_TYPE = 100;
const MAX_OPERATION = 100;

function truncate(value: string | undefined, max: number): string | undefined {
  if (value === undefined) return undefined;
  return value.length > max ? value.slice(0, max) : value;
}

/** Coerce to an integer the backend accepts, or `undefined` when it cannot. */
function toInteger(value: unknown): number | undefined {
  if (typeof value === 'number' && Number.isInteger(value)) return value;
  if (typeof value === 'string' && /^-?\d+$/.test(value.trim())) {
    const parsed = Number(value.trim());
    if (Number.isSafeInteger(parsed)) return parsed;
  }
  return undefined;
}

/** Map the SDK's public level (including the 'warn' alias) to the backend enum. */
function normalizeLevel(level: ErrorLevel): Exclude<ErrorLevel, 'warn'> {
  return level === 'warn' ? 'warning' : level;
}

/**
 * Convert an SDK event into the payload the ingestion endpoint accepts.
 *
 * Notes:
 * - `error_message` is required by the backend and rejects empty strings, so
 *   an empty message falls back to the error name and then to a generic text.
 * - `entity_id` and `user_id` must be integers; a non-integer value is left
 *   out rather than making the backend reject the whole event.
 */
export function toIngestPayload(event: ReportEvent): IngestPayload {
  const payload: IngestPayload = {
    environment: truncate(event.environment, MAX_ENVIRONMENT) as string,
    error_name: truncate(event.error.name || 'Error', MAX_ERROR_NAME) as string,
    error_message: event.error.message || event.error.name || 'Unknown error',
  };

  if (event.eventId) payload.event_id = event.eventId;
  const appVersion = truncate(event.appVersion, MAX_APP_VERSION);
  if (appVersion) payload.app_version = appVersion;
  if (event.platform) payload.platform = event.platform;
  const route = truncate(event.route, MAX_ROUTE);
  if (route) payload.route = route;
  if (event.userAgent) payload.user_agent = event.userAgent;
  if (event.level) payload.level = normalizeLevel(event.level);

  const errorType = truncate(event.error.type, MAX_ERROR_TYPE);
  if (errorType) payload.error_type = errorType;
  if (event.error.stack) payload.error_stack = event.error.stack;

  const entityType = truncate(event.context.entityType, MAX_ENTITY_TYPE);
  if (entityType) payload.entity_type = entityType;
  const entityId = toInteger(event.context.entityId);
  if (entityId !== undefined) payload.entity_id = entityId;
  const operation = truncate(event.context.operation, MAX_OPERATION);
  if (operation) payload.operation = operation;

  if (event.context.tags && Object.keys(event.context.tags).length > 0) {
    payload.tags = { ...event.context.tags };
  }
  if (event.context.data && Object.keys(event.context.data).length > 0) {
    payload.data = { ...event.context.data };
  }

  if (event.user && Object.keys(event.user).length > 0) {
    const userId = toInteger(event.user.userId);
    if (userId !== undefined) payload.user_id = userId;
    payload.user_payload = { ...event.user };
  }

  if (event.breadcrumbs && event.breadcrumbs.length > 0) {
    payload.breadcrumbs = event.breadcrumbs.map((b) => ({
      message: b.message,
      timestamp: b.timestamp,
      ...(b.data ? { data: { ...b.data } } : {}),
    }));
  }

  return payload;
}
