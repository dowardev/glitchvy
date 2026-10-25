<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace Tests\Feature;

use App\Models\ErrorReport;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Artisan;

class PruneErrorsCommandTest extends \Tests\TestCase
{
    use WithFaker;

    public function test_prune_errors_command_dry_run(): void
    {
        $project = Project::factory()->create();

        ErrorReport::factory()->create([
            'project_id' => $project->id,
            'deleted_at' => Carbon::now()->subDays(40),
        ]);
        ErrorReport::factory()->create([
            'project_id' => $project->id,
            'deleted_at' => Carbon::now()->subDays(10),
        ]);

        $exitCode = Artisan::call('glitchvy:prune', [
            '--days' => 30,
            '--dry-run' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('would force delete 1 error report(s)', $output);

        $this->assertSoftDeleted('error_reports', ['id' => $this->getFirstId()]);
    }

    public function test_prune_errors_command_actually_deletes(): void
    {
        $project = Project::factory()->create();

        $oldError = ErrorReport::factory()->create([
            'project_id' => $project->id,
            'deleted_at' => Carbon::now()->subDays(40),
        ]);
        ErrorReport::factory()->create([
            'project_id' => $project->id,
            'deleted_at' => Carbon::now()->subDays(10),
        ]);

        $exitCode = Artisan::call('glitchvy:prune', [
            '--days' => 30,
        ]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Force deleted 1 error report(s)', $output);

        $this->assertDatabaseMissing('error_reports', ['id' => $oldError->id]);
    }

    public function test_prune_errors_no_errors_to_prune(): void
    {
        $project = Project::factory()->create();

        ErrorReport::factory()->create([
            'project_id' => $project->id,
            'deleted_at' => Carbon::now()->subDays(10),
        ]);

        $exitCode = Artisan::call('glitchvy:prune', [
            '--days' => 30,
        ]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('No error reports to prune', $output);
    }

    private function getFirstId(): int
    {
        return ErrorReport::onlyTrashed()->where('deleted_at', '<', Carbon::now()->subDays(30))->value('id') ?? 0;
    }
};