<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace Database\Factories;

use App\Models\ErrorReport;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ErrorReportFactory extends Factory
{
    protected $model = ErrorReport::class;

    public function definition(): array
    {
        $tagOptions = ['api', 'frontend', 'backend', 'database'];
        $dataOptions = ['key' => $this->faker->word()];
        $breadcrumbOptions = ['type' => 'navigation', 'data' => []];

        return [
            'project_id' => Project::factory(),
            'event_id' => Str::uuid(),
            'app_version' => $this->faker->optional()->semver(),
            'environment' => $this->faker->randomElement(['production', 'staging', 'development']),
            'platform' => $this->faker->optional()->randomElement(['web', 'ios', 'android', 'desktop']),
            'route' => $this->faker->optional()->url(),
            'user_agent' => $this->faker->optional()->userAgent(),
            'level' => $this->faker->randomElement(['debug', 'info', 'warning', 'error', 'critical']),
            'error_name' => $this->faker->word().'Exception',
            'error_message' => $this->faker->sentence(),
            'error_type' => $this->faker->optional()->word().'Error',
            'error_stack' => $this->faker->optional()->text(1000),
            'entity_type' => $this->faker->optional()->randomElement(['user', 'order', 'product']),
            'entity_id' => $this->faker->optional()->numberBetween(1, 1000),
            'operation' => $this->faker->optional()->randomElement(['create', 'read', 'update', 'delete']),
            'tags' => $this->faker->optional()->randomElements($tagOptions, min(2, count($tagOptions))),
            'data' => $this->faker->optional()->randomElements($dataOptions, 1),
            'user_id' => $this->faker->optional()->numberBetween(1, 100),
            'user_payload' => $this->faker->optional()->randomElements(['id' => $this->faker->numberBetween(1, 100)], 1),
            'breadcrumbs' => $this->faker->optional()->randomElements($breadcrumbOptions, 1),
            'status' => 'open',
        ];
    }
};