# Glitchvy Admin Frontend

Frontend Ionic Vue 3 application for Glitchvy error reporting SaaS.

## Tech Stack

- **Framework**: Ionic 9 + Vue 3 + Vite
- **State Management**: Pinia
- **Routing**: Vue Router 5 (via @ionic/vue-router)
- **Testing**: Vitest + @vue/test-utils + jsdom
- **Package Manager**: pnpm

## Requirements

- Node.js 20+
- pnpm 9+
- Ionic CLI 7+ (`npm install -g @ionic/cli`)

## Getting Started

### Installation

```bash
cd front
pnpm install
```

### Development

Start the Ionic dev server (recommended):

```bash
ionic s
# or
npx ionic serve
```

This starts the server on port **8100** (Ionic default).

Alternatively, use Vite directly:

```bash
pnpm dev
```

### Environment Variables

Copy `.env.example` to `.env.local`:

```bash
cp .env.example .env.local
```

| Variable | Default | Description |
|----------|---------|-------------|
| `VITE_GLITCHVY_API_URL` | `/api` | API base path (proxied in dev) |
| `VITE_GLITCHVY_PROXY_TARGET` | `http://localhost:8000` | Laravel backend URL for Vite proxy |

### Building

```bash
pnpm build
```

Outputs to `dist/`.

### Testing

```bash
# Run tests
pnpm test

# Run tests with coverage
pnpm test:coverage
```

### Linting

```bash
pnpm lint
```

## Project Structure

```
front/
├── src/
│   ├── api/
│   │   └── client.ts          # Typed HTTP client for Glitchvy API
│   ├── components/
│   │   ├── ErrorListItem.vue  # Error card with badges
│   │   ├── ErrorFilters.vue   # Filter controls (level, status, search)
│   │   ├── ScreenshotViewer.vue # Screenshot fetch + fullscreen modal
│   │   ├── ProjectCreateModal.vue # Create project modal
│   │   └── ApiKeyCreateModal.vue  # Create API key modal with plaintext display
│   ├── stores/
│   │   ├── auth.ts            # Auth state (user, login, logout, fetchMe)
│   │   ├── projects.ts        # Projects list, create, API keys
│   │   └── errors.ts          # Errors list, detail, filters, pagination, status workflow
│   ├── views/
│   │   ├── LoginView.vue      # Email/password login → /projects
│   │   ├── ProjectListView.vue # Project list + create modal + logout
│   │   ├── ProjectDetailView.vue # Project info, API keys, errors tab
│   │   ├── ErrorListView.vue  # Error list with filters + pagination
│   │   └── ErrorDetailView.vue # Stack trace, breadcrumbs, context, tags, screenshots, status workflow
│   ├── types.ts               # TypeScript types for API contracts
│   ├── router/index.ts        # Routes + auth guard
│   ├── main.ts                # App bootstrap (Pinia, IonicVue, Router)
│   ├── App.vue                # Root component
│   └── theme/variables.css    # Ionic CSS variables
├── tests/
│   ├── setup.ts               # Vitest setup (JSDOM, mocks)
│   ├── api.spec.ts            # API client tests
│   ├── auth-store.spec.ts     # Auth store tests
│   ├── errors-store.spec.ts   # Errors store tests
│   └── projects-store.spec.ts # Projects store tests
├── ionic.config.json          # Ionic project config
├── vite.config.ts             # Vite config + proxy + test config
├── tsconfig.json              # TypeScript config
├── package.json
├── .env.example
├── .gitignore
└── README.md
```

## Key Features

### Authentication
- The session lives in an httpOnly cookie: the SPA never reads, stores or sends a token. A token in `localStorage` was readable by any XSS and outlived the browser session.
- `credentials: 'include'` on all requests, so the browser attaches the session cookie.
- `GET /api/glitchvy/csrf-cookie` bootstraps the readable `XSRF-TOKEN` cookie; every mutating request echoes it in the `X-XSRF-TOKEN` header.
- `Accept: application/json` + `X-Requested-With: XMLHttpRequest` headers.
- The session is restored on boot with `GET /api/auth/me`; the router guard waits for that answer before deciding where to send the user.

### Security
- Production builds carry a strict Content-Security-Policy (`script-src 'self'`, no inline script, `frame-ancestors 'none'`), injected by a Vite plugin so the dev server keeps its HMR.

### Error Management
- Paginated error list with cursor-based pagination
- Filters: level, status, search, project
- Detail view: stack trace, breadcrumbs, context, tags, screenshots
- Status workflow: open → in_progress → resolved / ignored

### Projects & API Keys
- Project CRUD
- API key generation with one-time plaintext display
- Copy to clipboard support

## API Integration

The frontend expects a Laravel backend with these endpoints:

```
GET    /api/glitchvy/csrf-cookie     # Bootstrap the XSRF token cookie
POST   /api/auth/login               # Login (session cookie)
POST   /api/auth/logout              # Logout
GET    /api/auth/me                  # Current user
GET    /api/projects                 # List projects
POST   /api/projects                 # Create project
POST   /api/projects/{id}/api-keys   # Create API key
GET    /api/glitchvy/errors          # List errors (with filters)
GET    /api/glitchvy/errors/{id}     # Error detail
PATCH  /api/glitchvy/errors/{id}/status # Update status
GET    /api/glitchvy/errors/{id}/screenshot # Screenshot download
```

## Ionic CLI vs Vite

**Use `ionic serve`** for development — it:
- Runs Vite internally
- Provides live reload
- Handles Ionic-specific features
- Uses port 8100 by default

**Use `pnpm dev`** only if you need direct Vite control (port 5174).

## License

MIT