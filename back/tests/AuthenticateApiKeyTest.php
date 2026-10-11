<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Tests;

use Glitchvy\Http\Middleware\AuthenticateApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthenticateApiKeyTest extends TestCase
{
    protected function middleware(): AuthenticateApiKey
    {
        return new AuthenticateApiKey();
    }

    public function test_returns_503_when_api_key_is_not_configured(): void
    {
        config()->set('glitchvy.api_key', '');

        $response = $this->middleware()->handle(new Request(), fn () => 'ok');
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(503, $response->getStatusCode());
        $body = json_decode($response->getContent() ?: '{}', true);
        $this->assertSame('glitchvy_not_configured', $body['error']);
    }

    public function test_returns_401_when_header_is_missing(): void
    {
        config()->set('glitchvy.api_key', 'good-key');

        $response = $this->middleware()->handle(new Request(), fn () => 'ok');
        $this->assertSame(401, $response->getStatusCode());
        $body = json_decode($response->getContent() ?: '{}', true);
        $this->assertSame('unauthenticated', $body['error']);
    }

    public function test_returns_401_when_authorization_malformed(): void
    {
        config()->set('glitchvy.api_key', 'good-key');

        $request = Request::create('/', 'GET');
        $request->headers->set('Authorization', 'Basic abc123');

        $response = $this->middleware()->handle($request, fn () => 'ok');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_returns_401_when_token_is_wrong(): void
    {
        config()->set('glitchvy.api_key', 'good-key');

        $request = Request::create('/', 'GET');
        $request->headers->set('Authorization', 'Bearer wrong-key');

        $response = $this->middleware()->handle($request, fn () => 'ok');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_passes_when_bearer_matches(): void
    {
        config()->set('glitchvy.api_key', 'good-key');

        $request = Request::create('/', 'GET');
        $request->headers->set('Authorization', 'Bearer good-key');

        $called = false;
        $response = $this->middleware()->handle($request, function () use (&$called) {
            $called = true;
            return response('forwarded');
        });
        $this->assertTrue($called);
        $this->assertSame('forwarded', $response->getContent());
    }
}
