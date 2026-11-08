<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $overrides
     */
    private function createUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'username' => 'login_user',
            'email' => 'login@example.com',
            'password' => 'password123',
        ], $overrides));
    }

    public function test_login_returns_token_and_user_without_password(): void
    {
        $user = $this->createUser();

        $response = $this->postJson('/api/auth/login', [
            'email' => '  LOGIN@Example.com  ',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'username', 'email']])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.username', 'login_user')
            ->assertJsonPath('user.email', 'login@example.com')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');

        $this->assertIsString($response->json('token'));
        $this->assertNotSame('', $response->json('token'));
    }

    public function test_login_rejects_wrong_password_with_401(): void
    {
        $this->createUser();

        $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401)
            ->assertExactJson(['message' => 'Invalid email or password.']);
    }

    public function test_login_rejects_unknown_email_with_the_same_message_as_wrong_password(): void
    {
        $this->createUser();

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ]);

        $unknownEmail = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        $wrongPassword->assertStatus(401);
        $unknownEmail->assertStatus(401);

        $this->assertSame('Invalid email or password.', $wrongPassword->json('message'));
        $this->assertSame($wrongPassword->json('message'), $unknownEmail->json('message'));
    }

    public function test_login_with_a_three_character_wrong_password_returns_401_not_400(): void
    {
        $this->createUser();

        $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'abc',
        ])->assertStatus(401)
            ->assertExactJson(['message' => 'Invalid email or password.']);
    }

    public function test_login_rejects_missing_fields(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertStatus(400)
            ->assertJsonStructure([
                'message',
                'errors' => ['email', 'password'],
            ]);
    }
}
