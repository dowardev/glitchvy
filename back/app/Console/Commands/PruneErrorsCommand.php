<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>
// SPDX-FileCopyrightText: 2026 DowarDev

namespace App\Console\Commands;

use App\Models\ErrorReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

class PruneErrorsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'glitchvy:prune {--days=} {--dry-run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune error reports older than the specified number of days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = $this->option('days');
        $dryRun = $this->option('dry-run');

        // Use config default if --days not provided
        if ($days === null) {
            $days = Config::get('glitchvy.retention_days', 30);
        }
        $days = (int) $days;

        $cutoff = Carbon::now()->subDays($days);

        // Two kinds of rows are past the retention: the ones that were
        // soft deleted before the cutoff, and the live ones created
        // before it. Nothing soft deletes an error report, so a prune
        // that only looked at the trashed rows would never delete
        // anything.
        $query = ErrorReport::withTrashed()
            ->where(function ($query) use ($cutoff) {
                $query->where('created_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff) {
                        $query->whereNotNull('deleted_at')
                            ->where('deleted_at', '<', $cutoff);
                    });
            });

        $count = $query->count();

        if ($count === 0) {
            $this->info('No error reports to prune.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("Dry run: would force delete {$count} error report(s) older than {$days} days.");

            return Command::SUCCESS;
        }

        $deleted = $query->forceDelete();

        $this->info("Force deleted {$deleted} error report(s) older than {$days} days.");

        return Command::SUCCESS;
    }
};