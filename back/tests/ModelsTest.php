<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Tests;

use Glitchvy\Events\ErrorReported;
use Glitchvy\Models\ErrorReport;
use Illuminate\Support\Facades\Event;

class ModelsTest extends TestCase
{
    public function test_statuses_constant_lists_all_valid_states(): void
    {
        $this->assertSame(['open', 'triaged', 'resolved', 'closed'], ErrorReport::STATUSES);
    }

    public function test_isValidStatus_distinguishes_known_and_unknown(): void
    {
        $this->assertTrue(ErrorReport::isValidStatus('open'));
        $this->assertTrue(ErrorReport::isValidStatus('triaged'));
        $this->assertTrue(ErrorReport::isValidStatus('resolved'));
        $this->assertTrue(ErrorReport::isValidStatus('closed'));
        $this->assertFalse(ErrorReport::isValidStatus('PENDING'));
        $this->assertFalse(ErrorReport::isValidStatus(''));
        $this->assertFalse(ErrorReport::isValidStatus('weirdstate'));
    }

    public function test_creating_a_report_dispatches_ErrorReported_event(): void
    {
        Event::fake([ErrorReported::class]);

        $report = ErrorReport::query()->create([
            'event_id' => 'evt-create',
            'app_version' => '0.1.0',
            'environment' => 'production',
            'level' => 'error',
            'error_name' => 'RangeError',
            'error_message' => 'hello',
        ]);

        Event::assertDispatched(ErrorReported::class, function (ErrorReported $event) use ($report): bool {
            return $event->errorReport->id === $report->id;
        });
    }
}
