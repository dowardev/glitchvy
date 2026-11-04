<?php
// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Support;

use App\Http\Middleware\SkipCsrfForLogout;

/**
 * CSRF middleware that does not skip validation while the test suite runs.
 *
 * The framework's own middleware ignores the token mismatch when the
 * application runs in a unit test, so a test can never prove that a
 * mutating request without the token is refused. This subclass keeps the
 * production behaviour (including the routes the application exempts),
 * which is what the browser session relies on.
 */
class StrictValidateCsrfToken extends SkipCsrfForLogout
{
    /**
     * Do not skip the token validation while testing.
     */
    protected function runningUnitTests()
    {
        return false;
    }
}
