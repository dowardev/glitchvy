<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace Tests;

use App\Console\Commands\CreateApiKeyCommand;
use App\Console\Commands\CreateProjectCommand;
use App\Console\Commands\PruneErrorsCommand;
use App\Http\Controllers\AdminErrorController;
use App\Http\Controllers\AdminProjectController;
use App\Http\Controllers\AdminScreenshotController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\IngestController;
use App\Http\Controllers\IngestScreenshotController;
use App\Http\Middleware\AuthenticateProjectApiKey;
use App\Providers\GlitchvyServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register commands manually
        $this->app->make('Illuminate\Contracts\Console\Kernel')->addCommands([
            PruneErrorsCommand::class,
            CreateProjectCommand::class,
            CreateApiKeyCommand::class,
        ]);

        // Configure Sanctum stateful domains for tests
        $this->app['config']->set('sanctum.stateful', ['localhost:8100', 'localhost', '127.0.0.1', '127.0.0.1:8100', '::1']);

        // Call defineRoutes manually
        $this->defineRoutes($this->app->make('router'));
    }

    protected function defineRoutes($router)
    {
        // Apply EnsureFrontendRequestsAreStateful to the api group
        $router->prefix('api')->middleware([EnsureFrontendRequestsAreStateful::class])->group(function () use ($router) {
            $router->post('glitchvy/errors', [IngestController::class, 'store'])
                ->middleware(['glitchvy.api', 'throttle:glitchvy-ingest'])
                ->name('glitchvy.errors.store');

            $router->post('glitchvy/errors/{id}/screenshot', [IngestScreenshotController::class, 'store'])
                ->middleware(['glitchvy.api', 'throttle:glitchvy-ingest'])
                ->whereNumber('id')
                ->name('glitchvy.screenshot.upload');

            $router->middleware('auth:sanctum')->prefix('glitchvy')->group(function () use ($router) {
                $router->get('errors', [AdminErrorController::class, 'index'])->name('glitchvy.errors.index');
                $router->get('errors/{id}', [AdminErrorController::class, 'show'])->whereNumber('id')->name('glitchvy.errors.show');
                $router->patch('errors/{id}/status', [AdminErrorController::class, 'updateStatus'])->whereNumber('id')->name('glitchvy.errors.status');

                $router->get('errors/{id}/screenshot', [AdminScreenshotController::class, 'show'])->whereNumber('id')->name('glitchvy.screenshot.show');
            });

            $router->middleware('auth:sanctum')->group(function () use ($router) {
                $router->get('projects', [AdminProjectController::class, 'index'])->name('projects.index');
                $router->post('projects', [AdminProjectController::class, 'store'])->name('projects.store');
                $router->post('projects/{id}/api-keys', [AdminProjectController::class, 'createApiKey'])->whereNumber('id')->name('projects.api-keys.store');
            });

            $router->post('auth/login', [AuthController::class, 'login'])->middleware('throttle:glitchvy-login')->name('auth.login');
            $router->post('auth/logout', [AuthController::class, 'logout'])
                ->middleware('auth:sanctum')
                ->name('auth.logout');
            $router->get('auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum')->name('auth.me');

            // CSRF cookie endpoint for SPA
            $router->get('glitchvy/csrf-cookie', function () {
                return response()->noContent();
            })->name('glitchvy.csrf-cookie');
        });

        // Register middleware alias
        $router->aliasMiddleware('glitchvy.api', AuthenticateProjectApiKey::class);
    }

    protected function getPackageProviders($app)
    {
        return [
            GlitchvyServiceProvider::class,
            \Laravel\Sanctum\SanctumServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('sanctum.expiration', null);
        $app['config']->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users.model', \App\Models\User::class);
    }
};