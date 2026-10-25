<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Models;

use Database\Factories\ErrorScreenshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['error_report_id', 'disk', 'path', 'mime_type', 'size_bytes'])]
class ErrorScreenshot extends Model
{
    /** @use HasFactory<ErrorScreenshotFactory> */
    use HasFactory;

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    /**
     * Get the error report that owns the screenshot.
     */
    public function errorReport(): BelongsTo
    {
        return $this->belongsTo(ErrorReport::class);
    }
};