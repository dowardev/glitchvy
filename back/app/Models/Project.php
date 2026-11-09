<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'user_id'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Get the admin that owns the project.
     *
     * The relation is nullable: a project created before any admin
     * existed has no owner, and the owner reference is dropped (not the
     * project) when its owner is removed.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope the query to the projects the given admin is allowed to see.
     *
     * A project belongs to an admin. The projects without an owner stay
     * visible to every admin: they are the ones nobody ever assigned,
     * and hiding them would make them unreachable.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereNull('user_id');
        }

        return $query->where(function (Builder $sub) use ($user) {
            $sub->where('user_id', $user->id)
                ->orWhereNull('user_id');
        });
    }

    /**
     * Get the API keys for the project.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * Get the error reports for the project.
     */
    public function errorReports(): HasMany
    {
        return $this->hasMany(ErrorReport::class);
    }
};