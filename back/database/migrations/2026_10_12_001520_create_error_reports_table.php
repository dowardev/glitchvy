<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('error_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id')->nullable()->unique();
            $table->string('app_version')->nullable();
            $table->string('environment');
            $table->string('platform')->nullable();
            $table->string('route')->nullable();
            $table->text('user_agent')->nullable();
            $table->enum('level', ['debug', 'info', 'warning', 'error', 'critical'])->default('error');
            $table->string('error_name');
            $table->text('error_message');
            $table->string('error_type')->nullable();
            $table->longText('error_stack')->nullable();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('operation')->nullable();
            $table->json('tags')->nullable();
            $table->json('data')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('user_payload')->nullable();
            $table->json('breadcrumbs')->nullable();
            $table->enum('status', ['open', 'triaged', 'resolved', 'closed'])->default('open');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['environment', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('error_reports');
    }
};