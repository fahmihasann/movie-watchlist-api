<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StrictInteger implements ValidationRule
{
    /**
     * Accept real integers and canonical integer strings that fit in a PHP int.
     *
     * Laravel's integer rule accepts booleans (true becomes 1), which lets bad
     * JSON payloads through. This rule also rejects floats, arrays, null bytes,
     * and numeric strings above PHP_INT_MAX so they never reach the database.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_int($value)) {
            return;
        }

        if (is_string($value)
            && preg_match('/^-?\d+$/D', $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return;
        }

        $fail("The {$attribute} field must be an integer.");
    }
}
