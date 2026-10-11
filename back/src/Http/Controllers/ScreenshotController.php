<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Http\Controllers;

use Glitchvy\Http\Middleware\AuthenticateApiKey;
use Glitchvy\Http\Middleware\AuthenticateUserToken;
use Glitchvy\Models\ErrorReport;
use Glitchvy\Models\ErrorScreenshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * Screenshot endpoints. Storage on a private disk; access is always gated.
 */
class ScreenshotController extends Controller
{
    /**
     * POST /api/glitchvy/errors/{id}/screenshot  (SDK auth: Bearer API key)
     */
    public function upload(Request $request, int $id): JsonResponse
    {
        $report = ErrorReport::find($id);
        if ($report === null) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $request->validate([
            'screenshot' => 'required|file|mimetypes:image/png,image/jpeg,image/webp|max:5120',
        ]);

        $file = $request->file('screenshot');
        $disk = (string) config('glitchvy.storage_disk', 'private');

        $path = $file->store("glitchvy/{$report->id}", $disk);

        $screenshot = new ErrorScreenshot();
        $screenshot->error_report_id = $report->id;
        $screenshot->disk = $disk;
        $screenshot->path = $path;
        $screenshot->mime_type = (string) $file->getMimeType();
        $screenshot->size_bytes = (int) $file->getSize();
        $screenshot->save();

        return new JsonResponse([
            'id' => $screenshot->id,
            'url' => route('glitchvy.screenshot.show', ['id' => $report->id]),
        ], 201);
    }

    /**
     * GET /api/glitchvy/errors/{id}/screenshot  (admin auth)
     *
     * Streams the binary if the user is allowed. The screenshot belongs to
     * an error report which the admin sees in the list view; we only
     * re-check that someone is logged in (the middleware does that).
     */
    public function show(int $id): Response|JsonResponse
    {
        $screenshot = ErrorScreenshot::where('error_report_id', $id)
            ->orderByDesc('id')
            ->first();

        if ($screenshot === null) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $disk = Storage::disk($screenshot->disk);
        if (! $disk->exists($screenshot->path)) {
            return new JsonResponse(['error' => 'file_missing'], 410);
        }

        $contents = $disk->get($screenshot->path);
        return new Response($contents, 200, [
            'Content-Type' => $screenshot->mime_type,
            'Content-Length' => (string) $screenshot->size_bytes,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
