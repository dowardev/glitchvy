<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 DowarDev

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Support\StrictValidateCsrfToken;

class AdminSessionAuthTest extends \Tests\TestCase
{
    private function createUser(string $email = 'admin@glitchvy.app', string $password = 'password123'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make($password),
        ]);
    }

    private function postLogin(string $email, string $password, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => $password,
        ], $headers);
    }

    public function test_spa_login_returns_user_with_null_token_and_sets_session_cookie(): void
    {
        Config::set('sanctum.stateful', ['localhost:8100']);

        $user = $this->createUser();

        $response = $this->postLogin($user->email, 'password123', [
            'Referer' => 'http://localhost:8100/errors',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['user', 'token']);
        $this->assertNull($response->json('token'), 'SPA login should return null token');
        $this->assertNotNull($response->json('user'));
        $this->assertSame($user->email, $response->json('user.email'));

        // Session cookie should be set
        $this->assertTrue(
            $response->headers->has('Set-Cookie'),
            'Response should have Set-Cookie header for session'
        );
    }

    public function test_spa_subsequent_request_with_cookie_is_authenticated(): void
    {
        Config::set('sanctum.stateful', ['localhost:8100']);

        $user = $this->createUser();

        // Login and capture cookies
        $loginResponse = $this->postLogin($user->email, 'password123', [
            'Referer' => 'http://localhost:8100/errors',
        ]);
        $loginResponse->assertStatus(200);

        $cookies = $loginResponse->headers->getCookies();
        $sessionCookie = null;
        foreach ($cookies as $cookie) {
            if (str_starts_with($cookie->getName(), 'laravel_session')) {
                $sessionCookie = $cookie;
                break;
            }
        }
        $this->assertNotNull($sessionCookie, 'Laravel session cookie should be set');

        // Make authenticated request with the session cookie
        $meResponse = $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue())
            ->getJson('/api/auth/me');

        $meResponse->assertStatus(200);
        $this->assertSame($user->email, $meResponse->json('email'));
    }

    public function test_api_client_login_returns_bearer_token(): void
    {
        // No Referer header - plain API client
        $user = $this->createUser();

        $response = $this->postLogin($user->email, 'password123');

        $response->assertStatus(200);
        $response->assertJsonStructure(['user', 'token']);
        $this->assertNotNull($response->json('token'), 'API client login should return a bearer token');
        $this->assertIsString($response->json('token'));
    }

    public function test_api_client_token_authenticates_via_authorization_header(): void
    {
        $user = $this->createUser();

        $loginResponse = $this->postLogin($user->email, 'password123');
        $loginResponse->assertStatus(200);

        $token = $loginResponse->json('token');
        $this->assertNotNull($token);

        $meResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/auth/me');

        $meResponse->assertStatus(200);
        $this->assertSame($user->email, $meResponse->json('email'));
    }

    public function test_csrf_cookie_endpoint_returns_204_and_sets_xsrf_token(): void
    {
        Config::set('sanctum.stateful', ['localhost:8100']);

        $response = $this->get('/api/glitchvy/csrf-cookie', [
            'Referer' => 'http://localhost:8100/',
        ]);

        $response->assertStatus(204);

        $cookies = $response->headers->getCookies();
        $xsrfCookie = null;
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === 'XSRF-TOKEN') {
                $xsrfCookie = $cookie;
                break;
            }
        }
        $this->assertNotNull($xsrfCookie, 'XSRF-TOKEN cookie should be set');
    }

    /**
     * A mutating request without the XSRF token must be refused, and the
     * same request must go through when the token is echoed back. The
     * framework skips the token validation while the test suite runs, so
     * the strict middleware stands in for the production one.
     */
    public function test_mutating_request_without_the_xsrf_token_is_refused(): void
    {
        Config::set('sanctum.stateful', ['localhost:8100']);
        Config::set('sanctum.middleware.validate_csrf_token', StrictValidateCsrfToken::class);

        $user = $this->createUser();

        // Bootstrap the cookie the way the SPA does before it logs in.
        $bootstrap = $this->get('/api/glitchvy/csrf-cookie', [
            'Referer' => 'http://localhost:8100/',
        ]);
        $bootstrap->assertStatus(204);

        $xsrf = $this->cookieNamed($bootstrap, 'XSRF-TOKEN');
        $session = $this->cookieNamed($bootstrap, 'laravel_session');
        $this->assertNotNull($xsrf, 'XSRF-TOKEN cookie should be set');
        $this->assertNotNull($session, 'session cookie should be set');

        // Without the token: refused.
        $withoutToken = $this->withCookie($session->getName(), $session->getValue())
            ->withHeaders(['Referer' => 'http://localhost:8100/errors'])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password123',
            ]);
        $withoutToken->assertStatus(419);

        // With the token: the login goes through.
        $withToken = $this->withCookie($session->getName(), $session->getValue())
            ->withCookie('XSRF-TOKEN', $xsrf->getValue())
            ->withHeaders([
                'Referer' => 'http://localhost:8100/errors',
                'X-XSRF-TOKEN' => $xsrf->getValue(),
            ])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password123',
            ]);
        $withToken->assertStatus(200);
        $this->assertNull($withToken->json('token'));
    }

    /**
     * Find a cookie by name in a response.
     */
    private function cookieNamed(\Illuminate\Testing\TestResponse $response, string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    public function test_api_client_logout_revokes_the_token(): void
    {
        $user = $this->createUser();

        $login = $this->postLogin($user->email, 'password123');
        $login->assertStatus(200);
        $token = $login->json('token');
        $this->assertNotNull($token);
        $this->assertSame(1, $user->tokens()->count());

        $logout = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/auth/logout');
        $logout->assertStatus(200);

        // The token the request authenticated with is gone.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_bearer_logout_revokes_the_token_even_from_a_first_party_origin(): void
    {
        Config::set('sanctum.stateful', ['localhost:8100']);
        // The strict middleware proves the production CSRF behaviour.
        Config::set('sanctum.middleware.validate_csrf_token', StrictValidateCsrfToken::class);

        $user = $this->createUser();

        $login = $this->postLogin($user->email, 'password123');
        $login->assertStatus(200);
        $token = $login->json('token');
        $this->assertNotNull($token);

        // The request looks like the SPA (first-party origin) but it
        // authenticated with the bearer token: the token must be revoked,
        // not just a session. It carries no XSRF token, so the logout
        // route must not depend on one.
        $logout = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Referer' => 'http://localhost:8100/errors',
        ])->postJson('/api/auth/logout');
        $logout->assertStatus(200);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_logout_route_is_exempt_from_the_csrf_check(): void
    {
        // The test environment does not load the application config
        // directory, so the production wiring is pinned against the file
        // itself: the middleware that leaves the logout route out is the
        // one the application configures.
        $config = require __DIR__ . '/../../config/sanctum.php';

        $this->assertSame(
            \App\Http\Middleware\SkipCsrfForLogout::class,
            $config['middleware']['validate_csrf_token']
        );
    }

    public function test_spa_login_revokes_the_tokens_the_previous_flow_issued(): void
    {
        Config::set('sanctum.stateful', ['localhost:8100']);

        $user = $this->createUser();
        // The previous SPA flow logged in without a device name, so the
        // backend named the token 'api-token'.
        $legacy = $user->createToken('api-token')->plainTextToken;
        // A client that names its device keeps its token.
        $named = $user->createToken('ci-pipeline')->plainTextToken;
        $this->assertSame(2, $user->tokens()->count());

        $login = $this->postLogin($user->email, 'password123', [
            'Referer' => 'http://localhost:8100/errors',
        ]);
        $login->assertStatus(200);
        $this->assertNull($login->json('token'));

        // The session is the credential now: the old token is gone.
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::findToken($legacy));
        $this->assertNotNull(\Laravel\Sanctum\PersonalAccessToken::findToken($named));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_spa_logout_invalidates_session(): void
    {
        Config::set('sanctum.stateful', ['localhost:8100']);

        $user = $this->createUser();

        // Login
        $loginResponse = $this->postLogin($user->email, 'password123', [
            'Referer' => 'http://localhost:8100/errors',
        ]);
        $loginResponse->assertStatus(200);

        $sessionCookie = $this->cookieNamed($loginResponse, 'laravel_session');
        $this->assertNotNull($sessionCookie);

        // Logout with session cookie (include Referer for first-party detection)
        $logoutResponse = $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue())
            ->withHeaders(['Referer' => 'http://localhost:8100/errors'])
            ->postJson('/api/auth/logout');
        $logoutResponse->assertStatus(200);
        $this->assertSame('Logged out', $logoutResponse->json('message'));

        // The session no longer holds the user, and the session was
        // rotated: the response carries a new session cookie. (The
        // next request cannot be asserted here: the test application
        // instance is shared by every request of the test, so the
        // authenticated user would leak into it.)
        $this->assertNull(auth()->guard('web')->user());
        $newSession = $this->cookieNamed($logoutResponse, 'laravel_session');
        $this->assertNotNull($newSession);
        $this->assertNotSame($sessionCookie->getValue(), $newSession->getValue());
    }
}