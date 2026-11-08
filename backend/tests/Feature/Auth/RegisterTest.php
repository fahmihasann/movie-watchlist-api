<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'username' => 'jane_doe',
            'email' => 'jane.doe@example.com',
            'password' => 'secret-password',
        ], $overrides);
    }

    public function test_register_returns_201_with_user_and_no_token(): void
    {
        $response = $this->postJson('/api/auth/register', $this->payload([
            'email' => '  Jane.Doe@Example.com  ',
        ]));

        $response->assertCreated()
            ->assertJsonStructure(['user' => ['id', 'username', 'email']])
            ->assertJsonPath('user.username', 'jane_doe')
            ->assertJsonPath('user.email', 'jane.doe@example.com')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');

        $this->assertArrayNotHasKey('token', $response->json());

        $user = User::query()->where('email', 'jane.doe@example.com')->firstOrFail();

        $this->assertSame('jane_doe', $user->username);
        $this->assertNotSame('secret-password', $user->password);
        $this->assertTrue(Hash::check('secret-password', $user->password));
    }

    public function test_register_rejects_duplicate_email_case_insensitively(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $response = $this->postJson('/api/auth/register', $this->payload([
            'username' => 'other_user',
            'email' => 'JANE.DOE@EXAMPLE.COM',
        ]));

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Email already registered.')
            ->assertJsonPath('errors.email.0', 'Email already registered.');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_register_rejects_duplicate_username(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $response = $this->postJson('/api/auth/register', $this->payload([
            'email' => 'other@example.com',
        ]));

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Username already taken.')
            ->assertJsonPath('errors.username.0', 'Username already taken.');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_register_rejects_invalid_email(): void
    {
        $response = $this->postJson('/api/auth/register', $this->payload([
            'email' => 'not-an-email',
        ]));

        $response->assertStatus(400)
            ->assertJsonPath('message', 'The email field must be a valid email address.')
            ->assertJsonPath('errors.email.0', 'The email field must be a valid email address.');
    }

    public function test_register_rejects_short_password(): void
    {
        $response = $this->postJson('/api/auth/register', $this->payload([
            'password' => 'short',
        ]));

        $response->assertStatus(400)
            ->assertJsonPath('errors.password.0', 'The password field must be at least 8 characters.');
    }

    public function test_register_rejects_missing_fields(): void
    {
        $response = $this->postJson('/api/auth/register', []);

        $response->assertStatus(400)
            ->assertJsonStructure([
                'message',
                'errors' => ['username', 'email', 'password'],
            ]);
    }

    public function test_register_accepts_a_72_ascii_character_password(): void
    {
        $this->postJson('/api/auth/register', $this->payload([
            'password' => str_repeat('a', 72),
        ]))->assertCreated();
    }

    public function test_register_rejects_a_73_ascii_character_password(): void
    {
        $response = $this->postJson('/api/auth/register', $this->payload([
            'password' => str_repeat('a', 73),
        ]));

        $response->assertStatus(400)
            ->assertJsonPath('errors.password.0', 'The password may not be greater than 72 bytes.');
    }

    public function test_register_rejects_a_multibyte_password_over_72_bytes(): void
    {
        $password = str_repeat('映', 40);

        $this->assertGreaterThan(72, strlen($password));
        $this->assertLessThanOrEqual(72, mb_strlen($password));

        $response = $this->postJson('/api/auth/register', $this->payload([
            'password' => $password,
        ]));

        $response->assertStatus(400)
            ->assertJsonPath('errors.password.0', 'The password may not be greater than 72 bytes.');
    }
}
