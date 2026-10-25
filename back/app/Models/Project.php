<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

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