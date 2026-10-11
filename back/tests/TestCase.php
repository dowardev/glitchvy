<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Tests;

use Glitchvy\GlitchvyServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base TestCase for the Laravel SDK test suite.
 *
 * - Uses Orchestra Testbench to bootstrap a minimal Laravel app in memory.
 * - Wires our service provider and a SQLite in-memory database.
 * - Runs the bundled migrations once per test (cheap with sqlite memory).
 * - Provides a tiny `User` model on the in-memory `users` table so the
 *   `AuthenticateUserToken` middleware can resolve tokens.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Register and run the package's bundled migrations on the in-memory DB.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--database' => 'testing']);

        // Then bring up the `users` table the bundled middleware looks up against.
        $this->bootTestSchema();
    }

    protected function getPackageProviders($app): array
    {
        return [GlitchvyServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $config->set('glitchvy.api_key', 'gvy_test_api_key_for_unit_tests_only_change_me');
        $config->set('glitchvy.admin_guard', 'web');
        $config->set('glitchvy.rate_limit.per_minute', 5);
        $config->set('glitchvy.routes.prefix', 'api/glitchvy');
        $config->set('glitchvy.retention_days', 30);
        $config->set('glitchvy.storage_disk', 'local');

        // Drive the auth guard at a custom provider; ensure a User class exists.
        $config->set('auth.providers.users.model', FakeUser::class);

        // Force storage disk to a local in-memory-friendly driver.
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/glitchvy-test-storage',
            'throw' => false,
        ]);
    }

    protected function bootTestSchema(): void
    {
        // Bring up only the auxiliary tables we need for tests.
        Schema::dropIfExists('users');
        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Helper for tests: register a fake user with a known remember token.
     */
    protected function makeFakeUser(string $token = 'user-token-abc'): FakeUser
    {
        $user = new FakeUser();
        $user->name = 'Tester';
        $user->email = 'tester@example.com';
        $user->remember_token = $token;
        $user->save();
        return $user;
    }
}
