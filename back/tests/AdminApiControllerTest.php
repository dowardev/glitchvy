<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Tests;

use Glitchvy\Http\Controllers\AdminApiController;
use Glitchvy\Models\ErrorReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminApiControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Seed three reports spanning different levels / statuses.
        ErrorReport::query()->create([
            'event_id' => 'evt-1',
            'app_version' => '0.1.0',
            'environment' => 'production',
            'level' => 'error',
            'error_name' => 'RangeError',
            'error_message' => 'out of range',
            'status' => 'open',
        ]);
        ErrorReport::query()->create([
            'event_id' => 'evt-2',
            'app_version' => '0.1.0',
            'environment' => 'production',
            'level' => 'warn',
            'error_name' => 'NetworkWarn',
            'error_message' => 'slow request',
            'status' => 'triaged',
        ]);
        ErrorReport::query()->create([
            'event_id' => 'evt-3',
            'app_version' => '0.1.0',
            'environment' => 'staging',
            'level' => 'error',
            'error_name' => 'TypeError',
            'error_message' => 'cannot read property foo of undefined',
            'status' => 'open',
        ]);
    }

    public function test_index_lists_all_reports_with_no_filter(): void
    {
        $response = (new AdminApiController())->index(new Request());
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent() ?: '{}', true);
        $this->assertCount(3, $body['data']);
    }

    public function test_index_filters_by_level(): void
    {
        $request = Request::create('/api/glitchvy/errors?level=warn', 'GET');
        $response = (new AdminApiController())->index($request);
        $body = json_decode($response->getContent() ?: '{}', true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('warn', $body['data'][0]['level']);
    }

    public function test_index_filters_by_status(): void
    {
        $request = Request::create('/api/glitchvy/errors?status=triaged', 'GET');
        $response = (new AdminApiController())->index($request);
        $body = json_decode($response->getContent() ?: '{}', true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('triaged', $body['data'][0]['status']);
    }

    public function test_index_searches_message(): void
    {
        $request = Request::create('/api/glitchvy/errors?search=undefined', 'GET');
        $response = (new AdminApiController())->index($request);
        $body = json_decode($response->getContent() ?: '{}', true);
        $this->assertCount(1, $body['data']);
    }

    public function test_show_returns_404_when_missing(): void
    {
        $response = (new AdminApiController())->show(999999);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_show_returns_serialized_report(): void
    {
        $report = ErrorReport::query()->where('event_id', 'evt-1')->firstOrFail();
        $response = (new AdminApiController())->show($report->id);
        $body = json_decode($response->getContent() ?: '{}', true);
        $this->assertSame('RangeError', $body['data']['error_name']);
        // Detailed view includes extras that the list view does not.
        $this->assertArrayHasKey('breadcrumbs', $body['data']);
        $this->assertArrayHasKey('screenshots', $body['data']);
    }

    public function test_update_status_changes_status(): void
    {
        $report = ErrorReport::query()->where('event_id', 'evt-1')->firstOrFail();

        $request = Request::create(
            "/api/glitchvy/errors/{$report->id}/status",
            'PATCH',
            ['status' => 'triaged'],
        );

        $response = (new AdminApiController())->updateStatus($request, $report->id);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('triaged', $report->fresh()->status);
    }

    public function test_update_status_rejects_invalid_value(): void
    {
        $report = ErrorReport::query()->where('event_id', 'evt-1')->firstOrFail();

        $request = Request::create(
            "/api/glitchvy/errors/{$report->id}/status",
            'PATCH',
            ['status' => 'fake-state'],
        );

        $response = (new AdminApiController())->updateStatus($request, $report->id);
        $this->assertSame(422, $response->getStatusCode());
    }
}
