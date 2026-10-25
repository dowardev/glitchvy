<?php
// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CreateAdminCommandTest extends \Tests\TestCase
{
    public function test_creates_user_with_given_email_and_password(): void
    {
        $this->artisan('glitchvy:create-admin', [
            'email' => 'owner@example.test',
            '--password' => 'plain-not-real',
            '--name' => 'Owner',
        ])->assertSuccessful();

        $user = User::where('email', 'owner@example.test')->first();
        $this->assertNotNull($user);
        $this->assertSame('Owner', $user->name);
        $this->assertTrue(Hash::check('plain-not-real', $user->password));
    }

    public function test_fails_when_email_already_exists(): void
    {
        User::create([
            'name' => 'Existing',
            'email' => 'dup@example.test',
            'password' => Hash::make('whatever'),
        ]);

        $this->artisan('glitchvy:create-admin', [
            'email' => 'dup@example.test',
            '--password' => 'whatever',
        ])->assertFailed();

        $this->assertSame(1, User::where('email', 'dup@example.test')->count());
    }

    public function test_default_name_is_admin(): void
    {
        $this->artisan('glitchvy:create-admin', [
            'email' => 'noname@example.test',
            '--password' => 'whatever',
        ])->assertSuccessful();

        $this->assertSame('Admin', User::where('email', 'noname@example.test')->first()->name);
    }
}
