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
        Schema::create('error_screenshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('error_report_id')
                ->constrained('error_reports')
                ->cascadeOnDelete();

            // Storage metadata
            $table->string('disk', 64);
            $table->string('path', 1024);
            $table->string('mime_type', 64);
            $table->unsignedInteger('size_bytes')->default(0);

            $table->timestamps();

            $table->index('error_report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_screenshots');
    }
};
