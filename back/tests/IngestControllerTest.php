<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Tests;

use Glitchvy\Http\Controllers\IngestController;
use Glitchvy\Models\ErrorReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class IngestControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        RateLimiter::clear('glitchvy:ingest:127.0.0.1');
        parent::tearDown();
    }

    public function test_persists_a_valid_payload(): void
    {
        $payload = $this->validPayload();

        $request = Request::create('/api/glitchvy/errors', 'POST', [], [], [], [], json_encode($payload));
        $request->headers->set('Content-Type', 'application/json');

        $controller = new IngestController();
        $response = $controller->store($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getContent() ?: '{}', true);
        $this->assertArrayHasKey('id', $body);
        $this->assertSame('evt-001', $body['event_id']);

        $this->assertDatabaseHas('error_reports', [
            'id' => $body['id'],
            'error_name' => 'RangeError',
            'entity_type' => 'budget_entry',
            'entity_id' => '42',
            'level' => 'error',
        ]);
    }

    public function test_rejects_payload_missing_error(): void
    {
        $payload = ['appVersion' => '0.1', 'environment' => 'test'];

        $request = Request::create('/api/glitchvy/errors', 'POST', [], [], [], [], json_encode($payload));
        $request->headers->set('Content-Type', 'application/json');

        $response = (new IngestController())->store($request);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_rejects_payload_missing_error_message(): void
    {
        $payload = $this->validPayload();
        $payload['error'] = ['name' => 'RangeError'];

        $request = Request::create('/api/glitchvy/errors', 'POST', [], [], [], [], json_encode($payload));
        $request->headers->set('Content-Type', 'application/json');

        $response = (new IngestController())->store($request);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_rate_limit_caps_requests_per_minute(): void
    {
        $payload = $this->validPayload();
        $controller = new IngestController();

        // Hit it 5 times — within budget.
        for ($i = 0; $i < 5; $i++) {
            $payload['eventId'] = 'evt-rl-'.$i;
            $request = Request::create('/api/glitchvy/errors', 'POST', [], [], [], [], json_encode($payload));
            $request->headers->set('Content-Type', 'application/json');
            $request->server->set('REMOTE_ADDR', '10.0.0.99');

            $response = $controller->store($request);
            $this->assertSame(201, $response->getStatusCode(), "Request $i should be accepted");
        }

        // 6th hit from the same IP should be rate-limited.
        $payload['eventId'] = 'evt-rl-6';
        $request = Request::create('/api/glitchvy/errors', 'POST', [], [], [], [], json_encode($payload));
        $request->headers->set('Content-Type', 'application/json');
        $request->server->set('REMOTE_ADDR', '10.0.0.99');

        $response = $controller->store($request);
        $this->assertSame(429, $response->getStatusCode());
        $body = json_decode($response->getContent() ?: '{}', true);
        $this->assertSame('rate_limited', $body['error']);

        // Cleanup
        RateLimiter::clear('glitchvy:ingest:10.0.0.99');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'eventId' => 'evt-001',
            'timestamp' => '2026-01-01T00:00:00.000Z',
            'appVersion' => '0.1.0',
            'environment' => 'production',
            'platform' => 'web',
            'route' => '/budget',
            'userAgent' => 'Mozilla/5.0',
            'level' => 'error',
            'error' => [
                'name' => 'RangeError',
                'message' => 'array index out of range',
                'type' => 'RangeError',
                'stack' => 'RangeError: ...\n    at X (file.js:1:1)',
            ],
            'context' => [
                'entityType' => 'budget_entry',
                'entityId' => 42,
                'operation' => 'create',
                'tags' => ['app' => 'oneSpend'],
                'data' => ['entry' => ['name' => '[REDACTED]', 'amount' => '[REDACTED]']],
            ],
            'user' => ['userId' => '42', 'email' => 'u@example.com'],
            'breadcrumbs' => [
                ['message' => 'opened form', 'timestamp' => '2026-01-01T00:00:00.000Z'],
            ],
        ];
    }
}
