<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The projects gain an owner. The column stays nullable because a
     * project created before any admin exists has nobody to own it, and
     * because the foreign key deletes the reference (not the project)
     * when its owner is removed.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('slug')
                ->index()
                ->constrained('users')
                ->nullOnDelete();
        });

        // The projects that already exist have no owner: the service was
        // a single-operator one and every admin saw every project.
        //
        // They belong to the operator, which is the first admin (the one
        // User::operator() returns), so no project is left unreachable
        // after the upgrade. The projects created from now on get their
        // owner from the request that creates them.
        $operatorId = DB::table('users')->orderBy('id')->value('id');

        if ($operatorId !== null) {
            DB::table('projects')->whereNull('user_id')->update(['user_id' => $operatorId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // The index goes first: SQLite refuses to drop a column that
            // an index still references.
            $table->dropIndex(['user_id']);
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
