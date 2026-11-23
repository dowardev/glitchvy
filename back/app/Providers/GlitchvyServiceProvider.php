<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>
// SPDX-FileCopyrightText: 2026 DowarDev

namespace App\Providers;

use App\Console\Commands\CreateAdminCommand;
use App\Console\Commands\CreateApiKeyCommand;
use App\Console\Commands\CreateProjectCommand;
use App\Console\Commands\PruneErrorsCommand;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class GlitchvyServiceProvider extends ServiceProvider
{
    /**
     * The namespace to assume for generated URLs.
     *
     * @var string|null
     */
    protected $namespace = 'App\\Http\\Controllers';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->commands([
            PruneErrorsCommand::class,
            CreateProjectCommand::class,
            CreateApiKeyCommand::class,
            CreateAdminCommand::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Route::aliasMiddleware('glitchvy.api', \App\Http\Middleware\AuthenticateProjectApiKey::class);

        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Per-project ingestion rate limiter
        RateLimiter::for('glitchvy-ingest', function (Request $request) {
            $project = $request->attributes->get('project');

            if (! $project) {
                return Limit::none();
            }

            $config = config('glitchvy.rate_limit', '60,1');
            [$maxAttempts, $decayMinutes] = $this->parseRateLimit($config);

            return Limit::perMinutes($decayMinutes, $maxAttempts)
                ->by($project->id);
        });
    }

    /**
     * Parse rate limit string "requests,minutes" into [maxAttempts, decayMinutes].
     *
     * @return array{maxAttempts: int, decayMinutes: int}
     */
    private function parseRateLimit(?string $config): array
    {
        if (! $config) {
            return [60, 1];
        }

        $parts = explode(',', $config);

        if (count($parts) !== 2) {
            return [60, 1];
        }

        $maxAttempts = (int) $parts[0];
        $decayMinutes = (int) $parts[1];

        if ($maxAttempts <= 0 || $decayMinutes <= 0) {
            return [60, 1];
        }

        return [$maxAttempts, $decayMinutes];
    }
};
