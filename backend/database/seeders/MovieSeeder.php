<?php

namespace Database\Seeders;

use App\Models\Movie;
use Illuminate\Database\Seeder;
use RuntimeException;

class MovieSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = base_path('../data/movies.json');

        if (! is_file($path)) {
            throw new RuntimeException("Movie seed file not found at: {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException("Movie seed file could not be read at: {$path}");
        }

        $movies = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($movies) || $movies === []) {
            throw new RuntimeException("Movie seed file is empty or invalid at: {$path}");
        }

        $rows = array_map(fn (array $movie) => [
            'id' => (int) $movie['id'],
            'title' => $movie['title'],
            'year' => (int) $movie['year'],
            'director' => $movie['director'],
            'genres' => json_encode($movie['genres'], JSON_UNESCAPED_UNICODE),
            'rating' => (float) $movie['rating'],
            'duration' => $movie['duration'],
            'synopsis' => $movie['synopsis'],
            'poster_url' => $movie['poster_url'],
            'cast_members' => json_encode($movie['cast'], JSON_UNESCAPED_UNICODE),
        ], $movies);

        // Eloquent's upsert does not apply model casts, so JSON columns are
        // encoded manually above. Movies have no timestamps by design.
        Movie::upsert(
            $rows,
            ['id'],
            ['title', 'year', 'director', 'genres', 'rating', 'duration', 'synopsis', 'poster_url', 'cast_members']
        );
    }
}
