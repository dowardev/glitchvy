<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Support\Facades\Artisan;

class CreateProjectCommandTest extends \Tests\TestCase
{
    public function test_create_project_command(): void
    {
        $exitCode = Artisan::call('glitchvy:create-project', [
            '--name' => 'Test Project',
            '--slug' => 'test-project',
        ]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Project created: Test Project (test-project)', $output);

        $this->assertDatabaseHas('projects', [
            'name' => 'Test Project',
            'slug' => 'test-project',
        ]);
    }

    public function test_create_project_with_auto_slug(): void
    {
        $exitCode = Artisan::call('glitchvy:create-project', [
            '--name' => 'My Awesome Project',
        ]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Project created: My Awesome Project', $output);

        $this->assertDatabaseHas('projects', [
            'name' => 'My Awesome Project',
            'slug' => 'my-awesome-project',
        ]);
    }

    public function test_create_duplicate_slug_fails(): void
    {
        Project::factory()->create(['slug' => 'existing-project']);

        $exitCode = Artisan::call('glitchvy:create-project', [
            '--name' => 'Another Project',
            '--slug' => 'existing-project',
        ]);

        $this->assertEquals(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString("Project with slug 'existing-project' already exists", $output);
    }
};