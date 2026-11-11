<?php

use App\Http\Middleware\SkipCsrfForLogout;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

// The domains that open a session instead of answering a bearer token.
//
// The loopback interface is always stateful, on any port: the admin SPA
// runs on a port of its own (Ionic picks a free one, and it changes
// between runs), and every one of those ports has to reach the API with
// a session cookie.
//
// The port wildcard is a Str::is pattern, not a Sanctum feature:
// Sanctum matches the browser origin against "<entry>/*" and Str::is
// understands the glob. A domain without a port only matches the bare
// host, so "localhost" alone would leave the SPA unauthenticated on
// http://localhost:8105 and on every port but the default one.
//
// The IPv6 loopback is listed in its bracketed form only: that is the
// one a browser sends in an origin.
//
// SANCTUM_STATEFUL_DOMAINS adds the deployment domains to this list.
$statefulDomains = array_merge(
    ['localhost', 'localhost:*', '127.0.0.1', '127.0.0.1:*', '[::1]', '[::1]:*'],
    array_filter(array_map('trim', explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', '')))),
    [Sanctum::currentApplicationUrlWithPort()],
);

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    | See $statefulDomains above for how the list is built.
    |
    */

    'stateful' => array_values(array_unique(array_filter($statefulDomains))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => SkipCsrfForLogout::class,
    ],

];
