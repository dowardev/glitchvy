<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ErrorReport;
use App\Models\Project;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class IngestControllerTest extends \Tests\TestCase
{
    public function test_valid_payload_creates_error_report(): void
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

        $payload = [
            'event_id' => Str::uuid()->toString(),
            'app_version' => '1.0.0',
            'environment' => 'production',
            'platform' => 'web',
            'route' => '/api/users',
            'user_agent' => 'Mozilla/5.0',
            'level' => 'error',
            'error_name' => 'ValidationException',
            'error_message' => 'The email field is required.',
            'error_type' => 'ValidationError',
            'error_stack' => 'Stack trace here...',
            'entity_type' => 'user',
            'entity_id' => 123,
            'operation' => 'create',
            'tags' => ['api', 'validation'],
            'data' => ['field' => 'email'],
            'user_id' => 456,
            'user_payload' => ['id' => 456, 'email' => 'test@example.com'],
            'breadcrumbs' => [
                ['type' => 'navigation', 'data' => ['to' => '/users']],
            ],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$plainKey,
        ])->postJson('/api/glitchvy/errors', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'event_id']);

        $this->assertDatabaseHas('error_reports', [
            'project_id' => $project->id,
            'error_name' => 'ValidationException',
            'environment' => 'production',
        ]);
    }

    public function test_invalid_payload_returns_422(): void
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
            'environment' => '',
            'error_name' => '',
            'error_message' => '',
        ]);

        $response->assertStatus(422);
    }
};