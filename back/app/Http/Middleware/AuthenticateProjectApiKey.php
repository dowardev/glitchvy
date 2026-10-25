<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateProjectApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization');

        if (! $header || ! str_starts_with($header, 'Bearer ')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $plainKey = substr($header, 7);
        $apiKey = ApiKey::findByPlainKey($plainKey);

        if (! $apiKey || $apiKey->revoked_at) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $apiKey->project) {
            return response()->json(['message' => 'Service unavailable'], 503);
        }

        $apiKey->update(['last_used_at' => now()]);

        $request->attributes->set('project', $apiKey->project);

        return $next($request);
    }
};