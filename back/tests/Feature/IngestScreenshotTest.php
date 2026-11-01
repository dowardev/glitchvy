<?php
// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ErrorReport;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IngestScreenshotTest extends \Tests\TestCase
{
    private function createProjectWithKey(): array
    {
        $project = Project::factory()->create();
        $plainKey = 'gv_'.Str::random(40);

        ApiKey::create([
            'project_id' => $project->id,
            'name' => 'Test Key',
            'key_hash' => Hash::make($plainKey),
            'key_prefix' => substr($plainKey, 0, 8),
        ]);

        return [$project, $plainKey];
    }

    public function test_screenshot_is_stored_on_the_configured_disk(): void
    {
        Config::set('glitchvy.storage_disk', 'screenshots-test');
        Storage::fake('screenshots-test');

        [$project, $plainKey] = $this->createProjectWithKey();
        $errorReport = ErrorReport::factory()->create(['project_id' => $project->id]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$plainKey,
        ])->post("/api/glitchvy/errors/{$errorReport->id}/screenshot", [
            'file' => UploadedFile::fake()->create('shot.png', 10, 'image/png'),
        ]);

        $response->assertStatus(201);

        $path = $response->json('path');
        Storage::disk('screenshots-test')->assertExists($path);
        $this->assertDatabaseHas('error_screenshots', [
            'error_report_id' => $errorReport->id,
            'disk' => 'screenshots-test',
            'path' => $path,
        ]);
    }

    public function test_screenshot_disk_defaults_to_the_private_disk(): void
    {
        Config::set('glitchvy.storage_disk', 'private');
        Storage::fake('private');

        [$project, $plainKey] = $this->createProjectWithKey();
        $errorReport = ErrorReport::factory()->create(['project_id' => $project->id]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$plainKey,
        ])->post("/api/glitchvy/errors/{$errorReport->id}/screenshot", [
            'file' => UploadedFile::fake()->create('shot.png', 10, 'image/png'),
        ]);

        $response->assertStatus(201);

        Storage::disk('private')->assertExists($response->json('path'));
    }
}
