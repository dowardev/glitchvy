<?php
// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminCommand extends Command
{
    protected $signature = 'glitchvy:create-admin
        {email : Admin email address}
        {--password= : Admin password (prompted if not given)}
        {--name=Admin : Display name}';

    protected $description = 'Create a Glitchvy admin user (for the SPA /api/auth/login)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = (string) ($this->option('password') ?? $this->secret('Password'));

        if (User::where('email', $email)->exists()) {
            $this->error("User with email {$email} already exists.");

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $this->option('name'),
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("Admin user created: {$user->email} (id={$user->id})");

        return self::SUCCESS;
    }
}
