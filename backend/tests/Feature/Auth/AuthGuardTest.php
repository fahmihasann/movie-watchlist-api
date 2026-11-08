<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:api')->get(
            '/api/test-protected',
            fn () => response()->json(['ok' => true])
        );
    }

    private function createUser(): User
    {
        return User::factory()->create([
            'username' => 'guard_user',
            'email' => 'guard@example.com',
            'password' => 'password123',
        ]);
    }

    private function loginToken(User $user): string
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk()->json('token');

        // Simulate a fresh HTTP request: clear the guard's cached user and token.
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return $token;
    }

    public function test_protected_route_without_token_returns_json_401_without_accept_header(): void
    {
        $this->get('/api/test-protected')
            ->assertStatus(401)
            ->assertHeader('content-type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_protected_route_without_token_returns_json_401_with_accept_header(): void
    {
        $this->getJson('/api/test-protected')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_protected_route_with_random_token_returns_401(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-valid-token')
            ->getJson('/api/test-protected')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_protected_route_with_malformed_token_returns_401(): void
    {
        $this->withHeader('Authorization', 'Bearer abc.def.ghi')
            ->getJson('/api/test-protected')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_protected_route_with_token_from_real_login_returns_200(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/test-protected')
            ->assertOk()
            ->assertExactJson(['ok' => true]);
    }

    public function test_expired_token_returns_401(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);

        $this->travel(61)->minutes();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/test-protected')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_token_for_deleted_user_returns_401(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);

        $user->delete();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/test-protected')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }
}
