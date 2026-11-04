# Glitchvy Server

Sentry-like error reporting SaaS server built with Laravel 13+. Multi-tenant, API-key authenticated, with admin dashboard endpoints.

## Features

- **Multi-tenant**: Projects isolated by API keys
- **Error ingestion**: REST API for error reports with rich metadata
- **Screenshot support**: Upload and serve error screenshots
- **Admin API**: Sanctum-authenticated endpoints for project/key/error management
- **CLI commands**: Project creation, API key generation, error pruning
- **Events**: `ErrorReported` event dispatched on new errors
- **Soft deletes + pruning**: Configurable retention with `glitchvy:prune`

## Requirements

- PHP 8.3+
- Composer
- SQLite (default) or MySQL/PostgreSQL

## Installation

```bash
cd back
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed  # creates admin@glitchvy.app user
```

## Configuration

Key environment variables (see `.env.example`):

| Variable | Description | Default |
|----------|-------------|---------|
| `DB_CONNECTION` | Database driver | `sqlite` |
| `DB_DATABASE` | Database path | `database/database.sqlite` |
| `GLITCHVY_RETENTION_DAYS` | Days before pruning soft-deleted errors | `30` |
| `GLITCHVY_STORAGE_DISK` | Filesystem disk for screenshots | `private` |
| `GLITCHVY_RATE_LIMIT` | Ingest rate limit per project (`requests,minutes`) | `60,1` |
| `GLITCHVY_LOGIN_RATE_LIMIT` | Admin login rate limit per IP and email (`requests,minutes`) | `5,1` |
| `GLITCHVY_CSRF_RATE_LIMIT` | Rate limit of the CSRF cookie endpoint, per IP (`requests,minutes`) | `60,1` |
| `SANCTUM_STATEFUL_DOMAINS` | Origins allowed to authenticate with the session cookie | `localhost,localhost:8100,127.0.0.1,127.0.0.1:8100,::1` |
| `CORS_ALLOWED_ORIGINS` | Origins allowed to call the API with credentials | `http://localhost:8100,http://127.0.0.1:8100` |
| `SESSION_SAME_SITE` | SameSite attribute of the session cookie | `lax` |
| `SESSION_SECURE_COOKIE` | Send the session cookie over HTTPS only | `false` |

The `private` disk points to `storage/app/private` and is not publicly accessible.

A cross-origin deployment (the SPA served from another host) needs
`SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS` to list that host, plus
`SESSION_SAME_SITE=none` and `SESSION_SECURE_COOKIE=true`, otherwise the
browser refuses to send the session cookie. The SPA and the API must also
share a site: the XSRF token lives in a readable cookie, and the browser only
exposes a cookie to the domain that set it. Serving the SPA from the same
host as the API (or from a sibling subdomain) and proxying `/api` keeps both
cookies usable.

## Running

```bash
php artisan serve
# Server runs on http://localhost:8000
```

## API Endpoints

### Ingestion (API Key Auth)

All ingestion endpoints require `Authorization: Bearer <api_key>` header.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/glitchvy/errors` | Create error report |
| `POST` | `/api/glitchvy/errors/{id}/screenshot` | Upload screenshot |

Both endpoints are rate limited per project (`GLITCHVY_RATE_LIMIT`): past the
budget the endpoint answers `429`.

**Error Report Payload:**
```json
{
  "event_id": "uuid (optional)",
  "app_version": "1.0.0",
  "environment": "production",
  "platform": "web",
  "route": "/api/users",
  "user_agent": "Mozilla/5.0...",
  "level": "error",
  "error_name": "ValidationException",
  "error_message": "The email field is required.",
  "error_type": "ValidationError",
  "error_stack": "Stack trace...",
  "entity_type": "user",
  "entity_id": 123,
  "operation": "create",
  "tags": {"source": "api", "area": "validation"},
  "data": {"field": "email"},
  "user_id": 456,
  "user_payload": {"id": 456, "email": "test@example.com"},
  "breadcrumbs": [{"type": "navigation", "data": {"to": "/users"}}]
}
```

`level` is one of `debug`, `info`, `warning`, `error`, `critical`. `tags` is an
object of string values (the shape the SDK sends and the dashboard renders as
`key: value` badges); a JSON list is rejected with `422`.

### Admin (Sanctum)

Two ways to authenticate:

- **Browser session (the admin SPA).** `POST /api/auth/login` from an origin
  listed in `SANCTUM_STATEFUL_DOMAINS` opens a session: the response sets an
  httpOnly session cookie and the body carries `token: null`. The SPA first
  calls `GET /api/glitchvy/csrf-cookie` and echoes the readable `XSRF-TOKEN`
  cookie back in the `X-XSRF-TOKEN` header on every mutating request.
- **API client.** A login from anywhere else returns a bearer token:
  `Authorization: Bearer <sanctum_token>`.

Opening a session revokes the personal access tokens the previous SPA flow
issued: those tokens never expired on their own, so they would have kept
working after the session ended. `POST /api/auth/logout` is exempt from the CSRF
check (it still requires authentication and only ends the caller's own session),
so a bearer client that sends a first-party origin can revoke its token instead
of getting a `419`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/glitchvy/csrf-cookie` | Bootstrap the XSRF token cookie (browser session, rate limited per IP) |
| `GET` | `/api/glitchvy/errors` | List errors (cursor paginated, filterable) |
| `GET` | `/api/glitchvy/errors/{id}` | Show error with screenshots |
| `PATCH` | `/api/glitchvy/errors/{id}/status` | Update status (open/in_progress/resolved/ignored) |
| `GET` | `/api/glitchvy/errors/{id}/screenshot` | Download screenshot |
| `GET` | `/api/projects` | List projects |
| `POST` | `/api/projects` | Create project |
| `POST` | `/api/projects/{id}/api-keys` | Create API key for project |
| `POST` | `/api/auth/login` | Login (session for a browser, token for an API client) |
| `POST` | `/api/auth/logout` | End the session or revoke the current token |
| `GET` | `/api/auth/me` | Current user |

`POST /api/auth/login` is rate limited per IP and email
(`GLITCHVY_LOGIN_RATE_LIMIT`): past the budget it answers `429`.

**Query parameters for `/api/glitchvy/errors`:**
- `environment`, `status`, `level`, `project_id`, `search`, `per_page` (`limit`
  is accepted as an alias)

## CLI Commands

```bash
# Create a project
php artisan glitchvy:create-project --name="My App" --slug=my-app

# Create an API key
php artisan glitchvy:create-api-key --project=my-app --name="Production Key"

# Prune old soft-deleted errors (dry run)
php artisan glitchvy:prune --days=30 --dry-run

# Actually prune (defaults to GLITCHVY_RETENTION_DAYS)
php artisan glitchvy:prune
```

The prune command is scheduled daily (`Schedule::command('glitchvy:prune')->daily()`),
so run `php artisan schedule:work` on the server.

## Testing

```bash
vendor/bin/phpunit
# or
php artisan test
```

Tests use in-memory SQLite and Orchestra Testbench.

## License

AGPL-3.0-only