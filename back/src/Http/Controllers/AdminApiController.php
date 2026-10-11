<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Http\Controllers;

use Glitchvy\Models\ErrorReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON API consumed by the glitchvy-admin SPA.
 *
 * All routes are protected by {@see AuthenticateUserToken}.
 */
class AdminApiController extends Controller
{
    /**
     * GET /api/glitchvy/errors
     *
     * Paginated list with optional filters. Query params:
     *  - level     (error | warn | info)
     *  - status    (open | triaged | resolved | closed)
     *  - search    (free-text search over error_message)
     *  - from, to  (ISO-8601 date filters on created_at)
     *  - per_page  (default 25, max 100)
     *  - cursor    (cursor pagination token)
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) min(100, max(1, (int) $request->query('per_page', '25')));

        $query = ErrorReport::query();

        if ($level = $request->query('level')) {
            $query->where('level', $level);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where('error_message', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $search).'%');
        }
        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        $reports = $query
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        return new JsonResponse([
            'data' => $reports->getCollection()->map(fn (ErrorReport $r) => $this->serialize($r))->all(),
            'next_cursor' => $reports->nextCursor()?->encode(),
            'prev_cursor' => $reports->previousCursor()?->encode(),
        ]);
    }

    /**
     * GET /api/glitchvy/errors/{id}
     */
    public function show(int $id): JsonResponse
    {
        $report = ErrorReport::with('screenshots')->find($id);
        if ($report === null) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }
        return new JsonResponse(['data' => $this->serialize($report, detailed: true)]);
    }

    /**
     * PATCH /api/glitchvy/errors/{id}/status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $report = ErrorReport::find($id);
        if ($report === null) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|string',
        ]);

        if (! ErrorReport::isValidStatus($validated['status'])) {
            return new JsonResponse([
                'error' => 'invalid_status',
                'allowed' => ErrorReport::STATUSES,
            ], 422);
        }

        $report->status = $validated['status'];
        $report->save();

        return new JsonResponse(['data' => $this->serialize($report)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(ErrorReport $report, bool $detailed = false): array
    {
        $base = [
            'id' => $report->id,
            'level' => $report->level,
            'status' => $report->status,
            'environment' => $report->environment,
            'app_version' => $report->app_version,
            'platform' => $report->platform,
            'route' => $report->route,
            'error_name' => $report->error_name,
            'error_message' => $report->error_message,
            'entity_type' => $report->entity_type,
            'entity_id' => $report->entity_id,
            'operation' => $report->operation,
            'created_at' => $report->created_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $base['user_agent'] = $report->user_agent;
            $base['error_type'] = $report->error_type;
            $base['error_stack'] = $report->error_stack;
            $base['tags'] = $report->tags;
            $base['data'] = $report->data;
            $base['user'] = $report->user_payload;
            $base['breadcrumbs'] = $report->breadcrumbs;
            $base['screenshots'] = $report->screenshots->map(fn ($s) => [
                'id' => $s->id,
                'mime_type' => $s->mime_type,
                'size_bytes' => $s->size_bytes,
                'created_at' => $s->created_at?->toIso8601String(),
                'has_screenshot' => true,
            ])->all();
        }

        return $base;
    }
}
