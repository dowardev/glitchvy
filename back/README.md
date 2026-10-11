# `glitchvy/glitchvy-server`

> Server SDK for the [`glitchvy`](../packages/sdk) error reporter. Provides the ingestion endpoint, data layer, admin JSON API, and artisan commands for your Laravel 11+ / 12 app.
>
> Composer name: `glitchvy/glitchvy-server`. Source lives in `back/`.

[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)

## What it does

You install this in your Laravel backend, run the migrations, set a key, and your server now has:

- `POST /api/glitchvy/errors` — Bearer API key, called by the SDK (the end user's tap on "Reportar este error").
- `POST /api/glitchvy/errors/{id}/screenshot` — multipart upload, Bearer API key.
- `GET /api/glitchvy/errors`, `GET /errors/{id}`, `PATCH /errors/{id}/status` — JSON API for the [`glitchvy-admin` SPA](../front); auth via `X-Glitchvy-User-Token` proxied through your app's normal auth guard.
- `GET /api/glitchvy/errors/{id}/screenshot` — authenticated binary stream (screenshots stored on the **private** disk; never exposed via public URLs).
- `Glitchvy\Events\ErrorReported` — fires on every persisted report so your app can wire alerting, Slack, etc.
- `php artisan glitchvy:prune --days=N` — retention sweep.
- `php artisan glitchvy:key [--rotate]` — generate / rotate the API key.

## Install (v0.x, via git+https)

```bash
composer require "glitchvy/glitchvy-server" \
  --repository='{"type":"vcs","url":"https://gitlab.com/dowardev/glitchvy.git"}'
```

The service provider is auto-discovered.

## Setup

```bash
# 1. Publish config (optional — defaults are fine for most apps)
php artisan vendor:publish --tag=glitchvy-config

# 2. Run the package migrations
php artisan migrate

# 3. Generate an API key (writes to .env as GLITCHVY_API_KEY)
php artisan glitchvy:key
```

Then in your consumer app:

```ts
import { initErrorReporter } from 'glitchvy';

initErrorReporter({
  ingestionUrl: '/api/glitchvy/errors',
  apiKey: import.meta.env.VITE_GLITCHVY_API_KEY,
  appVersion: '0.78.0',
  environment: 'production',
});
```

For local dev, both processes run side-by-side: your SPA at `:5173` (Vite) and your backend at `:8000` (Laravel). Configure a Vite proxy in `vite.config.ts` or let the SDK call the absolute URL.

## Configuration

The full `config/glitchvy.php`:

```php
return [
  'api_key' => env('GLITCHVY_API_KEY'),                    // required; set by `glitchvy:key`

  'routes' => [
    'prefix' => env('GLITCHVY_ROUTES_PREFIX', 'api/glitchvy'),
    'allowed_origins' => [...],                            // CORS allowlist; empty disables
  ],

  'rate_limit' => ['per_minute' => env('GLITCHVY_RATE_LIMIT_PER_MINUTE', 5)],

  'retention_days' => env('GLITCHVY_RETENTION_DAYS', 30),

  'storage_disk' => env('GLITCHVY_STORAGE_DISK', 'private'),

  'admin_guard' => env('GLITCHVY_ADMIN_GUARD', 'web'),     // Sanctum, Passport, custom

  'user' => [
    'table' => env('GLITCHVY_USER_TABLE', 'users'),         // resolved by AuthenticateUserToken
    'token_column' => env('GLITCHVY_USER_TOKEN_COLUMN', 'remember_token'),
  ],
];
```

### Storage disk — `private` by default

Screenshots are uploaded via `POST /errors/{id}/screenshot` and stored on the `private` filesystem disk (configurable). The admin SPA streams them through `GET /errors/{id}/screenshot` **after auth**, never via a public URL. If you switch this to `public` for self-hosted deployments, you take responsibility for restricting access at the HTTP layer.

### Rate limiting

The ingestion endpoint is capped at `5 req/min/IP` by default. The admin SPA endpoints are not throttled here — apply your own middleware if you expose them publicly.

## Endpoints

| Verb | Path | Auth | Used by |
|---|---|---|---|
| `POST`   | `/api/glitchvy/errors`                       | `Bearer $GLITCHVY_API_KEY`         | Consumer SDK |
| `POST`   | `/api/glitchvy/errors/{id}/screenshot`       | `Bearer $GLITCHVY_API_KEY`         | Future SDK helper |
| `GET`    | `/api/glitchvy/errors`                       | `X-Glitchvy-User-Token`            | Admin SPA |
| `GET`    | `/api/glitchvy/errors/{id}`                  | `X-Glitchvy-User-Token`            | Admin SPA |
| `PATCH`  | `/api/glitchvy/errors/{id}/status`           | `X-Glitchvy-User-Token`            | Admin SPA |
| `GET`    | `/api/glitchvy/errors/{id}/screenshot`       | `X-Glitchvy-User-Token`            | Admin SPA |

> Sentry-compatible ingestion is **out of scope** for v0.x. The HTTP contract is glitchvy-specific.

## Events

```php
namespace App\Listeners;

use Glitchvy\Events\ErrorReported;

class PingOnCallOnGlitchvyError
{
    public function handle(ErrorReported $event): void
    {
        $report = $event->errorReport;

        // your alerting logic
    }
}
```

Wire it in `app/Providers/EventServiceProvider::$listen` (Laravel 11) or `bootstrap/app.php` (Laravel 12 style):

```php
use Glitchvy\Events\ErrorReported;
use App\Listeners\PingOnCallOnGlitchvyError;

Event::listen(ErrorReported::class, PingOnCallOnGlitchvyError::class);
```

> No bundled alerting exists. Implement your own listener per-project.

## Status workflow

`ErrorReport::$status` is constrained to the enum:

```
open → triaged → resolved → closed
```

The admin SPA drives transitions via `PATCH /errors/{id}/status`. Invalid transitions return 422 with the allowed list.

## Testing

```bash
# from back/
composer install
vendor/bin/phpunit
```

The test suite uses [Orchestra Testbench](https://packages.tools/testbench) to spin up a minimal Laravel app in-memory with SQLite. Coverage > 70%.

## Out of scope (v0.x)

- Source maps. The client sends raw stacks.
- Alerter implementations (wire your own `ErrorReported` listener).
- Sentry-compatible ingestion endpoint.
- Server SDKs for non-Laravel frameworks (Node, Django, etc.) — separate packages.
- Public release to Packagist — install via `git+https` for v0.x.

## License

GNU Affero General Public License v3.0 (`AGPL-3.0-only`). See [LICENSE](../../LICENSE).

Copyright (C) 2026 DowarDev. SPDX-License-Identifier: AGPL-3.0-only.
