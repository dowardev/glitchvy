<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Models;

use Glitchvy\Events\ErrorReported;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ErrorReport extends Model
{
    use SoftDeletes;
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_TRIAGED = 'triaged';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_TRIAGED,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
    ];

    protected $table = 'error_reports';

    protected $guarded = ['id'];

    protected $casts = [
        'tags' => 'array',
        'data' => 'array',
        'user_payload' => 'array',
        'breadcrumbs' => 'array',
    ];

    protected $dispatchesEvents = [
        'created' => ErrorReported::class,
    ];

    public function screenshots(): HasMany
    {
        return $this->hasMany(ErrorScreenshot::class);
    }

    /**
     * Convenience accessor for the admin SPA. Reports a flat ISO-8601 string.
     */
    protected function createdAtIso(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->created_at?->toIso8601String(),
        );
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }
}
