<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 DowarDev

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `api_keys.key_prefix` unique.
 *
 * The prefix is the first 8 characters of the plaintext key and is the
 * only thing `ApiKey::findByPlainKey()` can look up before verifying
 * the hash, so two keys sharing a prefix is a real (if unlikely) state:
 * the lookup would have to consider both. Making the column unique
 * turns that into an impossible state instead of a runtime branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('api_keys')
            ->select('key_prefix', DB::raw('COUNT(*) as total'))
            ->groupBy('key_prefix')
            ->having('total', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $list = $duplicates->pluck('key_prefix')->implode(', ');

            throw new RuntimeException(
                "Cannot add a unique index on api_keys.key_prefix: duplicate prefixes found ({$list}). "
                .'Revoke or rotate those keys before running this migration.'
            );
        }

        Schema::table('api_keys', function (Blueprint $table) {
            $table->unique('key_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropUnique(['key_prefix']);
        });
    }
};
