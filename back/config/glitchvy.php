<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 DowarDev

return [
    /*
    |--------------------------------------------------------------------------
    | Rate Limit for Ingestion Endpoints
    |--------------------------------------------------------------------------
    |
    | Format: "requests,minutes" (e.g. "60,1" = 60 requests per minute)
    | Applied per-project via the API key middleware.
    |
    */
    'rate_limit' => env('GLITCHVY_RATE_LIMIT', '60,1'),

    /*
    |--------------------------------------------------------------------------
    | Rate Limit for Admin Login
    |--------------------------------------------------------------------------
    |
    | Format: "requests,minutes" (e.g. "5,1" = 5 attempts per minute)
    | Applied per IP + email combination.
    |
    */
    'login_rate_limit' => env('GLITCHVY_LOGIN_RATE_LIMIT', '5,1'),

    /*
    |--------------------------------------------------------------------------
    | Rate Limit for the CSRF Cookie Endpoint
    |--------------------------------------------------------------------------
    |
    | Format: "requests,minutes" (e.g. "60,1" = 60 requests per minute)
    | Applied per IP. The endpoint starts a session for a first-party
    | request, so it is bounded to keep the sessions table in check.
    |
    */
    'csrf_rate_limit' => env('GLITCHVY_CSRF_RATE_LIMIT', '60,1'),

    /*
    |--------------------------------------------------------------------------
    | Retention Days for Soft-Deleted Errors
    |--------------------------------------------------------------------------
    |
    | Number of days before soft-deleted error reports are eligible for
    | permanent deletion by the `glitchvy:prune` command.
    |
    */
    'retention_days' => (int) env('GLITCHVY_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Storage Disk for Screenshots
    |--------------------------------------------------------------------------
    |
    | Filesystem disk used for storing uploaded error screenshots.
    | Falls back to GLITCHVY_SCREENSHOT_DISK for backward compatibility.
    |
    */
    'storage_disk' => env('GLITCHVY_STORAGE_DISK', env('GLITCHVY_SCREENSHOT_DISK', 'private')),
];