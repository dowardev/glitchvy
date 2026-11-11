<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 DowarDev

namespace Tests\Feature;

use App\Models\ErrorReport;
use App\Models\ErrorScreenshot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

class ErrorReportOwnershipTest extends \Tests\TestCase
{
    public function test_index_only_returns_the_error_reports_of_the_projects_of_the_authenticated_admin(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($admin);

        $mine = Project::factory()->create(['user_id' => $admin->id]);
        $theirs = Project::factory()->create(['user_id' => $other->id]);

        $myReport = ErrorReport::factory()->create(['project_id' => $mine->id]);
        $theirReport = ErrorReport::factory()->create(['project_id' => $theirs->id]);

        $response = $this->getJson('/api/glitchvy/errors');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($myReport->id, $response->json('data.0.id'));
        $this->assertNull(
            collect($response->json('data'))->firstWhere('id', $theirReport->id),
            'The listing leaked an error report of a project of another admin.'
        );
    }

    public function test_index_returns_the_error_reports_of_the_ownerless_projects_to_every_admin(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($admin);

        $unowned = Project::factory()->create(['user_id' => null]);
        $theirs = Project::factory()->create(['user_id' => $other->id]);

        $unownedReport = ErrorReport::factory()->create(['project_id' => $unowned->id]);
        ErrorReport::factory()->create(['project_id' => $theirs->id]);

        $response = $this->getJson('/api/glitchvy/errors');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($unownedReport->id, $response->json('data.0.id'));
    }

    public function test_index_keeps_the_filters_of_the_owned_error_reports(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($admin);

        $mine = Project::factory()->create(['user_id' => $admin->id]);
        $theirs = Project::factory()->create(['user_id' => $other->id]);

        ErrorReport::factory()->create([
            'project_id' => $mine->id,
            'environment' => 'production',
            'status' => 'resolved',
        ]);
        ErrorReport::factory()->create([
            'project_id' => $theirs->id,
            'environment' => 'production',
            'status' => 'resolved',
        ]);

        $response = $this->getJson('/api/glitchvy/errors?environment=production&status=resolved');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('production', $response->json('data.0.environment'));
    }

    public function test_show_of_an_error_report_of_another_admin_is_not_found(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($admin);

        $project = Project::factory()->create(['user_id' => $other->id]);
        $report = ErrorReport::factory()->create(['project_id' => $project->id]);

        $this->getJson("/api/glitchvy/errors/{$report->id}")->assertStatus(404);
    }

    public function test_show_of_an_owned_error_report_is_returned(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $project = Project::factory()->create(['user_id' => $admin->id]);
        $report = ErrorReport::factory()->create(['project_id' => $project->id]);

        $response = $this->getJson("/api/glitchvy/errors/{$report->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $report->id]);
    }

    public function test_update_status_of_an_error_report_of_another_admin_is_not_found(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($admin);

        $project = Project::factory()->create(['user_id' => $other->id]);
        $report = ErrorReport::factory()->create(['project_id' => $project->id, 'status' => 'open']);

        $response = $this->patchJson("/api/glitchvy/errors/{$report->id}/status", [
            'status' => 'resolved',
        ]);

        $response->assertStatus(404);
        $this->assertSame('open', $report->fresh()->status);
    }

    public function test_update_status_of_an_owned_error_report_is_applied(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $project = Project::factory()->create(['user_id' => $admin->id]);
        $report = ErrorReport::factory()->create(['project_id' => $project->id, 'status' => 'open']);

        $response = $this->patchJson("/api/glitchvy/errors/{$report->id}/status", [
            'status' => 'resolved',
        ]);

        $response->assertStatus(200);
        $this->assertSame('resolved', $report->fresh()->status);
    }

    public function test_screenshot_of_an_error_report_of_another_admin_is_not_found(): void
    {
        Storage::fake('private');

        $admin = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($admin);

        $project = Project::factory()->create(['user_id' => $other->id]);
        $report = ErrorReport::factory()->create(['project_id' => $project->id]);
        $screenshot = ErrorScreenshot::factory()->create(['error_report_id' => $report->id]);
        Storage::disk($screenshot->disk)->put($screenshot->path, 'fake-image-bytes');

        $this->getJson("/api/glitchvy/errors/{$report->id}/screenshot")->assertStatus(404);
    }

    public function test_screenshot_of_an_owned_error_report_is_served(): void
    {
        Storage::fake('private');

        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $project = Project::factory()->create(['user_id' => $admin->id]);
        $report = ErrorReport::factory()->create(['project_id' => $project->id]);
        $screenshot = ErrorScreenshot::factory()->create(['error_report_id' => $report->id]);
        Storage::disk($screenshot->disk)->put($screenshot->path, 'fake-image-bytes');

        $response = $this->get("/api/glitchvy/errors/{$report->id}/screenshot");

        $response->assertStatus(200);
        $this->assertSame('fake-image-bytes', $response->streamedContent());
    }
}
