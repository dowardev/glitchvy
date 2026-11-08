<?php
// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiter for the routes that open a browser session.
 *
 * It runs BEFORE the stateful middleware, and that is the whole point:
 * the framework starts and persists the session before the route level
 * throttle, so a client that only wants to create session records would
 * create one per request, including the ones answered with 429.
 *
 * The budget is read from `glitchvy.<budget>` (`requests,minutes`). The
 * scope is `ip`, or `ip_email` for the login, where a budget per account
 * is the useful one.
 */
class ThrottleFirstPartyRequests
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $budget = 'csrf_rate_limit', string $scope = 'ip'): Response
    {
        $default = $budget === 'login_rate_limit' ? [5, 1] : [60, 1];
        $config = config('glitchvy.'.$budget);

        [$attempts, $decay] = is_string($config) && str_contains($config, ',')
            ? array_map('intval', explode(',', $config, 2))
            : $default;

        if ($attempts <= 0 || $decay <= 0) {
            [$attempts, $decay] = $default;
        }

        $key = 'glitchvy:'.$budget.':'.$request->ip();

        if ($scope === 'ip_email') {
            $key .= '|'.strtolower((string) $request->input('email', ''));
        }

        // One atomic step: a check followed by a hit would let two
        // concurrent requests both pass the check.
        if (! RateLimiter::attempt($key, $attempts, fn () => true, $decay * 60)) {
            // The same contract as the framework throttle: the message and
            // the rate limit headers a client reads to know when to retry.
            return response()->json(['message' => 'Too Many Attempts.'], 429, [
                'X-RateLimit-Limit' => $attempts,
                'X-RateLimit-Remaining' => 0,
                'Retry-After' => RateLimiter::availableIn($key),
            ]);
        }

        return $next($request);
    }
}
