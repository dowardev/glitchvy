<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy;

use Glitchvy\Console\Commands\GenerateApiKeyCommand;
use Glitchvy\Console\Commands\PruneErrorsCommand;
use Glitchvy\Http\Middleware\AuthenticateApiKey;
use Glitchvy\Http\Middleware\AuthenticateUserToken;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class GlitchvyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/glitchvy.php',
            'glitchvy',
        );
    }

    public function boot(): void
    {
        $this->bootMigrations();
        $this->bootRoutes();
        $this->bootMiddleware();
        $this->bootCommands();
    }

    protected function bootMigrations(): void
    {
        $migrationsPath = __DIR__.'/../database/migrations';
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }

    protected function bootRoutes(): void
    {
        $routesPath = __DIR__.'/../routes/api.php';
        if (! is_file($routesPath)) {
            return;
        }
        Route::group([
            'prefix' => (string) config('glitchvy.routes.prefix', 'api/glitchvy'),
            'middleware' => ['api'],
        ], function () use ($routesPath): void {
            $this->loadRoutesFrom($routesPath);
        });
    }

    protected function bootMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('glitchvy.api', AuthenticateApiKey::class);
        $router->aliasMiddleware('glitchvy.user', AuthenticateUserToken::class);
    }

    protected function bootCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneErrorsCommand::class,
                GenerateApiKeyCommand::class,
            ]);
        }
    }
}
