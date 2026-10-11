<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Http\Controllers;

use Glitchvy\Models\ErrorReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * POST /api/glitchvy/errors
 *
 * Receives a {@see ErrorReport} payload from the consumer app's glitchvy SDK.
 * Validates shape, persists, lets {@see ErrorReport}'s `created` event dispatch
 * {@see \Glitchvy\Events\ErrorReported}.
 */
class IngestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $key = 'glitchvy:ingest:'.$request->ip();
        $maxPerMinute = (int) config('glitchvy.rate_limit.per_minute', 5);

        if (RateLimiter::tooManyAttempts($key, $maxPerMinute)) {
            return new JsonResponse([
                'error' => 'rate_limited',
                'message' => 'Too many requests. Try again later.',
                'retry_after_seconds' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return new JsonResponse([
                'error' => 'invalid_payload',
                'message' => 'JSON body must be an object.',
            ], 422);
        }

        $report = $this->buildReport($payload);

        if ($report === null) {
            return new JsonResponse([
                'error' => 'invalid_payload',
                'message' => 'Missing required fields (error.message).',
            ], 422);
        }

        try {
            $report->save();
        } catch (Throwable $e) {
            Log::error('glitchvy: failed to persist error report', [
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse([
                'error' => 'persistence_failed',
                'message' => 'Failed to persist the error report.',
            ], 500);
        }

        return new JsonResponse([
            'id' => $report->id,
            'event_id' => $report->event_id,
        ], 201);
    }

    /**
     * Map the SDK payload into an {@see ErrorReport} model.
     * Returns null if required fields are missing.
     *
     * @param array<string, mixed> $payload
     */
    protected function buildReport(array $payload): ?ErrorReport
    {
        $error = $payload['error'] ?? null;
        if (! is_array($error)) {
            return null;
        }
        $message = $error['message'] ?? null;
        if (! is_string($message) || $message === '') {
            return null;
        }
        $name = is_string($error['name'] ?? null) ? $error['name'] : 'Error';
        if ($name === '') {
            $name = 'Error';
        }
        $type = is_string($error['type'] ?? null) ? $error['type'] : null;
        $stack = is_string($error['stack'] ?? null) ? $error['stack'] : null;

        $level = (string) ($payload['level'] ?? 'error');
        if (! in_array($level, ['error', 'warn', 'info'], true)) {
            $level = 'error';
        }

        $context = $payload['context'] ?? [];
        if (! is_array($context)) {
            $context = [];
        }

        $user = $payload['user'] ?? null;

        $breadcrumbs = $payload['breadcrumbs'] ?? [];
        if (! is_array($breadcrumbs)) {
            $breadcrumbs = [];
        }

        $report = new ErrorReport();
        $report->event_id = (string) ($payload['eventId'] ?? '');
        $report->app_version = (string) ($payload['appVersion'] ?? '');
        $report->environment = (string) ($payload['environment'] ?? '');
        $report->platform = (string) ($payload['platform'] ?? 'web');
        $report->route = is_string($payload['route'] ?? null) ? substr($payload['route'], 0, 1024) : null;
        $report->user_agent = is_string($payload['userAgent'] ?? null) ? $payload['userAgent'] : null;
        $report->level = $level;
        $report->error_name = substr($name, 0, 128);
        $report->error_message = $message;
        $report->error_type = $type !== null ? substr($type, 0, 128) : null;
        $report->error_stack = $stack;

        $report->entity_type = isset($context['entityType']) ? (string) $context['entityType'] : null;
        $report->entity_id = isset($context['entityId']) ? (string) $context['entityId'] : null;
        $report->operation = isset($context['operation']) ? (string) $context['operation'] : null;
        $report->tags = is_array($context['tags'] ?? null) ? $context['tags'] : null;
        $report->data = is_array($context['data'] ?? null) ? $context['data'] : null;

        if (is_array($user)) {
            $report->user_id = isset($user['userId']) ? (string) $user['userId'] : null;
            $report->user_payload = $user;
        }

        $report->breadcrumbs = $breadcrumbs;

        return $report;
    }
}
