<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 DowarDev

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;

class LoginThrottleTest extends \Tests\TestCase
{
    private function createUser(string $email = 'admin@glitchvy.app', string $password = 'password123'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make($password),
        ]);
    }

    private function postLogin(string $email, string $password, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', array_merge([
            'email' => $email,
            'password' => $password,
        ], $overrides));
    }

    public function test_login_returns_429_after_exceeding_limit(): void
    {
        Config::set('glitchvy.login_rate_limit', '3,1'); // 3 attempts per minute

        $user = $this->createUser();

        // 3 failed attempts - should succeed (return 422 for wrong password)
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postLogin($user->email, 'wrong-password');
            $response->assertStatus(422);
        }

        // 4th attempt - should be rate limited (429)
        $response = $this->postLogin($user->email, 'wrong-password');
        $response->assertStatus(429);
        $response->assertJsonStructure(['message']);
    }

    public function test_login_throttle_is_per_ip_and_email(): void
    {
        Config::set('glitchvy.login_rate_limit', '1,1'); // 1 attempt per minute

        $user1 = $this->createUser('user1@example.com');
        $user2 = $this->createUser('user2@example.com');

        // User 1 makes one failed attempt
        $this->postLogin($user1->email, 'wrong')->assertStatus(422);

        // User 1's second attempt should be rate limited
        $this->postLogin($user1->email, 'wrong')->assertStatus(429);

        // User 2 should still be able to attempt
        $this->postLogin($user2->email, 'wrong')->assertStatus(422);
    }

    public function test_login_throttle_default_when_config_missing(): void
    {
        // Don't set the config, let it use the default from config file
        // (the RefreshDatabase trait creates a fresh app per test)

        $user = $this->createUser();

        // Default should allow at least some attempts (5 per minute)
        for ($i = 0; $i < 5; $i++) {
            $this->postLogin($user->email, 'wrong')->assertStatus(422);
        }
        // 6th should be rate limited (default 5 per minute)
        $this->postLogin($user->email, 'wrong')->assertStatus(429);
    }
};