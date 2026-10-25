<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Models;

use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

#[Fillable(['project_id', 'name', 'key_hash', 'key_prefix', 'last_used_at', 'revoked_at'])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * Get the project that owns the API key.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Find an API key by its plain text value.
     *
     * The lookup is done by prefix (the first 8 characters) and then
     * verified against the stored hash. Because the prefix is only 8
     * characters wide, more than one key can share the same prefix, so
     * every candidate has to be checked — stopping at the first row
     * would make the second key unauthenticatable.
     *
     * @param  string  $plain
     * @return self|null
     */
    public static function findByPlainKey(string $plain): ?self
    {
        $prefix = substr($plain, 0, 8);

        foreach (self::where('key_prefix', $prefix)->get() as $key) {
            if (Hash::check($plain, $key->key_hash)) {
                return $key;
            }
        }

        return null;
    }
};