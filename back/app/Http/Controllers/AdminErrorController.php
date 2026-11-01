<?php
// SPDX-FileCopyrightText: 2024 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Http\Resources\ErrorReportResource;
use App\Models\ErrorReport;
use Illuminate\Http\Request;

class AdminErrorController extends Controller
{
    /**
     * Display a listing of error reports.
     */
    public function index(Request $request)
    {
        $query = ErrorReport::with('project')
            ->when($request->filled('environment'), function ($q) use ($request) {
                $q->where('environment', $request->string('environment'));
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status', $request->string('status'));
            })
            ->when($request->filled('level'), function ($q) use ($request) {
                $q->where('level', $request->string('level'));
            })
            ->when($request->filled('project_id'), function ($q) use ($request) {
                $q->where('project_id', $request->integer('project_id'));
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->where('error_name', 'like', "%{$request->string('search')}%")
                        ->orWhere('error_message', 'like', "%{$request->string('search')}%");
                });
            })
            ->latest();

        // `per_page` is the documented parameter; `limit` is accepted as
        // an alias for clients that were written against the earlier one.
        $perPage = $request->integer('per_page', $request->integer('limit', 20));

        $page = $query->cursorPaginate($perPage);

        return response()->json([
            'data' => ErrorReportResource::collection($page->items())->resolve(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'prev_cursor' => $page->previousCursor()?->encode(),
        ]);
    }

    /**
     * Display the specified error report.
     */
    public function show(Request $request, int $id)
    {
        $errorReport = ErrorReport::with(['project', 'screenshots'])
            ->findOrFail($id);

        return response()->json(new ErrorReportResource($errorReport));
    }

    /**
     * Update the status of the specified error report.
     */
    public function updateStatus(Request $request, int $id)
    {
        $errorReport = ErrorReport::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:open,in_progress,resolved,ignored',
        ]);

        $errorReport->update(['status' => $validated['status']]);

        return response()->json(new ErrorReportResource($errorReport->fresh()));
    }
}
