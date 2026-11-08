<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace Tests\Feature;

use App\Models\ErrorReport;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;

class AdminErrorControllerTest extends \Tests\TestCase
{
    use WithFaker;

    public function test_index_returns_paginated_errors(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $project = Project::factory()->create();
        ErrorReport::factory()->count(5)->create(['project_id' => $project->id]);

        $response = $this->getJson('/api/glitchvy/errors');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'next_cursor',
            'prev_cursor',
        ]);
        $this->assertCount(5, $response->json('data'));
    }

    public function test_index_filters_by_environment(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $project = Project::factory()->create();
        ErrorReport::factory()->create(['project_id' => $project->id, 'environment' => 'production']);
        ErrorReport::factory()->create(['project_id' => $project->id, 'environment' => 'staging']);

        $response = $this->getJson('/api/glitchvy/errors?environment=production');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('production', $response->json('data.0.environment'));
    }

    public function test_index_filters_by_status(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $project = Project::factory()->create();
        ErrorReport::factory()->create(['project_id' => $project->id, 'status' => 'open']);
        ErrorReport::factory()->create(['project_id' => $project->id, 'status' => 'resolved']);

        $response = $this->getJson('/api/glitchvy/errors?status=resolved');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('resolved', $response->json('data.0.status'));
    }

    public function test_show_returns_error_report(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $project = Project::factory()->create();
        $errorReport = ErrorReport::factory()->create(['project_id' => $project->id]);

        $response = $this->getJson("/api/glitchvy/errors/{$errorReport->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $errorReport->id]);
    }

    public function test_index_honours_the_per_page_parameter(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $project = Project::factory()->create();
        ErrorReport::factory()->count(10)->create(['project_id' => $project->id]);

        $response = $this->getJson('/api/glitchvy/errors?per_page=3');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_index_accepts_limit_as_an_alias_of_per_page(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $project = Project::factory()->create();
        ErrorReport::factory()->count(10)->create(['project_id' => $project->id]);

        $response = $this->getJson('/api/glitchvy/errors?limit=4');

        $response->assertStatus(200);
        $this->assertCount(4, $response->json('data'));
    }

    public function test_update_status_changes_status(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $project = Project::factory()->create();
        $errorReport = ErrorReport::factory()->create(['project_id' => $project->id, 'status' => 'open']);

        $response = $this->patchJson("/api/glitchvy/errors/{$errorReport->id}/status", [
            'status' => 'resolved',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'resolved']);

        $this->assertDatabaseHas('error_reports', [
            'id' => $errorReport->id,
            'status' => 'resolved',
        ]);
    }
};