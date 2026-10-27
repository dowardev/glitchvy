# glitchvy

> Multi-tenant error reporting SaaS (Sentry-like). Made by [DowarDev](https://dowar.xyz) — public, open-source, AGPL-3.0.

[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![Status: v0.1.0 WIP](https://img.shields.io/badge/status-v0.1.0_WIP-orange.svg)](#status)

`glitchvy` is a multi-tenant error reporting service you (or DowarDev) deploy, and your apps POST errors to. Three top-level directories: a consumer-installable SDK, an admin SPA, and a Laravel SaaS backend.

| Where | Purpose | Stack | Installed by |
|---|---|---|---|
| [`packages/sdk`](./packages/sdk)     | **SDK** the end-user triggers when they tap "Report this error" in your app's UI. No UI of its own. | TypeScript vanilla, framework-agnostic | npm (the consumer's app) |
| [`back`](./back)                      | **SaaS server**. Receives events, stores them, exposes the admin API. Multi-tenant. | PHP 8.2+, Laravel 13+ | composer (only on the server) |
| [`front`](./front)                    | **Admin dashboard SPA**. Triage errors, manage projects / API keys. Mobile-first. | Vue 3 + Ionic 9 | Ionic CLI (`ionic s`) |

## How it fits together

```
┌─────────────────────────────────────────────┐
│  Consumer's app (yours)                      │
│  ┌─────────────────────────────────────┐    │
│  │ glitchvy SDK (npm)                   │    │
│  │ initErrorReporter() · reportError()  │    │
│  └─────────────────────────────────────┘    │
└────────────────┬────────────────────────────┘
                 │ POST + Bearer <project_api_key>
                 ▼
┌─────────────────────────────────────────────┐
│  glitchvy SaaS — back/ (Laravel standalone)  │  ← deployed by us (or you)
│  POST /api/glitchvy/errors                  │
│  - Auth: per-project API key (hashed)        │
│  - Admin: Sanctum session                   │
│  - Events: Glitchvy\Events\ErrorReported    │
└────────────────┬────────────────────────────┘
                 │ JSON (Sanctum session cookie)
                 ▼
┌─────────────────────────────────────────────┐
│  glitchvy-admin (Vue 3 + Ionic SPA)         │  ← dev uses
│  Login · Projects · API keys · Errors       │     (served from front/)
│  Detail · Screenshots · Status workflow      │
└─────────────────────────────────────────────┘
```

## Repo layout

```
glitchvy/
├── LICENSE                              (AGPL-3.0)
├── README.md
├── CHANGELOG.md                         (Keep a Changelog)
├── .gitlab-ci.yml                       (lint + test + build per package)
├── .editorconfig
├── .gitignore
├── package.json                         (root, pnpm workspaces)
├── pnpm-workspace.yaml
├── packages/
│   └── sdk/                             (npm `@dowardev/glitchvy` — the only consumer installable)
├── front/                               (npm `glitchvy-admin` — Vue 3 + Ionic)
├── back/                                (composer `dowardev/glitchvy-server` — Laravel 13+ standalone)
└── examples/
    ├── vanilla-consumer/                (HTML + JS, no framework)
    └── vue-consumer/                    (Vue 3 + Ionic)
```

The three top-level directories mirror the runtime split: **`packages/`** is the only thing glitchvy publishes (the SDK), **`front/`** and **`back/`** are the SaaS itself (we deploy them), **`examples/`** are reference consumer apps.

## Getting started (dev mode)

### Back (Laravel SaaS)

```bash
cd back
composer install
php artisan migrate --seed
php artisan serve  # → http://127.0.0.1:8000
```

The seeder creates an initial admin user (`admin@glitchvy.app`) and prints a random password to stdout. Log in via the front, or use the API directly.

```bash
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d '{"email":"admin@glitchvy.app","password":"<seeded-password>"}'
```

### Front (admin SPA)

```bash
cd front
pnpm install
ionic s   # → http://localhost:8100
```

`VITE_GLITCHVY_API_URL` defaults to `/api/glitchvy` (proxied to Laravel in dev). Set `VITE_GLITCHVY_PROXY_TARGET` if your Laravel isn't on :8000.

### SDK (consumer installable)

In a real consumer app:

```bash
pnpm add "git+https://gitlab.com/dowardev/glitchvy.git#v0.1.0" -w packages/sdk
```

Then in your `main.ts`:

```ts
import { initErrorReporter } from '@dowardev/glitchvy';
initErrorReporter({
  ingestionUrl: 'https://errors.dowar.xyz/api/glitchvy/errors',
  apiKey: 'gvy_proj_xxx',  // per-project, created in the admin
  appVersion: '0.78.0',
  environment: 'production',
});
```

## Status

> **v0.1.0** — released. This repo was built against [LBDS-301](https://linear.app/dowardev/issue/LBDS-301/build-glitchvy-error-reporter-3-packages-sdk-laravel-sdk-admin-spa). The SDK is published on npm as `@dowardev/glitchvy`.

| Component | Status | Tests |
|---|---|---|
| `packages/sdk` | build (dual ESM+CJS) + types OK | 53/53 vitest ✓ |
| `back/` (Laravel standalone) | `php artisan serve` works | 25/25 PHPUnit ✓ |
| `front/` (Ionic CLI) | `ionic s` works | 31/31 vitest ✓ |

## Out of scope (v0.x)

Source maps · Performance monitoring · Session replay · Alerting (per-project listener on `Glitchvy\Events\ErrorReported`) · React/Svelte adapters · Non-Laravel server SDKs · Sentry-compatible ingestion · Publishing SDK to npm registry · Capacitor build of admin SPA · Self-hostable.

## License

GNU Affero General Public License v3.0 (`AGPL-3.0-only`). See [LICENSE](./LICENSE).

Copyright (C) 2026 DowarDev. SPDX-License-Identifier: AGPL-3.0-only.
