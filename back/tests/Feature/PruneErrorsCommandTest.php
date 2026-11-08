<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>
// SPDX-FileCopyrightText: 2026 DowarDev

namespace Tests\Feature;

use App\Models\ErrorReport;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;

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

    public function test_prune_deletes_live_reports_past_the_retention(): void
    {
        Config::set('glitchvy.retention_days', 15);

        $project = Project::factory()->create();

        // Nothing soft deletes an error report, so a prune that only
        // looked at the trashed rows would never delete anything.
        $old = ErrorReport::factory()->create([
            'project_id' => $project->id,
            'created_at' => Carbon::now()->subDays(20),
        ]);
        $recent = ErrorReport::factory()->create([
            'project_id' => $project->id,
            'created_at' => Carbon::now()->subDays(2),
        ]);

        $exitCode = Artisan::call('glitchvy:prune');

        $this->assertEquals(0, $exitCode);
        $this->assertDatabaseMissing('error_reports', ['id' => $old->id]);
        $this->assertDatabaseHas('error_reports', ['id' => $recent->id]);
    }

    public function test_prune_command_uses_configured_retention_days_as_default(): void
    {
        Config::set('glitchvy.retention_days', 15);

        $project = Project::factory()->create();

        // Error older than 15 days (should be pruned with default)
        $oldError = ErrorReport::factory()->create([
            'project_id' => $project->id,
            'deleted_at' => Carbon::now()->subDays(20),
        ]);
        // Error newer than 15 days (should NOT be pruned with default)
        ErrorReport::factory()->create([
            'project_id' => $project->id,
            'deleted_at' => Carbon::now()->subDays(10),
        ]);

        // Call without --days option, should use config default (15)
        $exitCode = Artisan::call('glitchvy:prune');

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Force deleted 1 error report(s)', $output);

        $this->assertDatabaseMissing('error_reports', ['id' => $oldError->id]);
    }

    public function test_prune_command_days_option_overrides_config(): void
    {
        Config::set('glitchvy.retention_days', 15);

        $project = Project::factory()->create();

        // Error older than 15 days but newer than 30 days
        $error = ErrorReport::factory()->create([
            'project_id' => $project->id,
            'deleted_at' => Carbon::now()->subDays(20),
        ]);

        // Call with --days=30, should NOT prune (error is only 20 days old)
        $exitCode = Artisan::call('glitchvy:prune', ['--days' => 30]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('No error reports to prune', $output);

        $this->assertSoftDeleted('error_reports', ['id' => $error->id]);
    }

    public function test_scheduler_has_prune_command_registered(): void
    {
        // Load console routes to register scheduled commands
        $router = $this->app->make('router');
        require_once __DIR__.'/../../routes/console.php';

        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $events = $schedule->events();
        $pruneCommands = array_filter($events, function ($event) {
            return str_contains($event->command, 'glitchvy:prune');
        });

        $this->assertNotEmpty($pruneCommands, 'glitchvy:prune should be scheduled');
        $pruneEvent = array_values($pruneCommands)[0];
        $this->assertTrue($pruneEvent->expression === '0 0 * * *' || 
            (method_exists($pruneEvent, 'daily') && $pruneEvent->daily),
            'Prune command should be scheduled daily');
    }

    private function getFirstId(): int
    {
        return ErrorReport::onlyTrashed()->where('deleted_at', '<', Carbon::now()->subDays(30))->value('id') ?? 0;
    }
};