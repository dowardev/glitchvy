<?php
// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;

class CsrfCookieRateLimitTest extends \Tests\TestCase
{
    public function test_csrf_cookie_endpoint_answers_429_past_the_limit(): void
    {
        Config::set('sanctum.stateful', ['localhost:8100']);
        Config::set('glitchvy.csrf_rate_limit', '2,1');

        $headers = ['Referer' => 'http://localhost:8100/'];

        $this->get('/api/glitchvy/csrf-cookie', $headers)->assertStatus(204);
        $this->get('/api/glitchvy/csrf-cookie', $headers)->assertStatus(204);

        $refused = $this->get('/api/glitchvy/csrf-cookie', $headers);
        $refused->assertStatus(429);

        // The same contract as the framework throttle.
        $refused->assertJson(['message' => 'Too Many Attempts.']);
        $this->assertSame('2', $refused->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $refused->headers->get('X-RateLimit-Remaining'));
        $this->assertNotNull($refused->headers->get('Retry-After'));
    }

    public function test_a_refused_csrf_request_does_not_start_a_session(): void
    {
        Config::set('sanctum.stateful', ['localhost:8100']);
        Config::set('glitchvy.csrf_rate_limit', '1,1');

        $headers = ['Referer' => 'http://localhost:8100/'];

        $this->get('/api/glitchvy/csrf-cookie', $headers)->assertStatus(204);

        $refused = $this->get('/api/glitchvy/csrf-cookie', $headers);
        $refused->assertStatus(429);

        // The throttle runs before the session is started, so a refused
        // request leaves no session record behind.
        $this->assertNull($this->cookieNamed($refused, 'laravel_session'));
    }

    public function test_csrf_cookie_endpoint_is_reachable_for_a_first_party_request(): void
    {
        Config::set('sanctum.stateful', ['localhost:8100']);

        $response = $this->get('/api/glitchvy/csrf-cookie', [
            'Referer' => 'http://localhost:8100/',
        ]);

        $response->assertStatus(204);
        $this->assertTrue(
            collect($response->headers->getCookies())->contains(
                fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN'
            ),
            'the endpoint must hand out the XSRF token'
        );
    }
}
