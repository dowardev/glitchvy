<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the `Authorization: Bearer ...` header against
 * `config('glitchvy.api_key')`. Used by routes that the consumer app's
 * glitchvy SDK calls (ingestion, screenshot upload).
 *
 * 401 if header missing or wrong. 503 if the consumer forgot to set the key.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('glitchvy.api_key', '');

        if ($expected === '') {
            return new JsonResponse([
                'error' => 'glitchvy_not_configured',
                'message' => 'GLITCHVY_API_KEY is not set on the server.',
            ], 503);
        }

        $authHeader = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return new JsonResponse([
                'error' => 'unauthenticated',
                'message' => 'Missing or malformed Authorization header.',
            ], 401);
        }

        $presented = substr($authHeader, 7);
        if (!hash_equals($expected, $presented)) {
            return new JsonResponse([
                'error' => 'unauthenticated',
                'message' => 'Invalid API key.',
            ], 401);
        }

        return $next($request);
    }
}
