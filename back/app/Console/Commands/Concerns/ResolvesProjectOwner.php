<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Console\Commands\Concerns;

use App\Models\User;

trait ResolvesProjectOwner
{
    /**
     * Resolve the admin the command acts for.
     *
     * The `--user` option picks the admin by email. Without it, the
     * command acts for the operator: the first admin, the same one the
     * migration that adds the ownership column assigns the projects that
     * already exist to.
     *
     * Returns false when the requested admin does not exist, and null
     * when there is no admin at all.
     */
    protected function resolveOwner(): User|false|null
    {
        $email = $this->option('user');

        if ($email) {
            $user = User::where('email', $email)->first();

            if (! $user) {
                $this->error("User not found: {$email}");

                return false;
            }

            return $user;
        }

        return User::operator();
    }
}
