# `glitchvy-admin`

> Vue 3 + Ionic SPA dashboard for the [`glitchvy-server`](../back) admin endpoints. Mobile-first, single-page, framework-light.
>
> Source lives in `front/`.

[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)

## What it does

The dev's window into errors reported by the consumer app:

- **Login** with an admin token (your Laravel app issues it via its auth guard; the admin sends it back as `X-Glitchvy-User-Token`).
- **Error list** with filters (level, status, free-text search) and cursor pagination.
- **Error detail** with stack, breadcrumbs, screenshots, and status workflow (open → triaged → resolved → closed).
- **Mobile-first** via Ionic — same UI on phone or laptop.

## Install & run (v0.x, dev mode)

```bash
# From the monorepo root
pnpm install
pnpm --filter glitchvy-admin dev
```

Open http://localhost:5174.

## Build

```bash
pnpm --filter glitchvy-admin build
# output: front/dist/  (static SPA — host it on any HTTP server)
```

## Environment

The admin reads two env vars at build time (Vite injects them as `import.meta.env.VITE_*`):

| Var | Default | Meaning |
|---|---|---|
| `VITE_GLITCHVY_API_URL` | `/api/glitchvy` | Base path the admin hits. Absolute in production (`https://your-app.example.com/api/glitchvy`). |
| `VITE_GLITCHVY_PROXY_TARGET` | `http://localhost:8000` | Local dev — Vite proxies `/api/glitchvy/*` to this Laravel server. |

> The token you enter in the login form is also stored in `localStorage` under `glitchvy-admin-token`. The admin SPA forwards it on every API request as `X-Glitchvy-User-Token`.

## Tests

```bash
pnpm --filter glitchvy-admin test:run
pnpm --filter glitchvy-admin test:coverage
```

The package is tested at the **state + boundary** level (Pinia stores + HTTP wrapper). Vue components are exercised manually + via the CI smoke flow; we don't measure coverage on `.vue` files because Ionic component markup inflates LOC without adding logic. Documented in `vitest.config.ts`.

## Stack

- Vue 3 (Composition API) + TypeScript 5.x
- Ionic Vue 8
- Vue Router 4 (via `@ionic/vue-router`)
- Pinia 2 (state)
- Vite 5 (build)

## License

GNU Affero General Public License v3.0 (`AGPL-3.0-only`). See [LICENSE](../../LICENSE).

Copyright (C) 2026 DowarDev. SPDX-License-Identifier: AGPL-3.0-only.
