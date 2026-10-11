<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Console\Commands;

use Glitchvy\Models\ErrorReport;
use Illuminate\Console\Command;

class PruneErrorsCommand extends Command
{
    protected $signature = 'glitchvy:prune
        {--days= : Override the configured retention window (in days)}
        {--dry-run : Show what would be deleted without touching data}';

    protected $description = 'Delete glitchvy error reports older than the retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('glitchvy.retention_days', 30));
        if ($days <= 0) {
            $this->error('Days must be a positive integer.');
            return self::INVALID;
        }

        $threshold = now()->subDays($days);

        // forceDelete() skips the SoftDeletes trait — old error reports are
        // removed permanently (and any attached screenshots whose foreign keys
        // cascade on the DB side).
        $query = ErrorReport::query()
            ->where('created_at', '<', $threshold);

        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->line("Would delete {$count} error reports older than {$threshold->toIso8601String()} ({$days} days).");
            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info("No error reports older than {$days} days to delete.");
            return self::SUCCESS;
        }

        $deleted = $query->forceDelete();
        $this->info("Deleted {$deleted} error reports older than {$days} days.");

        return self::SUCCESS;
    }
}
