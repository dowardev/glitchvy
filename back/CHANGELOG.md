# Changelog

All notable changes to `dowardev/glitchvy-server` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial release of the standalone Laravel 13 multi-tenant error-reporting server.
- Multi-tenant schema: `projects`, `api_keys`, `users`, `error_reports` (scoped by `project_id`), `error_screenshots`.
- Ingestion endpoint `POST /api/glitchvy/errors`, authenticated with a per-project API key sent as a Bearer token. The key is stored hashed; only its prefix is kept in clear for lookup.
- Screenshot upload endpoint `POST /api/glitchvy/errors/{id}/screenshot` (multipart), scoped to the owning project.
- Admin API authenticated with a Sanctum token:
  - `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`
  - `GET` / `POST /api/projects`, `GET` / `POST /api/projects/{id}/api-keys`
  - `GET /api/glitchvy/errors` (filters: `level`, `status`, `environment`, `project_id`, `search`, `per_page`), `GET /api/glitchvy/errors/{id}`, `PATCH /api/glitchvy/errors/{id}/status`, `GET /api/glitchvy/errors/{id}/screenshot`
- Artisan commands: `glitchvy:create-project`, `glitchvy:create-api-key`, `glitchvy:create-admin`, `glitchvy:prune --days=N [--dry-run]`.
- `ErrorReported` event, fired whenever a report is stored.
- `ErrorReportResource`, the single place where the stored column names are mapped to the API shape the admin SPA consumes (`message`, `type`, `stack_trace`, `context`, `count`, `first_seen_at`, `last_seen_at`).

### Changed

- The error status workflow is `open` → `in_progress` → `resolved`, with `ignored` for reports that will not be fixed. The pre-SPA values `triaged` and `closed` are gone from the schema, the model and the API.
- `error_reports.event_id` is now `NOT NULL`. A unique index does not constrain NULLs, so an event without an id would have defeated the dedup guarantee. The ingestion endpoint mints a UUID when the payload carries none.

### Fixed

- API key authentication now checks every key that shares the queried prefix instead of only the first row returned, so two keys with a colliding prefix can no longer make one of them unauthenticatable.
- `api_keys.key_prefix` now carries a unique index. The migration refuses to run if the table already holds duplicates, listing them rather than dropping data silently.
- Creating a project no longer fails with a 500 when two requests race for the same name. The insert is retried with a fresh random suffix, and the endpoint returns 409 if it keeps losing the race.
- Project slugs are generated automatically from the project name, with a random suffix on collision.

## [0.0.0]

> Not released.
