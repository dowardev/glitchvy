<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Tests;

use Glitchvy\Http\Middleware\AuthenticateUserToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticateUserTokenTest extends TestCase
{
    public function test_returns_401_when_header_is_missing(): void
    {
        $response = (new AuthenticateUserToken())->handle(new Request(), fn () => 'ok');
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());
        $body = json_decode($response->getContent() ?: '{}', true);
        $this->assertSame('Missing X-Glitchvy-User-Token header.', $body['message']);
    }

    public function test_returns_401_when_token_does_not_resolve(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('X-Glitchvy-User-Token', 'does-not-exist');

        $response = (new AuthenticateUserToken())->handle($request, fn () => 'ok');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_passes_when_user_is_resolved_via_remember_token(): void
    {
        $user = $this->makeFakeUser('my-token-123');

        $request = Request::create('/', 'GET');
        $request->headers->set('X-Glitchvy-User-Token', 'my-token-123');

        $called = false;
        $response = (new AuthenticateUserToken())->handle(
            $request,
            function () use (&$called) {
                $called = true;
                return response('forwarded');
            },
        );
        $this->assertTrue($called);
        $this->assertSame('forwarded', $response->getContent());
        $this->assertSame($user->id, Auth::id());
    }
}
