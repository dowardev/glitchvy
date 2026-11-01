<?php
// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

use App\Http\Controllers\AdminErrorController;
use App\Http\Controllers\AdminProjectController;
use App\Http\Controllers\AdminScreenshotController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\IngestController;
use App\Http\Controllers\IngestScreenshotController;
use Illuminate\Support\Facades\Route;

// Public SDK ingestion endpoints (project API key required).
Route::middleware(['glitchvy.api', 'throttle:glitchvy-ingest'])->prefix('glitchvy')->group(function () {
    Route::post('errors', [IngestController::class, 'store'])->name('glitchvy.errors.store');
    Route::post('errors/{id}/screenshot', [IngestScreenshotController::class, 'store'])
        ->whereNumber('id')
        ->name('glitchvy.screenshot.upload');
});

// Admin SPA endpoints (Sanctum auth required).
Route::middleware('auth:sanctum')->prefix('glitchvy')->group(function () {
    Route::get('errors', [AdminErrorController::class, 'index'])->name('glitchvy.errors.index');
    Route::get('errors/{id}', [AdminErrorController::class, 'show'])
        ->whereNumber('id')
        ->name('glitchvy.errors.show');
    Route::patch('errors/{id}/status', [AdminErrorController::class, 'updateStatus'])
        ->whereNumber('id')
        ->name('glitchvy.errors.status');

    Route::get('errors/{id}/screenshot', [AdminScreenshotController::class, 'show'])
        ->whereNumber('id')
        ->name('glitchvy.screenshot.show');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('projects', [AdminProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [AdminProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{id}/api-keys', [AdminProjectController::class, 'apiKeys'])
        ->whereNumber('id')
        ->name('projects.api-keys.index');
    Route::post('projects/{id}/api-keys', [AdminProjectController::class, 'createApiKey'])
        ->whereNumber('id')
        ->name('projects.api-keys.store');
});

// Public auth endpoints.
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:glitchvy-login')->name('auth.login');
Route::post('auth/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum')
    ->name('auth.logout');
Route::get('auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum')->name('auth.me');
