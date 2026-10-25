<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 DowarDev

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Drop the legacy `triaged` and `closed` statuses.
 *
 * The admin SPA's workflow is open → in_progress → resolved, with
 * `ignored` for "we are not going to fix this". The pre-SPA design
 * used Sentry-style `triaged` / `closed`, and the column still accepted
 * them, so rows could exist with a status that the API rejects and
 * `ErrorReport::isValidStatus()` refuses.
 *
 * MySQL: narrow the enum in place.
 * SQLite: the CHECK clause is baked in at CREATE TABLE time and cannot
 * be altered, so the table has to be rebuilt (same technique as the
 * earlier `fix_status_check_sqlite` migration).
 */
return new class extends Migration
{
    /** The four statuses the admin SPA actually uses. */
    private const NEW_STATUSES = ['open', 'in_progress', 'resolved', 'ignored'];

    /** The statuses the column accepted before this migration. */
    private const LEGACY_STATUSES = ['open', 'triaged', 'resolved', 'closed'];

    /**
     * Give every row without an event_id one.
     *
     * The column becomes NOT NULL, and the unique index that backs the
     * dedup guarantee does not constrain NULLs, so a row that still has
     * one would either break the ALTER or sit outside the index. The
     * ids are minted in PHP so they are real UUIDs in the same shape
     * the ingestion endpoint produces.
     */
    private function backfillMissingEventIds(): void
    {
        $rows = DB::table('error_reports')
            ->whereNull('event_id')
            ->select('id')
            ->get();

        foreach ($rows as $row) {
            DB::table('error_reports')
                ->where('id', $row->id)
                ->update(['event_id' => (string) Str::uuid()]);
        }
    }

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Map the legacy statuses onto the new vocabulary BEFORE
            // narrowing the enum. MySQL refuses to shrink an enum while
            // rows still hold a value that would be dropped, and it
            // reports that as a hard "Data truncated" error.
            DB::statement("UPDATE error_reports SET status = 'resolved' WHERE status IN ('triaged', 'closed')");

            $this->backfillMissingEventIds();

            DB::statement("ALTER TABLE error_reports MODIFY COLUMN status ENUM('open','in_progress','resolved','ignored') NOT NULL DEFAULT 'open'");
            // A unique index does not constrain NULLs, so a NULL event_id
            // would defeat the dedup guarantee. Every ingest now mints
            // an id server-side, so the column can be NOT NULL.
            DB::statement('ALTER TABLE error_reports MODIFY COLUMN event_id CHAR(36) NOT NULL');

            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        // The table is rebuilt below with a NOT NULL event_id, so any
        // row still missing one has to be given an id first.
        $this->backfillMissingEventIds();

        DB::statement('PRAGMA foreign_keys=OFF');

        $newTable = 'error_reports_new_status';

        DB::statement(<<<SQL
CREATE TABLE {$newTable} (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    event_id TEXT NOT NULL,
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
        CHECK (status IN ('open','in_progress','resolved','ignored')),
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
)
SQL);

        // Legacy rows keep their meaning as best we can: `triaged` and
        // `closed` both meant "handled", which in the new workflow is
        // `resolved`. Anything that was already `open` stays `open`.
        DB::statement(<<<SQL
INSERT INTO {$newTable}
SELECT
    id, project_id, event_id, app_version, environment, platform, route,
    user_agent, level, error_name, error_message, error_type, error_stack,
    entity_type, entity_id, operation, tags, data, user_id, user_payload,
    breadcrumbs,
    CASE
        WHEN status IN ('triaged', 'closed') THEN 'resolved'
        WHEN status IN ('open', 'in_progress', 'resolved', 'ignored') THEN status
        ELSE 'open'
    END,
    created_at, updated_at, deleted_at
FROM error_reports
SQL);

        DB::statement('DROP TABLE error_reports');
        DB::statement("ALTER TABLE {$newTable} RENAME TO error_reports");
        DB::statement('CREATE INDEX error_reports_environment_created_at_index ON error_reports (environment, created_at)');
        DB::statement('CREATE INDEX error_reports_status_created_at_index ON error_reports (status, created_at)');
        DB::statement('CREATE UNIQUE INDEX error_reports_event_id_unique ON error_reports (event_id)');

        DB::statement('PRAGMA foreign_keys=ON');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Order matters: MySQL refuses to UPDATE a row to a value
            // that is not in the column's current enum, and refuses to
            // shrink an enum while rows still hold a value it would
            // drop. So the enum is widened first, the rows are mapped,
            // and only then is it narrowed.
            DB::statement("ALTER TABLE error_reports MODIFY COLUMN status ENUM('open','in_progress','resolved','ignored','triaged','closed') NOT NULL DEFAULT 'open'");
            DB::statement("UPDATE error_reports SET status = 'triaged' WHERE status = 'in_progress'");
            DB::statement("UPDATE error_reports SET status = 'closed' WHERE status = 'ignored'");
            DB::statement("ALTER TABLE error_reports MODIFY COLUMN status ENUM('open','triaged','resolved','closed') NOT NULL DEFAULT 'open'");
            DB::statement('ALTER TABLE error_reports MODIFY COLUMN event_id CHAR(36) NULL');

            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('PRAGMA foreign_keys=OFF');

        $newTable = 'error_reports_old_status';

        DB::statement(<<<SQL
CREATE TABLE {$newTable} (
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
        CHECK (status IN ('open','triaged','resolved','closed')),
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
)
SQL);

        // `in_progress` and `ignored` did not exist in the old enum;
        // collapse them onto the closest legacy value.
        DB::statement(<<<SQL
INSERT INTO {$newTable}
SELECT
    id, project_id, event_id, app_version, environment, platform, route,
    user_agent, level, error_name, error_message, error_type, error_stack,
    entity_type, entity_id, operation, tags, data, user_id, user_payload,
    breadcrumbs,
    CASE
        WHEN status IN ('open', 'triaged', 'resolved', 'closed') THEN status
        ELSE 'open'
    END,
    created_at, updated_at, deleted_at
FROM error_reports
SQL);

        DB::statement('DROP TABLE error_reports');
        DB::statement("ALTER TABLE {$newTable} RENAME TO error_reports");
        DB::statement('CREATE INDEX error_reports_environment_created_at_index ON error_reports (environment, created_at)');
        DB::statement('CREATE INDEX error_reports_status_created_at_index ON error_reports (status, created_at)');
        DB::statement('CREATE UNIQUE INDEX error_reports_event_id_unique ON error_reports (event_id)');

        DB::statement('PRAGMA foreign_keys=ON');
    }
};
