<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Providers;

use App\Console\Commands\CreateAdminCommand;
use App\Console\Commands\CreateApiKeyCommand;
use App\Console\Commands\CreateProjectCommand;
use App\Console\Commands\PruneErrorsCommand;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

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
    }
};