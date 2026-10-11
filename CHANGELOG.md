# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

> Work in progress against [LBDS-301](https://linear.app/dowardev/issue/LBDS-301/build-glitchvy-error-reporter-3-packages-sdk-laravel-sdk-admin-spa).

### Planned (v0.1.0)

- **`glitchvy`** (SDK, npm, framework-agnostic) — `packages/sdk/`
  - `initErrorReporter(config)` to start the reporter
  - `reportError(error, context)` for explicit reporting
  - `setUserContext({ userId, ... })` to attach user identity
  - Auto-capture: `window.onerror`, `unhandledrejection`, `console.error` patch
  - Scrub engine: redact-by-key within configured contexts (e.g. `entry.name`)
  - PII-safe defaults: passwords / tokens / Authorization headers redacted automatically
  - Offline queue (IndexedDB) with `online` event flush and exponential-backoff retry
  - ESM + CJS dual build, TypeScript types shipped
- **`glitchvy/glitchvy-server`** (server SDK, composer) — `back/`
  - `POST /api/glitchvy/errors` (Bearer API key)
  - `POST /api/glitchvy/errors/{id}/screenshot` (multipart upload, Bearer API key)
  - `GET /api/glitchvy/errors`, `GET /errors/{id}`, `PATCH /errors/{id}/status` (admin auth via `X-Glitchvy-User-Token`)
  - `GET /api/glitchvy/errors/{id}/screenshot` (authenticated stream)
  - `php artisan glitchvy:prune --days=N`, `php artisan glitchvy:key --rotate`
  - `Glitchvy\Events\ErrorReported` event for custom listeners
  - Configurable storage disk (default `private`), rate limit (default 5 req/min/IP), retention (default 30 days), routes prefix (default `/api/glitchvy`)
- **`glitchvy-admin`** (Vue 3 + Ionic SPA) — `front/`
  - Login form (admin token)
  - Error list with filters (level, date, search) and pagination
  - Error detail view with screenshot viewer and status workflow (`open` → `triaged` → `resolved` → `closed`)
  - Pinia stores, Vue Router, Ionic components for mobile-first UX
- **Examples** — `examples/`
  - `examples/vanilla-consumer/` (HTML + JS, no framework)
  - `examples/vue-consumer/` (Vue 3 + Ionic)

### Repo layout

The three top-level directories mirror the runtime split:

- `packages/` — the only publishable thing (`glitchvy` npm SDK)
- `front/` — the dev's dashboard SPA (`glitchvy-admin`)
- `back/` — the server SDK composer package (`glitchvy/glitchvy-server`)

### Security

- Bearer API key (`GLITCHVY_API_KEY`) for SDK → server ingestion
- Header `X-Glitchvy-User-Token` for admin auth, proxied through the consumer app's existing auth guard
- Screenshots stored on the **private** disk, served only through an authenticated endpoint
- Configurable rate limit on ingestion (default 5 req/min/IP)

## [0.0.0] - 2026-10-10

> Repository scaffolded. No released code yet.
