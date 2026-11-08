<?php

namespace Tests\Feature\Watched;

use App\Models\Review;
use App\Models\User;
use App\Models\Watchlist;
use Database\Seeders\MovieSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WatchedTest extends TestCase
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
            'username' => "watched_{$suffix}",
            'email' => "watched_{$suffix}@example.com",
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

    private function addReview(User $user, int $movieId, int $rating = 4, string $review = 'Old review.'): Review
    {
        return Review::query()->create([
            'user_id' => $user->id,
            'movie_id' => $movieId,
            'rating' => $rating,
            'review' => $review,
        ]);
    }

    private function addToWatchlist(User $user, int $movieId): void
    {
        Watchlist::query()->create([
            'user_id' => $user->id,
            'movie_id' => $movieId,
        ]);
    }

    public function test_watched_endpoints_require_authentication(): void
    {
        $this->get('/api/watched')
            ->assertStatus(401)
            ->assertHeader('content-type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->postJson('/api/watched', [
            'movieId' => 1,
            'rating' => 5,
            'review' => 'Great.',
        ])->assertStatus(401);
    }

    public function test_it_records_a_watched_movie_with_the_movie_in_the_response(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);

        $response = $this->withBearer($token)->postJson('/api/watched', [
            'movieId' => 1,
            'rating' => 5,
            'review' => 'A mind-bending thriller.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Added to watched list.')
            ->assertJsonPath('data.movie_id', 1)
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.review', 'A mind-bending thriller.')
            ->assertJsonPath('data.movie.id', 1)
            ->assertJsonPath('data.movie.title', 'Inception')
            ->assertJsonMissingPath('data.movie.cast_members');

        $this->assertIsArray($response->json('data.movie.cast'));
        $this->assertIsString($response->json('data.created_at'));

        $this->assertDatabaseHas('reviews', [
            'user_id' => $user->id,
            'movie_id' => 1,
            'rating' => 5,
            'review' => 'A mind-bending thriller.',
        ]);
    }

    public function test_rating_must_be_an_integer_between_one_and_five(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);

        $this->withBearer($token)
            ->postJson('/api/watched', [
                'movieId' => 1,
                'review' => 'Missing rating.',
            ])
            ->assertStatus(400)
            ->assertJsonStructure(['message', 'errors' => ['rating']]);

        foreach ([0, 6, 4.5, 'abc'] as $rating) {
            $this->withBearer($token)
                ->postJson('/api/watched', [
                    'movieId' => 1,
                    'rating' => $rating,
                    'review' => 'Invalid rating.',
                ])
                ->assertStatus(400)
                ->assertJsonStructure(['message', 'errors' => ['rating']]);
        }

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_review_is_required_and_limited_to_2000_characters(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);

        $this->withBearer($token)
            ->postJson('/api/watched', ['movieId' => 1, 'rating' => 5])
            ->assertStatus(400)
            ->assertJsonStructure(['message', 'errors' => ['review']]);

        $this->withBearer($token)
            ->postJson('/api/watched', [
                'movieId' => 1,
                'rating' => 5,
                'review' => str_repeat('a', 2001),
            ])
            ->assertStatus(400)
            ->assertJsonStructure(['message', 'errors' => ['review']]);

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_recording_a_movie_that_does_not_exist_returns_404(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);

        $this->withBearer($token)
            ->postJson('/api/watched', [
                'movieId' => 999999,
                'rating' => 5,
                'review' => 'Does not exist.',
            ])
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Movie not found.']);

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_a_duplicate_review_returns_409_and_keeps_the_old_review(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);
        $this->addReview($user, 1, 3, 'Old review.');
        $this->addToWatchlist($user, 1);

        $this->withBearer($token)
            ->postJson('/api/watched', [
                'movieId' => 1,
                'rating' => 5,
                'review' => 'New review.',
            ])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Movie already reviewed.']);

        $this->assertDatabaseHas('reviews', [
            'user_id' => $user->id,
            'movie_id' => 1,
            'rating' => 3,
            'review' => 'Old review.',
        ]);
        $this->assertDatabaseHas('watchlists', [
            'user_id' => $user->id,
            'movie_id' => 1,
        ]);
    }

    public function test_recording_a_watched_movie_removes_it_from_the_watchlist(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);
        $this->addToWatchlist($user, 1);

        $this->withBearer($token)
            ->postJson('/api/watched', [
                'movieId' => 1,
                'rating' => 5,
                'review' => 'Watched it.',
            ])
            ->assertCreated();

        $this->assertDatabaseMissing('watchlists', [
            'user_id' => $user->id,
            'movie_id' => 1,
        ]);
    }

    public function test_a_failed_request_leaves_the_watchlist_untouched(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);
        $this->addToWatchlist($user, 1);

        $this->withBearer($token)
            ->postJson('/api/watched', [
                'movieId' => 999999,
                'rating' => 5,
                'review' => 'Missing movie.',
            ])
            ->assertStatus(404);

        $this->withBearer($token)
            ->postJson('/api/watched', [
                'movieId' => 1,
                'rating' => 6,
                'review' => 'Invalid rating.',
            ])
            ->assertStatus(400);

        $this->assertDatabaseHas('watchlists', [
            'user_id' => $user->id,
            'movie_id' => 1,
        ]);
    }

    public function test_a_user_id_in_the_body_is_ignored(): void
    {
        $user = $this->createUser();
        $other = $this->createUser('other');
        $token = $this->loginToken($user);

        $this->withBearer($token)
            ->postJson('/api/watched', [
                'movieId' => 1,
                'rating' => 5,
                'review' => 'Spoofed user.',
                'user_id' => $other->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('reviews', [
            'user_id' => $user->id,
            'movie_id' => 1,
        ]);
        $this->assertDatabaseMissing('reviews', [
            'user_id' => $other->id,
            'movie_id' => 1,
        ]);
    }

    public function test_it_only_returns_the_authenticated_users_history_newest_first(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other');

        $this->addReview($owner, 1, 4, 'First.');
        $this->travel(1)->minutes();
        $this->addReview($owner, 2, 5, 'Second.');
        $this->addReview($other, 3, 2, 'Not mine.');

        $token = $this->loginToken($owner);

        $response = $this->withBearer($token)->getJson('/api/watched')->assertOk();
        $items = $response->json();

        $this->assertCount(2, $items);
        $this->assertSame([2, 1], collect($items)->pluck('movie_id')->all());
        $this->assertSame([5, 4], collect($items)->pluck('rating')->all());
        $this->assertSame(['Second.', 'First.'], collect($items)->pluck('review')->all());
        $this->assertSame(2, $items[0]['movie']['id']);
        $this->assertSame('Parasite', $items[0]['movie']['title']);
        $this->assertNotNull($items[0]['created_at']);
    }

    public function test_the_watched_query_count_does_not_grow_with_the_number_of_entries(): void
    {
        $user = $this->createUser();
        $token = $this->loginToken($user);
        $this->addReview($user, 1);

        $single = $this->countWatchedQueries($token);

        foreach ([2, 3, 4, 5] as $movieId) {
            $this->addReview($user, $movieId);
        }

        $this->assertSame($single, $this->countWatchedQueries($token));
    }

    private function countWatchedQueries(string $token): int
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/watched')
            ->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
