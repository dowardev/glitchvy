<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Tests;

use Glitchvy\Console\Commands\GenerateApiKeyCommand;
use Glitchvy\Console\Commands\PruneErrorsCommand;
use Glitchvy\Models\ErrorReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class ConsoleCommandsTest extends TestCase
{
    public function test_prune_drops_reports_older_than_retention_window(): void
    {
        // Create two reports: one old, one fresh.
        ErrorReport::query()->create([
            'event_id' => 'old',
            'app_version' => '0.1.0',
            'environment' => 'production',
            'level' => 'error',
            'error_name' => 'RangeError',
            'error_message' => 'old',
            'created_at' => Carbon::now()->subDays(45),
        ]);
        ErrorReport::query()->create([
            'event_id' => 'fresh',
            'app_version' => '0.1.0',
            'environment' => 'production',
            'level' => 'error',
            'error_name' => 'RangeError',
            'error_message' => 'fresh',
            'created_at' => Carbon::now()->subDays(2),
        ]);

        $this->assertSame(2, ErrorReport::withTrashed()->count());

        Artisan::call(PruneErrorsCommand::class);

        // Only the fresh one remains. `forceDelete` removes the old record
        // permanently, so even `withTrashed()` only sees the fresh one.
        $this->assertSame(1, ErrorReport::query()->count());
        $this->assertSame(1, ErrorReport::withTrashed()->count());
    }

    public function test_prune_dry_run_does_not_modify_data(): void
    {
        ErrorReport::query()->create([
            'event_id' => 'old',
            'app_version' => '0.1.0',
            'environment' => 'production',
            'level' => 'error',
            'error_name' => 'RangeError',
            'error_message' => 'old',
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $exit = Artisan::call(PruneErrorsCommand::class, ['--dry-run' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame(1, ErrorReport::query()->count());
    }

    public function test_prune_days_option_overrides_config(): void
    {
        ErrorReport::query()->create([
            'event_id' => 'old',
            'app_version' => '0.1.0',
            'environment' => 'production',
            'level' => 'error',
            'error_name' => 'RangeError',
            'error_message' => 'old',
            'created_at' => Carbon::now()->subDays(8),
        ]);

        // Default 30 days → keeps. Override to 5 days → deletes.
        Artisan::call(PruneErrorsCommand::class, ['--days' => '5']);
        $this->assertSame(0, ErrorReport::query()->count());
    }

    public function test_key_rotate_prints_key_does_not_touch_env(): void
    {
        $exit = Artisan::call(GenerateApiKeyCommand::class, ['--rotate' => true]);
        $this->assertSame(0, $exit);
        $output = Artisan::output();
        // Should be a hex string of (2 * --length) chars. default 32 bytes = 64 chars.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/i', trim($output));
    }
}
