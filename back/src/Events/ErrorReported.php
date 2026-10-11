<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Events;

use Glitchvy\Models\ErrorReport;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after a new {@see ErrorReport} is persisted.
 *
 * Consumers can wire listeners for: alerting, Slack/email notifications,
 * derived aggregates, etc. We intentionally do NOT bundle any listener
 * implementations — alerting is out of scope for v0.x per the package spec.
 *
 * Example:
 *
 *     \App\Listeners\OnGlitchvyError::class
 *
 *     public function handle(ErrorReported $event): void
 *     {
 *         $report = $event->errorReport;
 *         // ... notify, alert, dedupe ...
 *     }
 */
class ErrorReported
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ErrorReport $errorReport,
    ) {
    }
}
