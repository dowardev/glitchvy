# Glitchvy — Deployment Guide

How to deploy the Glitchvy server (`back/`, Laravel) and the admin SPA (`front/`, Vue 3 + Ionic) to the production host.

The tooling lives in the repository:

| File | Purpose |
| --- | --- |
| `setup.sh` | First-run configuration of the server (env, migrations, admin user, health checks). |
| `scripts/deploy/deploy.sh` | Local deploy: checks, build, upload over SSH, remote migrations. |
| `scripts/deploy/deploy.conf` | Project configuration (committed). |
| `scripts/deploy/deploy.conf.example` | Template for the project configuration. |
| `scripts/deploy/.env.example` | Template for the host values and secrets (copy to `.env`). |
| `.gitlab-ci.yml` | `deploy` and `rollback` stages (tag-only). |

> The npm SDK (`packages/sdk`) is **not** published by any of this tooling — it is released by a separate flow.

---

## Architecture

```
glitchvy/
├── packages/sdk/     # npm SDK (@dowardev/glitchvy) — published separately
├── back/             # Laravel 13 server (ingest API + admin API)
│   ├── public/       # document root of the API
│   └── database/     # migrations
└── front/            # admin SPA (Vue 3 + Ionic), built to front/dist/
```

On the server:

```
public_html/glitchvy/            # the SPA (contents of front/dist/)
public_html/glitchvy/back/       # the Laravel app (document root: back/public)
```

The SPA and the API are served from the same host, so the SPA calls `/api` on its own origin (no CORS in production) and the session cookie works with Sanctum.

---

## Prerequisites

On the **server**:

- PHP 8.3+ with `pdo_mysql` (or `pdo_sqlite`) and `pdo_sqlite`
- Composer 2
- SSH access (user, port, key)
- Node.js + pnpm **only** if you build the SPA on the server (usually you do not: the SPA is built locally and uploaded)

On your **machine**:

- PHP 8.3+, Composer 2, Node.js 22+, pnpm 11 (`corepack enable`)

---

## 1. First run on the server

`setup.sh` is uploaded by the deploy script, but it is **never executed automatically**: it prompts for secrets (database password, admin password).

```bash
ssh -p 65002 u902881280@195.179.236.189
cd /home/u902881280/public_html/glitchvy
./setup.sh
```

What it does:

1. Checks the prerequisites (PHP version, Composer, Node/pnpm).
2. Creates `back/.env` from `back/.env.production.example` (backing up the previous one) and asks for:
   - app name, backend URL (`APP_URL`) and admin SPA URL (`FRONTEND_URL`);
   - the database (`sqlite` — the default — or `mysql`);
   - the mail settings and the `GLITCHVY_*` tunables (retention, storage disk, rate limit).
   From the SPA URL it derives `SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS`.
3. Generates `APP_KEY` (`php artisan key:generate`).
4. Runs the migrations (`php artisan migrate --force`) and links the public storage.
5. Optionally creates the admin user for the SPA login (`php artisan glitchvy:create-admin`).
6. Runs the health checks and prints a summary.

Options:

| Option | Effect |
| --- | --- |
| `--dev` | Use `.env.development.example` and local defaults. |
| `--non-interactive` | Unattended run; requires the `GLITCHVY_*` env vars (`GLITCHVY_APP_URL`, `GLITCHVY_FRONTEND_URL`, `GLITCHVY_DB_CONNECTION`, …). |
| `--no-backup` | Do not back up the existing `.env` files. |
| `--skip-front-build` | Do not build the admin SPA. |

Exit codes: `0` success, `2` missing prerequisite, `3` missing environment variable, `4` no TTY (or database connection failure), `5` migration/seed failure, `6` write permission error, `7` invalid value (a value that cannot be written to an env file, for example one that contains a newline).

### Unattended setup

```bash
ssh -p 65002 u902881280@195.179.236.189 \
  "cd /home/u902881280/public_html/glitchvy && \
   GLITCHVY_APP_URL=https://errors.example.com \
   GLITCHVY_FRONTEND_URL=https://errors.example.com \
   GLITCHVY_DB_CONNECTION=mysql \
   GLITCHVY_DB_HOST=127.0.0.1 \
   GLITCHVY_DB_DATABASE=glitchvy \
   GLITCHVY_DB_USERNAME=glitchvy \
   GLITCHVY_DB_PASSWORD='***' \
   ./setup.sh --non-interactive"
```

---

## 2. Local deploy (recommended)

```bash
cp scripts/deploy/.env.example scripts/deploy/.env   # once, then fill in your values
./scripts/deploy/deploy.sh
```

The script runs the checks (backend PHPUnit, SDK `tsc` + vitest, SPA `vue-tsc` + vitest), builds the backend and the SPA, uploads both tarballs with `scp` and runs the remote commands from `scripts/deploy/deploy.conf` (`composer install`, `config:cache`, `route:cache`).

**The deploy never migrates the database.** A pending migration can drop a table or a column, so it only runs when you ask for it:

```bash
./scripts/deploy/deploy.sh --migrate
```

The guard reads the SQL that `artisan migrate --pretend` would run and refuses to apply a destructive one (`DROP TABLE`, `DROP COLUMN`, `TRUNCATE`, `RENAME COLUMN`) unless you set `ALLOW_DESTRUCTIVE_MIGRATIONS=1` on purpose.

Options:

| Option | Effect |
| --- | --- |
| `--backend` / `--frontend` | Deploy only one side. |
| `--all` | Deploy both (the default). |
| `--tag v1.2.3` | Check out the tag first, then deploy it. |
| `--skip-checks` | Skip the checks (faster, use with care). |
| `--migrate` | Also run the pending migrations (opt-in, with a destructive-migration guard). |
| `--dry-run` | Print every command without running `scp`/`ssh`. |
| `--verbose` | Show the executed commands. |

Always try `--dry-run` first.

### The admin SPA env

Vite bakes the `VITE_*` values into the bundle at build time, so they must be set **before** the build:

```bash
cp front/.env.production.example front/.env.production
# edit front/.env.production (VITE_GLITCHVY_API_URL, VITE_GLITCHVY_CSP_CONNECT_SRC)
```

The deploy script builds the SPA with `pnpm --filter glitchvy-admin build` from the monorepo root, so `front/.env.production` is picked up automatically.

---

## 3. CI/CD deploy (tag-only)

Pushing a semver tag (`v1.2.3`, `v1.2.3-rc.1`) runs the pipeline with the `deploy` stage:

- `backend_deploy` — ships `back/` (without `vendor`, `.env*`, the local sqlite database and the generated caches), then runs `composer install --no-dev`, `config:cache` and `route:cache` on the server. It does **not** migrate.
- `backend_migrate` — **manual** job: runs the pending migrations with the same destructive-migration guard as `--migrate`. Start it explicitly after reviewing what the migration does.
- `frontend_deploy` — builds the SPA and ships `front/dist/`.

The `rollback` stage adds two **manual** jobs (`rollback_backend`, `rollback_frontend`) that redeploy the previous tag.

Required GitLab CI/CD variables (Settings → CI/CD → Variables):

| Variable | Example |
| --- | --- |
| `SSH_HOST` | `195.179.236.189` |
| `SSH_PORT` | `65002` |
| `SSH_USER` | `u902881280` |
| `SSH_PRIVATE_KEY` | the private key content |
| `SSH_KNOWN_HOSTS` | the `known_hosts` line |
| `DEPLOY_PATH_BACKEND` | `/home/u902881280/public_html/glitchvy/back` |
| `DEPLOY_PATH_FRONTEND` | `/home/u902881280/public_html/glitchvy` |
| `ALLOW_DESTRUCTIVE_MIGRATIONS` | optional: `1` lets the manual `backend_migrate` job apply a destructive migration |

---

## 4. Rollback

From CI: run the `rollback_backend` / `rollback_frontend` job of the tag pipeline. Both roll back to the newest **stable** release older than the tag being deployed, selected by `scripts/ci/previous-tag.sh` (prereleases and malformed tags never qualify, and a tag that is not a semver fails with a clear message).

If the automatic selection cannot find a candidate (for example the tags
were not fetched), set the `ROLLBACK_TO_TAG` CI/CD variable to the release
you want to restore and run the job again.

From your machine:

```bash
./scripts/deploy/deploy.sh --tag v1.2.2
```

The migrations are **not** reverted automatically: check the release notes before rolling back a version whose migrations changed the schema.

---

## 5. Configuration reference

### `back/.env` (server)

| Variable | Meaning |
| --- | --- |
| `APP_NAME` | Application name. |
| `APP_ENV` | `production` on the server. |
| `APP_DEBUG` | `false` on the server. |
| `APP_KEY` | Generated by `setup.sh` (`php artisan key:generate`). |
| `APP_URL` | Public URL of the API. |
| `FRONTEND_URL` | Public URL of the admin SPA. |
| `DB_CONNECTION` | `sqlite` or `mysql` (with `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). |
| `SANCTUM_STATEFUL_DOMAINS` | Derived from `FRONTEND_URL` — the host allowed to authenticate with a cookie. |
| `CORS_ALLOWED_ORIGINS` | Derived from `FRONTEND_URL`. |
| `GLITCHVY_RETENTION_DAYS` | Days an error report is kept (default `30`). |
| `GLITCHVY_STORAGE_DISK` | Disk used for the screenshots (default `private`). |
| `GLITCHVY_RATE_LIMIT` | Ingest throttle, `requests,minutes` (default `60,1`). |
| `GLITCHVY_LOGIN_RATE_LIMIT` | Login throttle (default `5,1`). |
| `GLITCHVY_CSRF_RATE_LIMIT` | CSRF endpoint throttle (default `60,1`). |

### `front/.env.production` (build machine)

| Variable | Meaning |
| --- | --- |
| `VITE_GLITCHVY_API_URL` | Base URL of the API. `/api` when the SPA and the API share the host. |
| `VITE_GLITCHVY_CSP_CONNECT_SRC` | `connect-src` of the CSP injected in the bundle. Keep `'self'` for a same-origin deployment. |

### `scripts/deploy/deploy.conf`

| Variable | Meaning |
| --- | --- |
| `PROJECT_TYPE` | `fullstack` for glitchvy. |
| `BACKEND_DIR` / `FRONTEND_DIR` | `back` / `front`. |
| `BACKEND_ARTIFACT_EXCLUDES` | Files that never ship (`.env*`, `vendor`, `storage/*`, the local sqlite database…). |
| `BACKEND_REMOTE_CMD` | Commands run on the server after the extract. |
| `FRONTEND_BUILD_CMD` | Build command (run from the monorepo root). |
| `FRONTEND_ARTIFACT_SOURCE` | `front/dist`. |
| `SETUP_SCRIPT` | `setup.sh` — uploaded to the server, never executed automatically. |
| `VERSION_CHECK_PKG` / `VERSION_CHECK_FILE` | Informational check of the SDK version against the npm registry. |

---

## 6. After the deploy

```bash
# Create the first project and its API key (the SDK needs the key)
ssh -p 65002 u902881280@195.179.236.189 \
  "cd /home/u902881280/public_html/glitchvy/back && \
   php artisan glitchvy:create-project --name='My app' && \
   php artisan glitchvy:create-api-key --name='default'"

# Then point the SDK at the ingestion endpoint
# import { initErrorReporter } from '@dowardev/glitchvy'
# initErrorReporter({ apiUrl: 'https://errors.example.com/api', apiKey: 'gv_...' })
```

---

## 7. Troubleshooting

**The SPA loads but every request fails with 401.**
`SANCTUM_STATEFUL_DOMAINS` does not match the SPA host. It is derived from `FRONTEND_URL` — set it to the exact host (with the port in development) and clear the config cache: `php artisan config:clear`.

**`Please provide a valid cache path.`**
`storage/framework/*` is excluded from the artifact. The deploy creates the directories on the server; if you deploy by hand, run `mkdir -p storage/framework/{views,cache/data,sessions}` and `chmod -R 775 storage bootstrap/cache`.

**`The sqlite database file does not exist` / `database is locked`.**
The sqlite file must exist and be writable by the web server: `touch back/database/database.sqlite` and `chown` it to the PHP user. For a shared host, MySQL is usually the better option.

**CORS errors in the browser.**
`CORS_ALLOWED_ORIGINS` must contain the exact SPA origin (scheme + host + port).

**The deploy uploads the previous `.env`.**
The artifact excludes every `.env*` file. If a real `.env` reaches the server, remove it and re-deploy: `rm back/.env` on the server.

**`corepack` fails to download pnpm.**
Install pnpm with npm instead: `npm i -g pnpm@11.3.0`.

---

If the machine has no `ssh-keygen` (the OpenSSH client), the deploy
refuses to run: it cannot validate the private key. The risk of the escape
hatch is concrete: a key that is truncated, mis-formatted or still
encrypted with a passphrase fails at connection time with an opaque error,
and the deploy stops there instead of failing fast at the key check. Set
`ALLOW_UNVALIDATED_SSH_KEY=1` only when the key comes from a source you
trust, and check the first connection by hand.

## 8. Tests

The deployment tooling has its own test suite: the rollback tag selection,
the env file writer, the destructive migration guard and the tarball
exclusions.

```bash
# from the repository root
bash scripts/deploy/tests/run-tests.sh
```

CI runs it in the `test:deploy-tooling` job of the `test` stage, so a
broken deploy script never reaches a tag pipeline.

## 9. Useful commands

```bash
# Backend (from back/)
php artisan migrate --status
php artisan glitchvy:prune --dry-run
php artisan config:clear && php artisan route:cache

# Admin SPA (from front/)
pnpm run dev
pnpm run build

# Monorepo root
pnpm install --frozen-lockfile
pnpm --filter @dowardev/glitchvy test:run
pnpm --filter glitchvy-admin test:unit --run
```
