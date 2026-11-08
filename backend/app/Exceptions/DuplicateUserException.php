<?php

namespace App\Exceptions;

use Exception;

class DuplicateUserException extends Exception
{
    private function __construct(public readonly ?string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function email(): self
    {
        return new self('email', 'Email already registered.');
    }

    public static function username(): self
    {
        return new self('username', 'Username already taken.');
    }

    public static function generic(): self
    {
        return new self(null, 'Account already exists.');
    }
}
