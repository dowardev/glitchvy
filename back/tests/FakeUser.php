<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Glitchvy\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Bare-bones Authenticatable user used only by the test suite.
 * Has no real auth behavior; the bundled middleware only checks the
 * presence of a row matched by remember_token.
 */
class FakeUser extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'remember_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
}
