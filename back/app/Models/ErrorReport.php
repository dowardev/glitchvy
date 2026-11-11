<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Models;

use App\Events\ErrorReported;
use Database\Factories\ErrorReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'project_id',
    'event_id',
    'app_version',
    'environment',
    'platform',
    'route',
    'user_agent',
    'level',
    'error_name',
    'error_message',
    'error_type',
    'error_stack',
    'entity_type',
    'entity_id',
    'operation',
    'tags',
    'data',
    'user_id',
    'user_payload',
    'breadcrumbs',
    'status',
])]
class ErrorReport extends Model
{
    /** @use HasFactory<ErrorReportFactory> */
    use HasFactory, SoftDeletes;

    public const STATUSES = ['open', 'in_progress', 'resolved', 'ignored'];

    /**
     * Check whether a given string is one of the valid error statuses.
     * Used by the admin SPA status segment control and the
     * updateStatus endpoint to validate incoming status values.
     */
    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    protected $casts = [
        'event_id' => 'string',
        'entity_id' => 'integer',
        'user_id' => 'integer',
        'tags' => 'array',
        'data' => 'array',
        'user_payload' => 'array',
        'breadcrumbs' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dispatchesEvents = [
        'created' => ErrorReported::class,
    ];

    /**
     * Scope the query to the error reports the given admin is allowed to see.
     *
     * A report belongs to a project, and a project belongs to an admin.
     * The visibility of the reports is therefore the visibility of their
     * projects: the same relation the admin project endpoints use.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $query->whereHas(
            'project',
            fn (Builder $sub) => $sub->visibleTo($user)
        );
    }

    /**
     * Get the project that owns the error report.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the screenshots for the error report.
     */
    public function screenshots(): HasMany
    {
        return $this->hasMany(ErrorScreenshot::class);
    }
};