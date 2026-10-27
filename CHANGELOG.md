# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-27

> First release: the SDK, the Laravel SaaS and the admin SPA. Work tracked against [LBDS-301](https://linear.app/dowardev/issue/LBDS-301/build-glitchvy-error-reporter-3-packages-sdk-laravel-sdk-admin-spa).

### Added

**`glitchvy`** — error reporting SaaS (Sentry-like). Three shipped entities, each with its own changelog:

- **`@dowardev/glitchvy`** SDK (npm, framework-agnostic TypeScript) — `packages/sdk/`
  - `initErrorReporter(config)`, `reportError(err, context, level)`, `setUserContext` / `clearUserContext`, `addBreadcrumb`, `flush`, `getQueueSize`, `getQueueStatus`, `shutdown`
  - Auto-capture: `window.onerror`, `unhandledrejection`, `console.error` patch
  - Scrub: default PII globals (passwords, tokens, Authorization headers) + per-context `redactFields` inside `redactContexts`
  - Offline queue (IndexedDB) with `online`-event flush and exponential-backoff retry
  - ESM + CJS dual build, TypeScript types shipped
- **`dowardev/glitchvy-server`** (Laravel 13+ standalone multi-tenant SaaS) — `back/`
  - Multi-tenant: `projects`, `api_keys` (hashed), `users` (admin via Sanctum), `error_reports` (with `project_id` FK), `error_screenshots`
  - Endpoints: SDK auth (Bearer per-project API key) for ingestion; Sanctum token for admin
  - Events: `Glitchvy\Events\ErrorReported`
  - Commands: `glitchvy:create-project`, `glitchvy:create-api-key`, `glitchvy:create-admin`, `glitchvy:prune --days=N [--dry-run]`
- **`glitchvy-admin`** (Vue 3 + Ionic 9 SPA) — `front/`
  - Login (Sanctum), Projects, Project detail (with API keys), Error list, Error detail
  - Error status workflow: `open` → `in_progress` → `resolved`, plus `ignored`
  - Screenshot viewer; Pinia stores; typed API client; Ionic CLI project (`ionic s`)
- **Examples** — `examples/`
  - `vanilla-consumer/` (HTML + JS, no framework) and `vue-consumer/` (Vue 3 + Ionic), both depending on the SDK through `workspace:*`

### Changed

- The service is multi-tenant, operated by DowarDev. The SDK is the only thing a consumer installs; `back/` and `front/` are the SaaS itself, not packages.
- The admin SPA sticks to stock Ionic: no `<style>` blocks, only Ionic utility classes and components. The project configuration files match the `ionic start` / `composer create-project` output.
- The error status workflow is `open` → `in_progress` → `resolved`, with `ignored`. The earlier Sentry-style `triaged` / `closed` values are gone from the schema, the model and the API.

### Fixed

- The admin SPA could not navigate at all. A successful login left the page on `/login` with *"Cannot read properties of undefined (reading 'push')"*, and the error detail view threw *"Cannot read properties of undefined (reading 'params')"*. `useRouter()` and `useRoute()` resolve to `undefined` inside a view rendered by the Ionic router outlet, because vue-router 5 resolves its injections from the `RouterView` component. Navigation now goes through `useIonRouter()`, and the route parameters arrive as props.
- The Sanctum token is persisted and sent with every authenticated request. It was discarded at login, so every call after it went out unauthenticated.
- The project list renders and creating a project works. The endpoint returned a cursor paginator where the SPA expected a plain array.
- The admin SPA can list the API keys it created; the listing was a stub that never called the API.
- The admin build is green — `vue-tsc` rejected nine type errors that would have failed the CI build job.
- API key authentication checks every key sharing the queried prefix, and `api_keys.key_prefix` is now unique, so two colliding keys can no longer make one of them unusable.
- Ingesting the same `event_id` twice is deduplicated instead of crashing, and `event_reports.event_id` can no longer be null.
- Creating a project no longer fails with a 500 when two requests race for the same name.
- The SDK now redacts real bearer tokens (JWT, base64), which could previously be stored in the error panel.
- Errors reported before the SDK is initialized are no longer lost.
- The offline queue respects its configured size and stops retrying forever.
- The SDK now sends the data format the service accepts: until now every report was rejected and no error reached the panel.

### Security

- Per-project API key (hashed in storage) for SDK → server ingestion
- Sanctum token for admin auth
- Screenshots stored on a private disk and served only through an authenticated endpoint
- The API key listing never exposes the plaintext key — it is shown once, at creation time

### Repo layout

The three top-level directories mirror the runtime split:

- `packages/sdk/` — the only publishable thing (`@dowardev/glitchvy` npm SDK)
- `front/` — the admin SPA (Vue 3 + Ionic, served by the SaaS)
- `back/` — the SaaS server (Laravel 13+ standalone, multi-tenant)

## [0.0.0] - 2026-10-10

> Repository scaffolded. No released code yet.
