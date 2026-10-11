<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

use Glitchvy\Http\Controllers\AdminApiController;
use Glitchvy\Http\Controllers\IngestController;
use Glitchvy\Http\Controllers\ScreenshotController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| glitchvy routes
|--------------------------------------------------------------------------
|
| Loaded by {@see GlitchvyServiceProvider} inside the configured prefix
| (default `api/glitchvy`). Two authentication flavors:
|
|   - `glitchvy.api`  → Bearer API key, used by the client SDK
|   - `glitchvy.user` → X-Glitchvy-User-Token, used by the admin SPA
|
| Names are stable for the admin SPA to navigate by URL.
*/

Route::middleware(['glitchvy.api'])->group(function (): void {
    Route::post('errors', [IngestController::class, 'store'])
        ->name('glitchvy.errors.store');

    Route::post('errors/{id}/screenshot', [ScreenshotController::class, 'upload'])
        ->whereNumber('id')
        ->name('glitchvy.screenshot.upload');
});

Route::middleware(['glitchvy.user'])->group(function (): void {
    Route::get('errors', [AdminApiController::class, 'index'])
        ->name('glitchvy.errors.index');

    Route::get('errors/{id}', [AdminApiController::class, 'show'])
        ->whereNumber('id')
        ->name('glitchvy.errors.show');

    Route::patch('errors/{id}/status', [AdminApiController::class, 'updateStatus'])
        ->whereNumber('id')
        ->name('glitchvy.errors.status.update');

    Route::get('errors/{id}/screenshot', [ScreenshotController::class, 'show'])
        ->whereNumber('id')
        ->name('glitchvy.screenshot.show');
});
