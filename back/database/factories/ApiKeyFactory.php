<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    public function definition(): array
    {
        $plainKey = 'gv_'.Str::random(40);

        return [
            'project_id' => Project::factory(),
            'name' => $this->faker->word(),
            'key_hash' => Hash::make($plainKey),
            'key_prefix' => substr($plainKey, 0, 8),
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }
};