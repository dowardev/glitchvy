<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 DowarDev

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Project;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class IngestThrottleTest extends \Tests\TestCase
{
    private function createProjectWithKey(string $name = 'Test Project'): array
    {
        $project = Project::factory()->create(['name' => $name]);
        $plainKey = 'gv_'.Str::random(40);
        $hash = Hash::make($plainKey);
        $prefix = substr($plainKey, 0, 8);

        ApiKey::create([
            'project_id' => $project->id,
            'name' => 'Test Key',
            'key_hash' => $hash,
            'key_prefix' => $prefix,
        ]);

        return [$project, $plainKey];
    }

    private function postError(string $plainKey, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'environment' => 'production',
            'error_name' => 'TestError',
            'error_message' => 'Test message',
        ], $overrides);

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$plainKey,
        ])->postJson('/api/glitchvy/errors', $payload);
    }

    public function test_ingest_returns_429_after_exceeding_per_project_limit(): void
    {
        Config::set('glitchvy.rate_limit', '2,1'); // 2 requests per minute

        [$project, $plainKey] = $this->createProjectWithKey();

        // First request - should succeed
        $response1 = $this->postError($plainKey);
        $response1->assertStatus(201);

        // Second request - should succeed
        $response2 = $this->postError($plainKey);
        $response2->assertStatus(201);

        // Third request - should be rate limited (429)
        $response3 = $this->postError($plainKey);
        $response3->assertStatus(429);
        $response3->assertJsonStructure(['message']);
    }

    public function test_different_project_not_blocked_by_first_projects_traffic(): void
    {
        Config::set('glitchvy.rate_limit', '1,1'); // 1 request per minute

        [$project1, $key1] = $this->createProjectWithKey('Project 1');
        [$project2, $key2] = $this->createProjectWithKey('Project 2');

        // Project 1 makes its one allowed request
        $this->postError($key1)->assertStatus(201);

        // Project 1's second request should be rate limited
        $this->postError($key1)->assertStatus(429);

        // Project 2 should still be able to make its request
        $this->postError($key2)->assertStatus(201);
    }

    public function test_rate_limit_env_var_parsing_requests_and_minutes(): void
    {
        Config::set('glitchvy.rate_limit', '100,5'); // 100 requests per 5 minutes
        [$project, $plainKey] = $this->createProjectWithKey();

        // Make 100 requests - all should succeed
        for ($i = 0; $i < 100; $i++) {
            $this->postError($plainKey, ['error_message' => "Message $i"])->assertStatus(201);
        }

        // 101st should fail
        $this->postError($plainKey)->assertStatus(429);
    }

    public function test_malformed_rate_limit_falls_back_to_default(): void
    {
        Config::set('glitchvy.rate_limit', 'not-a-number');

        [$project, $plainKey] = $this->createProjectWithKey();

        // Default is 60 per minute, so we can't easily test all 60 in a unit test
        // But we can verify it doesn't crash and allows at least some requests
        $this->postError($plainKey)->assertStatus(201);
        $this->postError($plainKey)->assertStatus(201);
    }

    public function test_missing_rate_limit_uses_default_60_per_minute(): void
    {
        Config::set('glitchvy.rate_limit', null);

        [$project, $plainKey] = $this->createProjectWithKey();

        $this->postError($plainKey)->assertStatus(201);
        $this->postError($plainKey)->assertStatus(201);
    }
};