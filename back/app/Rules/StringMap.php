<?php
// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a value is an object of string values.
 *
 * The SDK sends the tags as a map (`{"source": "console.error"}`) and the
 * dashboard renders them as `key: value` badges. A JSON array would be
 * stored with numeric keys and rendered as `0: api`, `1: validation`,
 * which is not what anybody means by a tag.
 */
class StringMap implements ValidationRule
{
    /**
     * Determine if the validation rule passes.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The :attribute must be an object of string values.');

            return;
        }

        // An empty object carries no tags, which is fine.
        if ($value === []) {
            return;
        }

        if (array_is_list($value)) {
            $fail('The :attribute must be an object of string values, not a list.');

            return;
        }

        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_string($item)) {
                $fail('The :attribute must be an object of string values.');

                return;
            }
        }
    }
}
