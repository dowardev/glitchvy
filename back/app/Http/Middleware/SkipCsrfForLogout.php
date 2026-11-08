<?php
// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * CSRF protection that leaves the logout route out.
 *
 * The logout revokes the credential the request authenticated with: a
 * browser session ends the session, a bearer client revokes its token.
 * A first-party request that carries a bearer token is a stateful request
 * for Sanctum, so the CSRF check would answer 419 and the token would
 * survive the logout it asked for.
 *
 * Exempting the route is safe: it still requires `auth:sanctum`, it only
 * ends the caller's own session, and the session cookie is SameSite=lax,
 * which keeps a cross-site POST from carrying it in the first place.
 */
class SkipCsrfForLogout extends ValidateCsrfToken
{
    /**
     * The routes that are not subject to the token check.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/auth/logout',
    ];
}
