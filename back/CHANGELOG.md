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
- Rate limiting on the ingestion endpoints (`POST /api/glitchvy/errors` and the screenshot upload), applied per project. The budget comes from `GLITCHVY_RATE_LIMIT` (`requests,minutes`, default `60,1`), so one noisy project cannot exhaust another's quota.
- Rate limiting on the admin login (`POST /api/auth/login`), applied per IP and email. The budget comes from `GLITCHVY_LOGIN_RATE_LIMIT` (default `5,1`).
- `config/glitchvy.php`, the one place the documented configuration lives: `GLITCHVY_RATE_LIMIT`, `GLITCHVY_LOGIN_RATE_LIMIT`, `GLITCHVY_RETENTION_DAYS` and `GLITCHVY_STORAGE_DISK`, all listed in `.env.example`.
- A daily schedule for `glitchvy:prune`, so retention is enforced without a human running the command.
- A `private` filesystem disk, the default place where screenshots are stored. It is not publicly accessible.

### Changed

- The error status workflow is `open` → `in_progress` → `resolved`, with `ignored` for reports that will not be fixed. The pre-SPA values `triaged` and `closed` are gone from the schema, the model and the API.
- `error_reports.event_id` is now `NOT NULL`. A unique index does not constrain NULLs, so an event without an id would have defeated the dedup guarantee. The ingestion endpoint mints a UUID when the payload carries none.
- `glitchvy:prune` defaults to the configured retention days (`GLITCHVY_RETENTION_DAYS`, default 30) instead of a hardcoded 30. `--days` still overrides it.
- Screenshots are stored on the disk named by `GLITCHVY_STORAGE_DISK` (default `private`, falling back to the previous `GLITCHVY_SCREENSHOT_DISK`), instead of reading the environment directly.

### Fixed

- API key authentication now checks every key that shares the queried prefix instead of only the first row returned, so two keys with a colliding prefix can no longer make one of them unauthenticatable.
- `api_keys.key_prefix` now carries a unique index. The migration refuses to run if the table already holds duplicates, listing them rather than dropping data silently.
- Creating a project no longer fails with a 500 when two requests race for the same name. The insert is retried with a fresh random suffix, and the endpoint returns 409 if it keeps losing the race.
- Project slugs are generated automatically from the project name, with a random suffix on collision.
- The ingestion endpoint had no rate limit. The per-project API key ships in the browser bundle of every consumer app, and `Access-Control-Allow-Origin: *` removed the browser-side barrier, so anyone who extracted the key could flood the database.
- The admin login had no throttle, so the credentials could be brute-forced.
- The documented environment variables (`GLITCHVY_RETENTION_DAYS`, `GLITCHVY_STORAGE_DISK`, `GLITCHVY_RATE_LIMIT`) were read by no code, and the one variable that existed (`GLITCHVY_SCREENSHOT_DISK`) was undocumented.

## [0.0.0]

> Not released.
