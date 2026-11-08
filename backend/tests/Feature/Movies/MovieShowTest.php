<?php

namespace Tests\Feature\Movies;

use Database\Seeders\MovieSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MovieShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MovieSeeder::class);
    }

    public function test_it_returns_a_single_movie(): void
    {
        $response = $this->getJson('/api/movies/1');

        $response->assertOk()
            ->assertJsonPath('id', 1)
            ->assertJsonPath('title', 'Inception')
            ->assertJsonPath('cast.0', 'Leonardo DiCaprio')
            ->assertJsonMissingPath('cast_members');

        $this->assertIsArray($response->json('genres'));
        $this->assertIsFloat($response->json('rating'));
    }

    public function test_a_missing_movie_returns_a_json_404_without_an_accept_header(): void
    {
        $this->get('/api/movies/999999')
            ->assertStatus(404)
            ->assertHeader('content-type', 'application/json')
            ->assertExactJson(['message' => 'Not found.']);
    }

    public function test_a_non_numeric_movie_id_returns_a_json_404(): void
    {
        $this->get('/api/movies/not-a-number')
            ->assertStatus(404)
            ->assertHeader('content-type', 'application/json')
            ->assertExactJson(['message' => 'Not found.']);
    }
}
