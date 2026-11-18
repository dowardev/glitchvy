# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

> Unreleased changes of `dowardev/glitchvy-server` (Laravel) and `glitchvy-admin` (Vue 3 + Ionic), tracked in [issue #6](https://gitlab.com/dowardev/glitchvy/-/issues/6).

### Added

**Deployment tooling**

- `setup.sh`: the first-run configuration of the server (backend env from the templates, `APP_KEY`, migrations, storage link, the optional admin user and a health-check report). It is uploaded by the deploy script but never executed automatically, because it prompts for secrets.
- `scripts/deploy/deploy.sh`: the local deploy (checks, build, upload over SSH, remote migrations and caches) with its configuration in `scripts/deploy/deploy.conf` and the host values in the gitignored `scripts/deploy/.env`.
- `.gitlab-ci.yml`: the `deploy` and `rollback` stages, running on semver tags.
- `DEPLOY.md`: the deployment guide (first run, local deploy, CI deploy, rollback, configuration reference and troubleshooting).
- `setup.sh` restricts the server environment file and its backups to the owner (`chmod 600`): on a
  shared host the default permissions left the database password, the application key and the mail
  credentials readable by any other user of the machine.

**`dowardev/glitchvy-server`** (Laravel)

- Project ownership, tracked in [issue #2](https://gitlab.com/dowardev/glitchvy/-/issues/2): a project belongs to an admin, and the admin project endpoints (`GET`/`POST /api/projects`, `GET`/`POST /api/projects/{id}/api-keys`) are scoped by the authenticated admin. A project of another admin answers `404`, so the caller cannot learn that it exists. The projects that already existed are assigned to the operator (the first admin) by the migration, so none of them is left unreachable. The admin SPA reads the same scoped endpoints, so its project list and API key listing are scoped by the same relation.
- Error report ownership, tracked in [issue #7](https://gitlab.com/dowardev/glitchvy/-/issues/7): the error report endpoints (`GET /api/glitchvy/errors`, `GET /api/glitchvy/errors/{id}`, `PATCH /api/glitchvy/errors/{id}/status`, `GET /api/glitchvy/errors/{id}/screenshot`) are scoped by the same relation, since a report belongs to a project. The listing holds only the reports of the projects the authenticated admin owns (plus the ones nobody owns), and a report of a project of another admin answers `404`.

### Fixed
- The deploy pipeline now refuses an empty or invalid private key with an explicit message. Before, the
  runner installed the empty or bogus content as the ssh identity and the job died later with an opaque
  key error.

**`dowardev/glitchvy-server`** (Laravel)

- The admin SPA could not open a session from a loopback port other than the default ones: the login answered a bearer token the SPA does not use, and the first data request answered `Unauthenticated`. The stateful domains now cover the loopback interface on any port (the SPA runs on a port of its own, and it changes between runs), and `SANCTUM_STATEFUL_DOMAINS` adds the deployment domains to that list instead of replacing it. The port wildcard is a `Str::is` pattern (Sanctum matches the origin against `"<entry>/*"`), not a Sanctum feature.

- The ingestion endpoint had no rate limit. The per-project API key ships in the browser bundle of every consumer app, so anyone who extracted it could flood the database. The limit is per project and comes from `GLITCHVY_RATE_LIMIT` (`60,1` by default).
- The admin login had no throttle, so the credentials could be brute-forced. The limit is per IP and email and comes from `GLITCHVY_LOGIN_RATE_LIMIT` (`5,1` by default).
- The documented environment variables (`GLITCHVY_RETENTION_DAYS`, `GLITCHVY_STORAGE_DISK`, `GLITCHVY_RATE_LIMIT`) were read by no code, and the one that existed (`GLITCHVY_SCREENSHOT_DISK`) was undocumented. They now live in `config/glitchvy.php` and are listed in `.env.example`.
- `glitchvy:prune` hardcoded `--days=30` and no scheduler entry existed. It now defaults to the configured retention days and runs daily.
- The documented ingest payload showed `tags` as an array while the SDK and the dashboard use an object. The object map is the canonical shape: a list is now rejected with a clear message instead of being stored with numeric keys.

**`glitchvy-admin`** (Vue 3 + Ionic)

- The admin token was kept in `localStorage`, where any XSS could read it, and it outlived the browser session. The session now lives in an httpOnly cookie that the SPA never touches.
- The page size was sent as `limit`, which the API ignored: the error list was always 20 rows. The API reads `per_page` now.
- The SPA shipped without a Content-Security-Policy. Production builds now carry a strict one (`script-src 'self'`, no inline script, `frame-ancestors 'none'`).
- Creating a project from the admin SPA died with *"Failed to execute 'insertBefore' on 'Node'"* and left the page broken. Opening a modal makes Ionic move the `<ion-modal>` out of the `<ion-content>` into the `<ion-app>` overlay container, so the element Vue had to insert the new list before was no longer a child of the content. The project list keeps a single stable `<ion-list>` with the state inside it, and the modals live outside the `<ion-content>`. The project detail view also rendered an unresolved `<ion-text>` component, and the error list asked for two icons by name instead of importing them, so neither icon was drawn.

## [0.2.0] - 2026-11-08

> Release of `@dowardev/glitchvy` 0.2.0 (the SDK), with the hardening of `v0.1.0`, tracked in [issue #6](https://gitlab.com/dowardev/glitchvy/-/issues/6). The server and the admin SPA keep their own versions and changelogs.

### Changed (breaking)

**`@dowardev/glitchvy`** (SDK)

- `shutdown()` is now async: it returns a `Promise<void>` instead of `void`. It drains the offline queue before it resets the state, so the events that were waiting are sent instead of being lost, and it never rejects (a failed flush keeps the events in IndexedDB for the next boot). Callers that used the synchronous return value must `await` it: `glitchvy.shutdown()` without `await` can end the process before the queue is flushed.

### Fixed

**`@dowardev/glitchvy`** (SDK)

- The SDK `level` values did not match the backend enum. `reportError(err, ctx, 'warn')` was answered `422` and the event was lost, silently and forever (it was re-queued on every flush). The type now reaches the whole backend enum (`debug`, `info`, `warning`, `error`, `critical`), `warn` stays a public alias and is mapped to `warning` at the transport boundary.
- `shutdown()` did not flush, although the README said it did. See the breaking change above.
- `initErrorReporter()` in a runtime without a `window` permanently disabled auto-capture for that module instance. The hooks are now only marked as installed after the window check.

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
