# glitchvy

> Minimalist error reporting for apps that end in an HTTP backend.
> Made by [DowarDev](https://dowar.xyz) — public, open-source, AGPL-3.0.

[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![Status: v0.1.0 WIP](https://img.shields.io/badge/status-v0.1.0_WIP-orange.svg)](#status)

`glitchvy` is a 3-piece monorepo for end-to-end error reporting on top of your existing HTTP backend:

| Where | Purpose | Stack | Installed by |
|---|---|---|---|
| [`packages/sdk`](./packages/sdk)     | **SDK** the end-user triggers when they tap "Report this error" in your app's UI. No UI of its own. | TypeScript vanilla, framework-agnostic | npm (`git+https`) |
| [`back`](./back)                      | **Server SDK**. Provides ingestion API + data layer + auth. Your Laravel app gets `/api/glitchvy/*` endpoints. | PHP 8.2+, Laravel 11+ | composer (`git+https`) |
| [`front`](./front)                    | **Dashboard SPA** where the dev sees and triages errors. Mobile-first. | Vue 3 + Ionic 8 + Vite + Pinia | npm (`git+https`) |

## How it fits together

```
┌─────────────────────────────────────────────┐
│  glitchvy  (npm SDK, framework-agnostic)    │  ← your end user triggers this from a button in your app
│  TypeScript vanilla                         │     (lives in packages/sdk)
│  initErrorReporter() · reportError() · setUserContext()
└────────────────┬────────────────────────────┘
                 │ POST + Bearer apiKey
                 ▼
┌─────────────────────────────────────────────┐
│  glitchvy-server  (composer, server SDK)    │  ← you install this in your Laravel backend
│  POST /api/glitchvy/errors                  │     (lives in back/)
│  GET  /api/glitchvy/errors (admin API)      │
└────────────────┬────────────────────────────┘
                 │ JSON
                 ▼
┌─────────────────────────────────────────────┐
│  glitchvy-admin  (Vue 3 + Ionic SPA)        │  ← you, looking at errors from laptop or phone
│  Login · Error list · Error detail · ...    │     (lives in front/)
└─────────────────────────────────────────────┘
```

## Installation (developer mode, via git+https)

> **v0.x is installable from git**, not the npm registry. Pin a tag/commit in your lockfile.

```bash
# 1. SDK in your consumer app
pnpm add "git+https://gitlab.com/dowardev/glitchvy.git#v0.1.0" -w packages/sdk

# 2. Server SDK in your backend
composer require "dowardev/glitchvy-server" \
  --repository='{"type":"vcs","url":"https://gitlab.com/dowardev/glitchvy.git"}'

# 3. Admin SPA: clone the repo and run standalone
pnpm --filter glitchvy-admin dev
```

Full per-package install instructions live in each sub-`README.md`.

## Repo layout

```
glitchvy/
├── LICENSE                              (AGPL-3.0)
├── README.md
├── CHANGELOG.md                         (Keep a Changelog)
├── .gitlab-ci.yml                       (lint + test + build)
├── .editorconfig
├── .gitignore
├── package.json                         (root, pnpm workspaces)
├── pnpm-workspace.yaml
├── packages/
│   └── sdk/                             (glitchvy npm SDK — the only publishable package)
├── front/                               (glitchvy-admin Vue 3 + Ionic SPA)
├── back/                                (glitchvy/glitchvy-server composer package)
└── examples/
    ├── vanilla-consumer/                (HTML + JS, no framework)
    └── vue-consumer/                    (Vue 3 + Ionic)
```

The three top-level directories mirror the runtime split: **`packages/`** is for the only thing glitchvy publishes (the SDK), **`front/`** is the dev's dashboard, **`back/`** is the server SDK that lives in the consumer's Laravel backend.

## Status

> **v0.1.0 WIP** — this repo is under active development against [LBDS-301](https://linear.app/dowardev/issue/LBDS-301/build-glitchvy-error-reporter-3-packages-sdk-laravel-sdk-admin-spa). Do not publish externally yet. Use a pinned commit/tag.

## Out of scope (v0.x)

- Source maps (clients send raw stacks)
- Performance monitoring / traces
- Session replay
- Automatic alerting (wire your own listener on `Glitchvy\Events\ErrorReported`)
- Adapters to React / Svelte / Vue components (the SDK is framework-agnostic by design)
- Server SDKs for non-Laravel backends
- Sentry-compatible ingestion
- Publishing to the npm registry (currently installs via `git+https`)
- Capacitor build of the admin SPA (v0.2.0+)

See `front/README.md`, `back/README.md`, `packages/sdk/README.md` for per-package details and `CHANGELOG.md` for released versions.

## License

GNU Affero General Public License v3.0 (`AGPL-3.0-only`). See [LICENSE](./LICENSE).

Copyright (C) 2026 DowarDev. SPDX-License-Identifier: AGPL-3.0-only.
