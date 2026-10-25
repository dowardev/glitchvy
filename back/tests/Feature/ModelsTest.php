<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace Tests\Feature;

use App\Models\ErrorReport;
use App\Models\Project;

class ModelsTest extends \Tests\TestCase
{
    public function test_error_report_statuses_constant(): void
    {
        $this->assertEquals(['open', 'in_progress', 'resolved', 'ignored'], ErrorReport::STATUSES);
    }

    public function test_is_valid_status(): void
    {
        $this->assertTrue(ErrorReport::isValidStatus('open'));
        $this->assertTrue(ErrorReport::isValidStatus('in_progress'));
        $this->assertTrue(ErrorReport::isValidStatus('resolved'));
        $this->assertTrue(ErrorReport::isValidStatus('ignored'));
        $this->assertFalse(ErrorReport::isValidStatus('invalid'));
        $this->assertFalse(ErrorReport::isValidStatus(''));
    }

    public function test_error_report_dispatches_event_on_created(): void
    {
        $project = Project::factory()->create();

        $dispatchesEvents = (new \ReflectionClass(ErrorReport::class))
            ->getProperty('dispatchesEvents')
            ->getValue(new ErrorReport());

        $this->assertEquals(
            ['created' => \App\Events\ErrorReported::class],
            $dispatchesEvents
        );
    }

    public function test_project_has_relations(): void
    {
        $project = Project::factory()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $project->apiKeys());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $project->errorReports());
    }

    public function test_error_report_has_relations(): void
    {
        $project = Project::factory()->create();
        $errorReport = ErrorReport::factory()->create(['project_id' => $project->id]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $errorReport->project());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $errorReport->screenshots());
    }
};