<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Http\Controllers;

use App\Models\ErrorReport;
use App\Models\ErrorScreenshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class IngestScreenshotController extends Controller
{
    /**
     * Upload a screenshot for an error report.
     */
    public function store(Request $request, int $id)
    {
        $project = $request->attributes->get('project');

        $errorReport = ErrorReport::where('project_id', $project->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'file' => 'required|file|mimes:png,jpg,jpeg,webp|max:10240',
        ]);

        $file = $validated['file'];
        $disk = config('glitchvy.storage_disk', 'private');
        $path = $file->store('screenshots', $disk);

        $screenshot = ErrorScreenshot::create([
            'error_report_id' => $errorReport->id,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        return response()->json([
            'id' => $screenshot->id,
            'path' => $screenshot->path,
        ], 201);
    }
};