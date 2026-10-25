<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

class CreateApiKeyCommandTest extends \Tests\TestCase
{
    public function test_create_api_key_command(): void
    {
        $project = Project::factory()->create(['name' => 'Test Project', 'slug' => 'test-project']);

        // Verify project exists before command
        $this->assertDatabaseHas('projects', ['slug' => 'test-project']);

        // Run command via Artisan facade
        $exitCode = Artisan::call('glitchvy:create-api-key', [
            '--project' => 'test-project',
            '--name' => 'Test API Key',
        ]);

        $this->assertEquals(0, $exitCode);

        // Debug: check if api_keys table has any rows
        $count = ApiKey::count();
        $this->assertGreaterThan(0, $count, "api_keys table has {$count} rows");

        $this->assertDatabaseHas('api_keys', [
            'project_id' => $project->id,
            'name' => 'Test API Key',
        ]);
    }

    public function test_create_api_key_for_nonexistent_project_fails(): void
    {
        $exitCode = Artisan::call('glitchvy:create-api-key', [
            '--project' => 'nonexistent',
            '--name' => 'Test API Key',
        ]);

        $this->assertEquals(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Project not found', $output);
    }
};