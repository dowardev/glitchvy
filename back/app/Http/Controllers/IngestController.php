<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Http\Controllers;

use App\Models\ErrorReport;
use App\Rules\StringMap;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IngestController extends Controller
{
    /**
     * Store a new error report.
     */
    public function store(Request $request)
    {
        $project = $request->attributes->get('project');

        $validated = $request->validate([
            'event_id' => 'nullable|uuid',
            'app_version' => 'nullable|string|max:50',
            'environment' => 'required|string|max:50',
            'platform' => 'nullable|string|max:50',
            'route' => 'nullable|string|max:255',
            'user_agent' => 'nullable|string',
            'level' => 'nullable|in:debug,info,warning,error,critical',
            'error_name' => 'required|string|max:255',
            'error_message' => 'required|string',
            'error_type' => 'nullable|string|max:255',
            'error_stack' => 'nullable|string',
            'entity_type' => 'nullable|string|max:100',
            'entity_id' => 'nullable|integer',
            'operation' => 'nullable|string|max:100',
            'tags' => ['nullable', 'array', new StringMap],
            'data' => 'nullable|array',
            'user_id' => 'nullable|integer',
            'user_payload' => 'nullable|array',
            'breadcrumbs' => 'nullable|array',
        ]);

        // The SDK assigns a UUID per captured event and the same event
        // may be retried (network blip, queued offline flush, etc.). If
        // the same event_id comes in twice for the same project, return
        // the existing row with 200 so the client stops retrying. The
        // error_reports table doesn't track a "count" or "last_seen_at"
        // — each event_id is its own row.
        //
        // When the payload carries no event_id we mint one server-side.
        // The column is UNIQUE, but a unique index does not constrain
        // NULLs, so a NULL event_id would let every concurrent ingest
        // insert its own row with the index silently doing nothing.
        // Minting the id here keeps the index meaningful and guarantees
        // every stored report is addressable by a unique event id.
        //
        // Note: two payloads that both omit event_id are genuinely two
        // different events as far as the server is concerned — there is
        // no shared identity to deduplicate on. The SDK always sends an
        // event_id, so the retry path is the one that matters.
        if (empty($validated['event_id'])) {
            $validated['event_id'] = (string) Str::uuid();
        }

        $existing = ErrorReport::where('project_id', $project->id)
            ->where('event_id', $validated['event_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'id' => $existing->id,
                'event_id' => $existing->event_id,
                'deduplicated' => true,
            ], 200);
        }

        try {
            $errorReport = ErrorReport::create([
                'project_id' => $project->id,
                ...$validated,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two SDK instances both sent the same event_id in parallel
            // and both lost the existing-row check above. Re-read the
            // winning row and respond with 200 so the client stops
            // retrying.
            $existing = ErrorReport::where('project_id', $project->id)
                ->where('event_id', $validated['event_id'])
                ->firstOrFail();

            return response()->json([
                'id' => $existing->id,
                'event_id' => $existing->event_id,
                'deduplicated' => true,
            ], 200);
        }

        return response()->json([
            'id' => $errorReport->id,
            'event_id' => $errorReport->event_id,
        ], 201);
    }
};