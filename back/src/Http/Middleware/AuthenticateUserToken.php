<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the `X-Glitchvy-User-Token` header against the consumer app's
 * configured guard (default `web`). For guards that authenticate via a
 * Sanctum/Passport-style bearer token, this resolves the user from the
 * guard's normal flow.
 *
 * For session-style guards (the default `web`), the token must match the
 * configured user table's token column (default `remember_token`). The
 * admin SPA is expected to call the consumer app's login endpoint first
 * to obtain the token, then send it on every admin API request.
 *
 * 401 if header missing. 401 if token does not resolve to a user.
 */
class AuthenticateUserToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->headers->get('X-Glitchvy-User-Token', '');
        if ($token === '') {
            return new JsonResponse([
                'error' => 'unauthenticated',
                'message' => 'Missing X-Glitchvy-User-Token header.',
            ], 401);
        }

        $user = $this->resolveUser($token);

        if (! $user instanceof Authenticatable) {
            return new JsonResponse([
                'error' => 'unauthenticated',
                'message' => 'Invalid user token.',
            ], 401);
        }

        // Bind the user for downstream controllers (`auth()->user()` works).
        Auth::setUser($user);

        return $next($request);
    }

    /**
     * Resolve a user from a token. Strategy:
     *
     *   1. Try the configured guard's `tokenById` / `retrieveByCredentials`
     *      contract via `Auth::guard(...)->user()` with credentials injected.
     *   2. Fallback: look up by table/token column (default: users.remember_token).
     */
    protected function resolveUser(string $token): ?Authenticatable
    {
        $guardName = (string) config('glitchvy.admin_guard', 'web');
        $guard = Auth::guard($guardName);

        // Sanctum-style personal access token first.
        $user = $guard->user();
        if ($user instanceof Authenticatable) {
            return $user;
        }

        // Fallback: lookup directly by token column for session guards.
        $table = (string) config('glitchvy.user.table', 'users');
        $column = (string) config('glitchvy.user.token_column', 'remember_token');

        $modelClass = $this->resolveUserModelClass();
        if ($modelClass === null) {
            return null;
        }

        $instance = $modelClass::query()
            ->where($column, $token)
            ->first();

        return $instance instanceof Authenticatable ? $instance : null;
    }

    protected function resolveUserModelClass(): ?string
    {
        // Use Laravel's configured auth user model.
        $class = config('auth.providers.users.model');
        return is_string($class) && class_exists($class) ? $class : null;
    }
}
