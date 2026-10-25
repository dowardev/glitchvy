<?php
// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Resources;

use App\Models\ErrorReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes an {@see ErrorReport} for the admin SPA.
 *
 * The DB columns are storage-oriented (`error_message`, `error_type`,
 * `error_stack`, `data`). The admin SPA expects Sentry-style names
 * (`message`, `type`, `stack_trace`, `context`). This resource is
 * the only place that mapping lives so it stays in sync between
 * the list and the detail endpoints.
 *
 * Per-event fields the SPA wants but the DB doesn't store:
 *   - `count`         — always 1 (each event_id is its own row)
 *   - `first_seen_at` — aliased to `created_at`
 *   - `last_seen_at`  — aliased to `updated_at`
 */
class ErrorReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ErrorReport $r */
        $r = $this->resource;

        return [
            'id' => $r->id,
            'project_id' => $r->project_id,
            'event_id' => $r->event_id,
            'environment' => $r->environment,
            'level' => $r->level,
            'message' => $r->error_message,
            'type' => $r->error_type,
            'status' => $r->status,
            'count' => 1,
            'first_seen_at' => optional($r->created_at)?->toIso8601String(),
            'last_seen_at' => optional($r->updated_at)?->toIso8601String(),
            // Detail-only fields; harmless on list responses.
            'stack_trace' => $r->error_stack,
            'breadcrumbs' => $r->breadcrumbs ?? [],
            'context' => $r->data ?? [],
            'tags' => $r->tags ?? [],
            // Eager-loaded relation metadata; an empty array when the
            // relation was not loaded (list responses), never null, so
            // the SPA can read .length without a guard.
            'screenshots' => $r->relationLoaded('screenshots')
                ? $r->screenshots->map(fn ($s) => [
                    'id' => $s->id,
                    'error_report_id' => $s->error_report_id,
                    'filename' => basename($s->path),
                    'content_type' => $s->mime_type,
                    'size_bytes' => $s->size_bytes,
                    'created_at' => optional($s->created_at)?->toIso8601String(),
                    'url' => "/api/glitchvy/errors/{$r->id}/screenshot",
                ])->all()
                : [],
        ];
    }
}
