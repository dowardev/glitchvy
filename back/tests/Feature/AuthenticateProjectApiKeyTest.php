<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Project;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthenticateProjectApiKeyTest extends \Tests\TestCase
{
    public function test_valid_api_key_sets_project_on_request(): void
    {
        $project = Project::factory()->create();
        $plainKey = 'gv_'.Str::random(40);
        $hash = Hash::make($plainKey);
        $prefix = substr($plainKey, 0, 8);

        ApiKey::create([
            'project_id' => $project->id,
            'name' => 'Test Key',
            'key_hash' => $hash,
            'key_prefix' => $prefix,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$plainKey,
        ])->postJson('/api/glitchvy/errors', [
            'environment' => 'production',
            'error_name' => 'TestError',
            'error_message' => 'Test message',
        ]);

        $response->assertStatus(201);
    }

    public function test_missing_authorization_header_returns_401(): void
    {
        $response = $this->postJson('/api/glitchvy/errors', [
            'environment' => 'production',
            'error_name' => 'TestError',
            'error_message' => 'Test message',
        ]);

        $response->assertStatus(401);
    }

    public function test_invalid_authorization_format_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'InvalidFormat key',
        ])->postJson('/api/glitchvy/errors', [
            'environment' => 'production',
            'error_name' => 'TestError',
            'error_message' => 'Test message',
        ]);

        $response->assertStatus(401);
    }

    public function test_invalid_api_key_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer gv_'.Str::random(40),
        ])->postJson('/api/glitchvy/errors', [
            'environment' => 'production',
            'error_name' => 'TestError',
            'error_message' => 'Test message',
        ]);

        $response->assertStatus(401);
    }

    public function test_revoked_api_key_returns_401(): void
    {
        $project = Project::factory()->create();
        $plainKey = 'gv_'.Str::random(40);
        $hash = Hash::make($plainKey);
        $prefix = substr($plainKey, 0, 8);

        ApiKey::create([
            'project_id' => $project->id,
            'name' => 'Revoked Key',
            'key_hash' => $hash,
            'key_prefix' => $prefix,
            'revoked_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$plainKey,
        ])->postJson('/api/glitchvy/errors', [
            'environment' => 'production',
            'error_name' => 'TestError',
            'error_message' => 'Test message',
        ]);

        $response->assertStatus(401);
    }
};