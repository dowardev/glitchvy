<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 DowarDev

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SQLite doesn't support ALTER TABLE for CHECK constraints, and
 * `$table->enum(...)` bakes the allowed values into a CHECK clause
 * the moment the table is created. The previous migration only
 * updated the MySQL enum, so the dev (SQLite) database still
 * rejects in_progress / ignored updates.
 *
 * Workaround: rebuild the table. We don't touch the column types,
 * just the CHECK on `status`. The screenshot / api_keys / projects
 * tables reference project_id but are connected to error_reports
 * via screenshots.error_report_id, which we'll preserve.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return; // MySQL side already covered by the previous migration
        }

        DB::statement('PRAGMA foreign_keys=OFF');

        // New table with the updated status CHECK. Note we keep the
        // foreign key declarations off here because the screenshots
        // table has an FK to error_reports; rebuilding it would need
        // to be coordinated. The downstream tables' FKs will be
        // re-enabled by the final PRAGMA.
        DB::statement(<<<'SQL'
CREATE TABLE error_reports_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    event_id TEXT NULL,
    app_version VARCHAR(255) NULL,
    environment VARCHAR(255) NOT NULL,
    platform VARCHAR(255) NULL,
    route VARCHAR(255) NULL,
    user_agent TEXT NULL,
    level VARCHAR(255) NOT NULL DEFAULT 'error'
        CHECK (level IN ('debug','info','warning','error','critical')),
    error_name VARCHAR(255) NOT NULL,
    error_message TEXT NOT NULL,
    error_type VARCHAR(255) NULL,
    error_stack LONGTEXT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id INTEGER NULL,
    operation VARCHAR(100) NULL,
    tags TEXT NULL,
    data TEXT NULL,
    user_id INTEGER NULL,
    user_payload TEXT NULL,
    breadcrumbs TEXT NULL,
    status VARCHAR(255) NOT NULL DEFAULT 'open'
        CHECK (status IN ('open','in_progress','resolved','ignored','triaged','closed')),
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
)
SQL);

        DB::statement('INSERT INTO error_reports_new SELECT * FROM error_reports');
        DB::statement('DROP TABLE error_reports');
        DB::statement('ALTER TABLE error_reports_new RENAME TO error_reports');
        DB::statement('CREATE INDEX error_reports_environment_created_at_index ON error_reports (environment, created_at)');
        DB::statement('CREATE INDEX error_reports_status_created_at_index ON error_reports (status, created_at)');
        DB::statement('CREATE UNIQUE INDEX error_reports_event_id_unique ON error_reports (event_id)');

        DB::statement('PRAGMA foreign_keys=ON');
    }

    public function down(): void
    {
        // No rollback: rebuilding back would lose any rows created
        // in the new status values. The previous MySQL migration
        // remains the source of truth for the enum semantics.
    }
};
