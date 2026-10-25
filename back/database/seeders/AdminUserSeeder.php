<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $password = Str::random(16);

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@glitchvy.app',
            'password' => Hash::make($password),
        ]);

        $this->command?->info("Admin user created: {$user->email}");
        $this->command?->info("Password: {$password}");
    }
};