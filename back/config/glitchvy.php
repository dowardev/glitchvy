<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

return [

    /*
    |--------------------------------------------------------------------------
    | API key
    |--------------------------------------------------------------------------
    |
    | The shared Bearer token the glitchvy SDK uses to authenticate against
    | the ingestion endpoint. Generate or rotate it with:
    |
    |     php artisan glitchvy:key --rotate
    |
    | Or generate manually:
    |
    |     php -r 'echo bin2hex(random_bytes(32));'
    |
    */

    'api_key' => env('GLITCHVY_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'prefix' => env('GLITCHVY_ROUTES_PREFIX', 'api/glitchvy'),

        /*
         | Comma-separated list of origins allowed to call the SDK endpoints
         | via CORS. Use `*` to allow all (development only). Empty array
         | disables CORS for the SDK routes.
         */
        'allowed_origins' => array_filter(array_map(
            'trim',
            explode(',', (string) env('GLITCHVY_ALLOWED_ORIGINS', '')),
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting (ingestion endpoint)
    |--------------------------------------------------------------------------
    |
    | Default: 5 requests per minute per IP. Tune via env or config.
    */

    'rate_limit' => [
        'per_minute' => (int) env('GLITCHVY_RATE_LIMIT_PER_MINUTE', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data retention
    |--------------------------------------------------------------------------
    |
    | Errors older than this (in days) are eligible for `glitchvy:prune`.
    | Default: 30 days.
    */

    'retention_days' => (int) env('GLITCHVY_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Where to store screenshot attachments. The default `private` disk is
    | recommended: the admin SPA streams screenshots via an authenticated
    | endpoint, never via a public URL.
    |
    | If you set this to `public` for testing or self-hosted deployments,
    | you take responsibility for restricting access at the HTTP layer.
    */

    'storage_disk' => env('GLITCHVY_STORAGE_DISK', 'private'),

    /*
    |--------------------------------------------------------------------------
    | Admin auth
    |--------------------------------------------------------------------------
    |
    | The admin SPA authenticates by sending an `X-Glitchvy-User-Token`
    | header whose value resolves a user through the consumer app's auth
    | guard (Sanctum, Passport, your custom guard, etc.). Configure the
    | guard name here. The default `web` is the standard session guard.
    |
    | For non-SPA setups or fully-decoupled deployments, swap the guard
    | to a stateless one (e.g. `sanctum`, `passport`).
    */

    'admin_guard' => env('GLITCHVY_ADMIN_GUARD', 'web'),

    /*
    |--------------------------------------------------------------------------
    | User table (admin auth lookup)
    |--------------------------------------------------------------------------
    |
    | Table used to validate the X-Glitchvy-User-Token when the resolved
    | auth guard exposes one of these tokens. Defaults to the consumer's
    | users table. Only consulted by the bundled AuthenticateUserToken
    | middleware; if you wire your own guard you can ignore this.
    */

    'user' => [
        'table' => env('GLITCHVY_USER_TABLE', 'users'),
        'token_column' => env('GLITCHVY_USER_TOKEN_COLUMN', 'remember_token'),
    ],
];
