<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 DowarDev

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Align the `error_reports.status` enum with the admin SPA's
 * workflow: open → in_progress → resolved, with the option to
 * mark an error as ignored (vs. just closing it).
 *
 * We don't drop the original values (triaged, closed) so existing
 * rows that used them are preserved; they just stop being valid
 * from the API surface. If a deployment needs to migrate those
 * rows, do it explicitly with a follow-up UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL syntax — alter the enum in place. SQLite ignores
        // this for non-strict tables, so the dev environment still
        // works without it.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE error_reports MODIFY COLUMN status ENUM('open', 'in_progress', 'resolved', 'ignored', 'triaged', 'closed') NOT NULL DEFAULT 'open'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE error_reports MODIFY COLUMN status ENUM('open', 'triaged', 'resolved', 'closed') NOT NULL DEFAULT 'open'");
        }
    }
};
