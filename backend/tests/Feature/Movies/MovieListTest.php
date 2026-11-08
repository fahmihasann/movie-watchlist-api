<?php

namespace Tests\Feature\Movies;

use Database\Seeders\MovieSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MovieListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MovieSeeder::class);
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $movies): array
    {
        return collect($movies)->pluck('id')->sort()->values()->all();
    }

    /**
     * @return list<int>
     */
    private function orderedIds(TestResponse $response): array
    {
        return collect($response->json())->pluck('id')->all();
    }

    private function assertPaginationHeaders(
        TestResponse $response,
        int $total,
        int $totalPages,
        int $page,
        int $perPage,
    ): void {
        $response->assertHeader('X-Total-Count', (string) $total)
            ->assertHeader('X-Total-Pages', (string) $totalPages)
            ->assertHeader('X-Page', (string) $page)
            ->assertHeader('X-Per-Page', (string) $perPage);
    }

    private function queryCountFor(string $uri): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson($uri)->assertOk();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    private function addMovies(int $count): void
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'title' => "Extra Movie {$i}",
                'year' => 2026,
                'director' => 'Test Director',
                'genres' => json_encode(['Drama']),
                'rating' => 3.0,
                'duration' => '100 min',
                'synopsis' => 'Extra movie used to verify query counts.',
                'poster_url' => 'https://example.com/poster.jpg',
                'cast_members' => json_encode(['Test Actor']),
            ];
        }

        DB::table('movies')->insert($rows);
    }

    public function test_it_returns_all_movies_as_a_plain_array_without_authentication(): void
    {
        $response = $this->getJson('/api/movies');

        $response->assertOk()
            ->assertHeader('content-type', 'application/json');

        $movies = $response->json();

        $this->assertIsArray($movies);
        $this->assertCount(20, $movies);
        $this->assertArrayNotHasKey('data', $movies);
        $this->assertSame(range(1, 20), array_column($movies, 'id'));

        $inception = collect($movies)->firstWhere('id', 1);

        $this->assertIsArray($inception['cast']);
        $this->assertSame('Leonardo DiCaprio', $inception['cast'][0]);
        $this->assertArrayNotHasKey('cast_members', $inception);
        $this->assertIsArray($inception['genres']);
        $this->assertIsFloat($inception['rating']);
        $this->assertIsInt($inception['year']);
    }

    public function test_it_filters_by_a_canonical_genre(): void
    {
        $response = $this->getJson('/api/movies?genre=Sci-Fi')->assertOk();

        $this->assertSame([1, 4, 8, 12, 13, 16, 18], $this->ids($response->json()));

        foreach ($response->json() as $movie) {
            $this->assertContains('Sci-Fi', $movie['genres']);
        }
    }

    public function test_genre_filter_is_case_insensitive(): void
    {
        $canonical = $this->getJson('/api/movies?genre=Sci-Fi')->assertOk()->json();
        $lowercase = $this->getJson('/api/movies?genre=sci-fi')->assertOk()->json();

        $this->assertSame($this->ids($canonical), $this->ids($lowercase));
    }

    public function test_an_unknown_genre_returns_an_empty_array(): void
    {
        $this->getJson('/api/movies?genre=NotAGenre')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_search_matches_part_of_the_title_case_insensitively(): void
    {
        $response = $this->getJson('/api/movies?search=INCEPT')->assertOk();

        $this->assertSame([1], $this->ids($response->json()));
    }

    public function test_search_also_matches_the_director(): void
    {
        $response = $this->getJson('/api/movies?search=NOLAN')->assertOk();

        $this->assertSame([1, 3, 4, 14, 19], $this->ids($response->json()));
    }

    public function test_like_wildcards_in_search_are_treated_as_literal_text(): void
    {
        $this->getJson('/api/movies?search=%25')->assertOk()->assertExactJson([]);
        $this->getJson('/api/movies?search=_')->assertOk()->assertExactJson([]);
    }

    public function test_search_and_genre_filters_can_be_combined(): void
    {
        $response = $this->getJson('/api/movies?genre=sci-fi&search=nolan')->assertOk();

        $this->assertSame([1, 4], $this->ids($response->json()));
    }

    public function test_shuffle_returns_the_same_set_of_movies(): void
    {
        $plain = $this->getJson('/api/movies')->assertOk()->json();
        $shuffled = $this->getJson('/api/movies?shuffle=true')->assertOk()->json();

        $this->assertCount(20, $shuffled);
        $this->assertSame($this->ids($plain), $this->ids($shuffled));
    }

    public function test_it_does_not_send_pagination_headers_without_pagination_parameters(): void
    {
        $response = $this->getJson('/api/movies')->assertOk();

        $response->assertHeaderMissing('X-Total-Count')
            ->assertHeaderMissing('X-Total-Pages')
            ->assertHeaderMissing('X-Page')
            ->assertHeaderMissing('X-Per-Page');
    }

    public function test_it_paginates_ten_movies_per_page_by_default(): void
    {
        $pageOne = $this->getJson('/api/movies?page=1')->assertOk();
        $this->assertSame(range(1, 10), $this->orderedIds($pageOne));
        $this->assertPaginationHeaders($pageOne, 20, 2, 1, 10);

        $pageTwo = $this->getJson('/api/movies?page=2')->assertOk();
        $this->assertSame(range(11, 20), $this->orderedIds($pageTwo));
        $this->assertPaginationHeaders($pageTwo, 20, 2, 2, 10);

        $combined = array_merge($this->orderedIds($pageOne), $this->orderedIds($pageTwo));

        $this->assertCount(20, $combined);
        $this->assertCount(20, array_unique($combined));
        $this->assertSame(range(1, 20), collect($combined)->sort()->values()->all());
    }

    public function test_per_page_without_page_is_treated_as_page_one(): void
    {
        $response = $this->getJson('/api/movies?per_page=5')->assertOk();

        $this->assertSame(range(1, 5), $this->orderedIds($response));
        $this->assertPaginationHeaders($response, 20, 4, 1, 5);

        $pageTwo = $this->getJson('/api/movies?page=2&per_page=5')->assertOk();

        $this->assertSame(range(6, 10), $this->orderedIds($pageTwo));
        $this->assertPaginationHeaders($pageTwo, 20, 4, 2, 5);
    }

    public function test_a_page_beyond_the_last_page_returns_an_empty_array_with_headers(): void
    {
        $response = $this->getJson('/api/movies?page=3')
            ->assertOk()
            ->assertExactJson([]);

        $this->assertPaginationHeaders($response, 20, 2, 3, 10);
    }

    public function test_invalid_pagination_parameters_return_a_400_response(): void
    {
        $cases = [
            'page=0' => 'page',
            'page=-1' => 'page',
            'page=abc' => 'page',
            'per_page=0' => 'per_page',
            'per_page=51' => 'per_page',
            'per_page=abc' => 'per_page',
        ];

        foreach ($cases as $query => $field) {
            $response = $this->getJson('/api/movies?'.$query);

            $response->assertStatus(400)->assertJsonStructure(['message', 'errors']);

            $this->assertArrayHasKey(
                $field,
                $response->json('errors'),
                "Missing [{$field}] error for ?{$query}",
            );
        }
    }

    public function test_invalid_filter_and_shuffle_values_return_a_400_response(): void
    {
        $cases = [
            'genre='.str_repeat('a', 51) => 'genre',
            'search='.str_repeat('a', 101) => 'search',
            'shuffle=yes' => 'shuffle',
        ];

        foreach ($cases as $query => $field) {
            $response = $this->getJson('/api/movies?'.$query);

            $response->assertStatus(400)->assertJsonStructure(['message', 'errors']);

            $this->assertArrayHasKey(
                $field,
                $response->json('errors'),
                "Missing [{$field}] error for ?{$query}",
            );
        }
    }

    public function test_shuffle_accepts_the_documented_values(): void
    {
        foreach (['true', 'false', '1', '0'] as $value) {
            $this->getJson('/api/movies?shuffle='.$value)
                ->assertOk()
                ->assertJsonCount(20);
        }
    }

    public function test_pagination_combines_with_the_genre_filter(): void
    {
        $unpaginated = $this->getJson('/api/movies?genre=Sci-Fi')->assertOk();
        $expectedIds = $this->ids($unpaginated->json());

        $this->assertCount(7, $expectedIds);

        $combined = [];

        for ($page = 1; $page <= 4; $page++) {
            $response = $this->getJson("/api/movies?genre=Sci-Fi&per_page=2&page={$page}")
                ->assertOk();

            $this->assertPaginationHeaders($response, 7, 4, $page, 2);

            $combined = array_merge($combined, $this->orderedIds($response));
        }

        $this->assertCount(7, $combined);
        $this->assertCount(7, array_unique($combined));
        $this->assertSame($expectedIds, collect($combined)->sort()->values()->all());
    }

    public function test_shuffle_with_a_seed_is_stable_across_pages(): void
    {
        $pageOne = $this->getJson('/api/movies?shuffle=true&seed=42&page=1&per_page=10')
            ->assertOk();
        $pageTwo = $this->getJson('/api/movies?shuffle=true&seed=42&page=2&per_page=10')
            ->assertOk();
        $repeated = $this->getJson('/api/movies?shuffle=true&seed=42&page=1&per_page=10')
            ->assertOk();

        $this->assertSame($this->orderedIds($pageOne), $this->orderedIds($repeated));

        $combined = array_merge($this->orderedIds($pageOne), $this->orderedIds($pageTwo));

        $this->assertCount(20, $combined);
        $this->assertCount(20, array_unique($combined));
        $this->assertSame(range(1, 20), collect($combined)->sort()->values()->all());
    }

    public function test_paginated_and_unpaginated_bodies_are_plain_arrays_without_an_accept_header(): void
    {
        foreach (['/api/movies', '/api/movies?page=1&per_page=10'] as $uri) {
            $response = $this->get($uri);

            $response->assertOk()
                ->assertHeader('content-type', 'application/json');

            $body = $response->json();

            $this->assertIsArray($body);
            $this->assertArrayNotHasKey('data', $body);
            $this->assertStringStartsWith('[', ltrim((string) $response->getContent()));
        }
    }

    public function test_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $plainBefore = $this->queryCountFor('/api/movies');
        $paginatedBefore = $this->queryCountFor('/api/movies?page=1&per_page=10');

        $this->addMovies(40);

        $this->assertSame($plainBefore, $this->queryCountFor('/api/movies'));
        $this->assertSame($paginatedBefore, $this->queryCountFor('/api/movies?page=1&per_page=10'));
    }
}
