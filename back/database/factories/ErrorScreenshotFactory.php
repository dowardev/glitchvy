<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace Database\Factories;

use App\Models\ErrorReport;
use App\Models\ErrorScreenshot;
use Illuminate\Database\Eloquent\Factories\Factory;

class ErrorScreenshotFactory extends Factory
{
    protected $model = ErrorScreenshot::class;

    public function definition(): array
    {
        return [
            'error_report_id' => ErrorReport::factory(),
            'disk' => 'private',
            'path' => 'screenshots/'.$this->faker->uuid().'.png',
            'mime_type' => 'image/png',
            'size_bytes' => $this->faker->numberBetween(1000, 1000000),
        ];
    }
};