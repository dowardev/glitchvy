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
| `GLITCHVY_RATE_LIMIT` | Rate limit for API endpoints | `60,1` |

The `private` disk points to `storage/app/private` and is not publicly accessible.

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
  "tags": ["api", "validation"],
  "data": {"field": "email"},
  "user_id": 456,
  "user_payload": {"id": 456, "email": "test@example.com"},
  "breadcrumbs": [{"type": "navigation", "data": {"to": "/users"}}]
}
```

### Admin (Sanctum Token Auth)

Requires `Authorization: Bearer <sanctum_token>` from `/api/auth/login`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/glitchvy/errors` | List errors (cursor paginated, filterable) |
| `GET` | `/api/glitchvy/errors/{id}` | Show error with screenshots |
| `PATCH` | `/api/glitchvy/errors/{id}/status` | Update status (open/triaged/resolved/closed) |
| `GET` | `/api/glitchvy/errors/{id}/screenshot` | Download screenshot |
| `GET` | `/api/projects` | List projects |
| `POST` | `/api/projects` | Create project |
| `POST` | `/api/projects/{id}/api-keys` | Create API key for project |
| `POST` | `/api/auth/login` | Login, returns token |
| `POST` | `/api/auth/logout` | Revoke current token |
| `GET` | `/api/auth/me` | Current user |

**Query parameters for `/api/glitchvy/errors`:**
- `environment`, `status`, `level`, `project_id`, `search`, `per_page`

## CLI Commands

```bash
# Create a project
php artisan glitchvy:create-project --name="My App" --slug=my-app

# Create an API key
php artisan glitchvy:create-api-key --project=my-app --name="Production Key"

# Prune old soft-deleted errors (dry run)
php artisan glitchvy:prune --days=30 --dry-run

# Actually prune
php artisan glitchvy:prune --days=30
```

## Testing

```bash
vendor/bin/phpunit
# or
php artisan test
```

Tests use in-memory SQLite and Orchestra Testbench.

## License

AGPL-3.0-only