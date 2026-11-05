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
use App\Http\Middleware\ThrottleFirstPartyRequests;
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
        // Mirrors routes/api.php: the stateful stack is per route, after
        // the throttle that must run before a session is started.
        $router->prefix('api')->group(function () use ($router) {
            $router->post('glitchvy/errors', [IngestController::class, 'store'])
                ->middleware(['glitchvy.api', 'throttle:glitchvy-ingest'])
                ->name('glitchvy.errors.store');

            $router->post('glitchvy/errors/{id}/screenshot', [IngestScreenshotController::class, 'store'])
                ->middleware(['glitchvy.api', 'throttle:glitchvy-ingest'])
                ->whereNumber('id')
                ->name('glitchvy.screenshot.upload');

            $router->middleware([EnsureFrontendRequestsAreStateful::class, 'auth:sanctum'])->prefix('glitchvy')->group(function () use ($router) {
                $router->get('errors', [AdminErrorController::class, 'index'])->name('glitchvy.errors.index');
                $router->get('errors/{id}', [AdminErrorController::class, 'show'])->whereNumber('id')->name('glitchvy.errors.show');
                $router->patch('errors/{id}/status', [AdminErrorController::class, 'updateStatus'])->whereNumber('id')->name('glitchvy.errors.status');

                $router->get('errors/{id}/screenshot', [AdminScreenshotController::class, 'show'])->whereNumber('id')->name('glitchvy.screenshot.show');
            });

            $router->middleware([EnsureFrontendRequestsAreStateful::class, 'auth:sanctum'])->group(function () use ($router) {
                $router->get('projects', [AdminProjectController::class, 'index'])->name('projects.index');
                $router->post('projects', [AdminProjectController::class, 'store'])->name('projects.store');
                $router->post('projects/{id}/api-keys', [AdminProjectController::class, 'createApiKey'])->whereNumber('id')->name('projects.api-keys.store');
            });

            $router->post('auth/login', [AuthController::class, 'login'])
                ->middleware([ThrottleFirstPartyRequests::class.':login_rate_limit,ip_email', EnsureFrontendRequestsAreStateful::class])
                ->name('auth.login');
            $router->post('auth/logout', [AuthController::class, 'logout'])
                ->middleware([EnsureFrontendRequestsAreStateful::class, 'auth:sanctum'])
                ->name('auth.logout');
            $router->get('auth/me', [AuthController::class, 'me'])
                ->middleware([EnsureFrontendRequestsAreStateful::class, 'auth:sanctum'])
                ->name('auth.me');

            // CSRF cookie endpoint for SPA
            $router->get('glitchvy/csrf-cookie', function () {
                return response()->noContent();
            })->middleware([ThrottleFirstPartyRequests::class, EnsureFrontendRequestsAreStateful::class])
                ->name('glitchvy.csrf-cookie');
        });

        // Register middleware alias
        $router->aliasMiddleware('glitchvy.api', AuthenticateProjectApiKey::class);
    }

    /**
     * Find a cookie by name in a response.
     */
    protected function cookieNamed(\Illuminate\Testing\TestResponse $response, string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        return collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === $name);
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
        // Mirrors config/sanctum.php: the test environment does not load
        // the application config directory.
        $app['config']->set(
            'sanctum.middleware.validate_csrf_token',
            \App\Http\Middleware\SkipCsrfForLogout::class
        );
        $app['config']->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users.model', \App\Models\User::class);
    }
};