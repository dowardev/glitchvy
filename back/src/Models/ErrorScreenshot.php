<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErrorScreenshot extends Model
{
    use HasFactory;

    protected $table = 'error_screenshots';

    protected $guarded = ['id'];

    public function errorReport(): BelongsTo
    {
        return $this->belongsTo(ErrorReport::class);
    }

    /**
     * Absolute path within the configured disk. Consumers should reach this
     * file only via {@see \Glitchvy\Http\Controllers\ScreenshotController}.
     */
    public function diskPath(): string
    {
        return $this->path;
    }
}
