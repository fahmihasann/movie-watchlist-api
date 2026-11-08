<?php

namespace Tests\Feature\Watchlist;

use App\Models\User;
use App\Models\Watchlist;
use Database\Seeders\MovieSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WatchlistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MovieSeeder::class);
    }

    private function createUser(string $suffix = 'owner'): User
    {
        return User::factory()->create([
            'username' => "watchlist_{$suffix}",
            'email' => "watchlist_{$suffix}@example.com",
            'password' => 'password123',
        ]);
    }

    private function loginToken(User $user): string
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return $token;
    }

    private function withBearer(string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function addToWatchlist(User $user, int $movieId): Watchlist
    {
        return Watchlist::query()->create([
            'user_id' => $user->id,
            'movie_id' => $movieId,
        ]);
    }

    public function test_watchlist_endpoints_require_authentication(): void
    {
        $this->get('/api/watchlist')
            ->assertStatus(401)
            ->assertHeader('content-type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->postJson('/api/watchlist', ['movieId' => 1])->assertStatus(401);
        $this->deleteJson('/api/watchlist/1')->assertStatus(401);
    }

    public function test_it_adds_a_movie_to_the_watchlist(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);

        $response = $this->withBearer($token)
            ->postJson('/api/watchlist', ['movieId' => 1]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Added to watchlist.')
            ->assertJsonPath('data.id', 1)
            ->assertJsonPath('data.title', 'Inception')
            ->assertJsonMissingPath('data.cast_members');

        $this->assertIsArray($response->json('data.cast'));
        $this->assertDatabaseHas('watchlists', [
            'user_id' => $user->id,
            'movie_id' => 1,
        ]);
    }

    public function test_adding_the_same_movie_twice_returns_409(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);
        $this->addToWatchlist($user, 1);

        $this->withBearer($token)
            ->postJson('/api/watchlist', ['movieId' => 1])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Movie already in watchlist.']);

        $this->assertDatabaseCount('watchlists', 1);
    }

    public function test_adding_a_movie_that_does_not_exist_returns_404(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);

        $this->withBearer($token)
            ->postJson('/api/watchlist', ['movieId' => 999999])
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Movie not found.']);

        $this->assertDatabaseCount('watchlists', 0);
    }

    public function test_movie_id_validation_returns_400(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);

        $this->withBearer($token)
            ->postJson('/api/watchlist', [])
            ->assertStatus(400)
            ->assertJsonStructure(['message', 'errors' => ['movieId']]);

        foreach (['abc', 1.5, 0, -1] as $movieId) {
            $this->withBearer($token)
                ->postJson('/api/watchlist', ['movieId' => $movieId])
                ->assertStatus(400)
                ->assertJsonStructure(['message', 'errors' => ['movieId']]);
        }

        $this->assertDatabaseCount('watchlists', 0);
    }

    public function test_it_only_returns_the_authenticated_users_movies_newest_first(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other');

        $this->addToWatchlist($owner, 1);
        $this->travel(1)->minutes();
        $this->addToWatchlist($owner, 2);
        $this->addToWatchlist($other, 3);

        $token = $this->loginToken($owner);

        $response = $this->withBearer($token)->getJson('/api/watchlist')->assertOk();

        $this->assertSame([2, 1], collect($response->json())->pluck('id')->all());
        $this->assertIsArray($response->json('0.cast'));
        $response->assertJsonMissingPath('0.cast_members');
    }

    public function test_it_removes_a_movie_from_the_watchlist(): void
    {
        $user = $this->createUser();
        $this->addToWatchlist($user, 1);
        $token = $this->loginToken($user);

        $this->withBearer($token)
            ->deleteJson('/api/watchlist/1')
            ->assertOk()
            ->assertExactJson(['message' => 'Removed from watchlist.']);

        $this->assertDatabaseMissing('watchlists', [
            'user_id' => $user->id,
            'movie_id' => 1,
        ]);
    }

    public function test_removing_a_movie_that_is_not_in_the_watchlist_returns_404(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);

        $this->withBearer($token)
            ->deleteJson('/api/watchlist/1')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Movie not in watchlist.']);
    }

    public function test_a_user_cannot_remove_another_users_watchlist_entry(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other');
        $this->addToWatchlist($other, 1);

        $token = $this->loginToken($owner);

        $this->withBearer($token)
            ->deleteJson('/api/watchlist/1')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Movie not in watchlist.']);

        $this->assertDatabaseHas('watchlists', [
            'user_id' => $other->id,
            'movie_id' => 1,
        ]);
    }

    public function test_a_non_numeric_movie_id_returns_a_json_404_without_an_accept_header(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);

        $this->withBearer($token)
            ->delete('/api/watchlist/not-a-number')
            ->assertStatus(404)
            ->assertHeader('content-type', 'application/json')
            ->assertExactJson(['message' => 'Not found.']);
    }

    public function test_the_watchlist_query_count_does_not_grow_with_the_number_of_entries(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);
        $this->addToWatchlist($user, 1);

        $single = $this->countWatchlistQueries($token);

        foreach ([2, 3, 4, 5] as $movieId) {
            $this->addToWatchlist($user, $movieId);
        }

        $this->assertSame($single, $this->countWatchlistQueries($token));
    }

    private function countWatchlistQueries(string $token): int
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/watchlist')
            ->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
