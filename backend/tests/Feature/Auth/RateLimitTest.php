<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class RateLimitTest extends TestCase
{
    public function test_login_returns_json_429_with_retry_after_when_throttled(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/auth/login', [])->assertStatus(400);
        }

        $this->postJson('/api/auth/login', [])
            ->assertStatus(429)
            ->assertExactJson(['message' => 'Too many requests. Please try again later.'])
            ->assertHeader('Retry-After');
    }
}
