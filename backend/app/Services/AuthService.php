<?php

namespace App\Services;

use App\Exceptions\DuplicateUserException;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthService
{
    public function register(array $data): User
    {
        $email = Str::lower(trim($data['email']));
        $username = $data['username'];

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw DuplicateUserException::email();
        }

        if (User::query()->where('username', $username)->exists()) {
            throw DuplicateUserException::username();
        }

        try {
            return User::create([
                'username' => $username,
                'email' => $email,
                'password' => $data['password'],
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw $this->mapUniqueViolation($e, $email, $username);
        }
    }

    /**
     * @return array{token: string, user: User}|null
     */
    public function login(array $data): ?array
    {
        $credentials = [
            'email' => Str::lower(trim($data['email'])),
            'password' => $data['password'],
        ];

        $guard = Auth::guard('api');
        $token = $guard->attempt($credentials);

        if (! $token) {
            return null;
        }

        return [
            'token' => $token,
            'user' => $guard->user(),
        ];
    }

    private function mapUniqueViolation(
        UniqueConstraintViolationException $exception,
        string $email,
        string $username,
    ): DuplicateUserException {
        $message = $exception->getMessage();

        if (str_contains($message, 'users_email_unique')) {
            return DuplicateUserException::email();
        }

        if (str_contains($message, 'users_username_unique')) {
            return DuplicateUserException::username();
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return DuplicateUserException::email();
        }

        if (User::query()->where('username', $username)->exists()) {
            return DuplicateUserException::username();
        }

        return DuplicateUserException::generic();
    }
}
