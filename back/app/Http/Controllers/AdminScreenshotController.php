<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Http\Controllers;

use App\Models\ErrorReport;
use App\Models\ErrorScreenshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AdminScreenshotController extends Controller
{
    /**
     * Display the screenshot for an error report.
     */
    public function show(Request $request, int $id)
    {
        $errorReport = ErrorReport::findOrFail($id);

        $screenshot = ErrorScreenshot::where('error_report_id', $errorReport->id)
            ->latest()
            ->firstOrFail();

        $disk = Storage::disk($screenshot->disk);

        if (! $disk->exists($screenshot->path)) {
            return response()->json(['message' => 'Screenshot not found'], 404);
        }

        return response()->streamDownload(function () use ($disk, $screenshot) {
            echo $disk->get($screenshot->path);
        }, "screenshot-{$screenshot->id}.{$this->extension($screenshot->mime_type)}", [
            'Content-Type' => $screenshot->mime_type,
            'Content-Length' => $screenshot->size_bytes,
        ]);
    }

    private function extension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }
};