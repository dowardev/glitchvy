<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('error_reports', function (Blueprint $table): void {
            $table->id();

            // The event id assigned by the SDK (UUID v4). Unique so flushQueue
            // re-queues can use it as a natural upsert key for future migrations.
            $table->string('event_id', 64)->nullable()->unique();

            // The consumer app's version (from InitConfig.appVersion).
            $table->string('app_version', 64)->index();

            // Free-form environment label (production / staging / ...).
            $table->string('environment', 32)->index();

            // Detection metadata
            $table->string('platform', 32)->default('web')->index();
            $table->string('route', 1024)->nullable();
            $table->text('user_agent')->nullable();

            // Error envelope
            $table->string('level', 16)->index(); // error | warn | info
            $table->string('error_name', 128);
            $table->text('error_message');
            $table->text('error_type')->nullable();
            $table->longText('error_stack')->nullable();

            // Context (entityType/entityId/operation/tags/data)
            $table->string('entity_type', 128)->nullable()->index();
            $table->string('entity_id', 128)->nullable()->index();
            $table->string('operation', 64)->nullable();
            $table->json('tags')->nullable();
            $table->json('data')->nullable();

            // User attribution
            $table->string('user_id', 128)->nullable()->index();
            $table->json('user_payload')->nullable();

            // Breadcrumbs (recent activity trail)
            $table->json('breadcrumbs')->nullable();

            // Workflow state: open -> triaged -> resolved -> closed
            $table->string('status', 32)->default('open')->index();

            $table->timestamps();
            $table->softDeletes();

            // Common index for the admin list view (most recent first).
            $table->index(['environment', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_reports');
    }
};
