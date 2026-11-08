<?php

namespace App\Services;

use App\Models\Movie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class MovieService
{
    /**
     * Canonical genre names from the seeded dataset, resolved once per request.
     *
     * @var list<string>|null
     */
    private ?array $canonicalGenres = null;

    public function list(?string $genre, ?string $search, bool $shuffle, ?int $seed = null): Collection
    {
        return $this->filteredQuery($genre, $search, $shuffle, $seed)->get();
    }

    public function paginate(
        ?string $genre,
        ?string $search,
        bool $shuffle,
        ?int $seed,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        return $this->filteredQuery($genre, $search, $shuffle, $seed)
            ->paginate($perPage, ['*'], 'page', $page);
    }

    private function filteredQuery(
        ?string $genre,
        ?string $search,
        bool $shuffle,
        ?int $seed,
    ): Builder {
        $query = Movie::query();

        $genre = trim((string) $genre);

        if ($genre !== '') {
            $canonicalGenre = $this->canonicalGenre($genre);

            if ($canonicalGenre === null) {
                return $query->whereRaw('1 = 0');
            }

            $query->whereJsonContains('genres', $canonicalGenre);
        }

        $search = trim((string) $search);

        if ($search !== '') {
            $pattern = '%'.$this->escapeLike(Str::lower($search)).'%';

            $query->where(function ($query) use ($pattern) {
                $query->whereRaw('LOWER(title) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(director) LIKE ?', [$pattern]);
            });
        }

        if ($shuffle) {
            if ($seed === null) {
                $query->inRandomOrder();
            } else {
                $query->inRandomOrder($seed);
            }
        } else {
            $query->orderBy('id');
        }

        return $query;
    }

    public function find(int $id): Movie
    {
        return Movie::query()->findOrFail($id);
    }

    private function canonicalGenre(string $genre): ?string
    {
        $this->canonicalGenres ??= Movie::query()
            ->pluck('genres')
            ->flatten()
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        $needle = Str::lower($genre);

        foreach ($this->canonicalGenres as $canonicalGenre) {
            if (Str::lower($canonicalGenre) === $needle) {
                return $canonicalGenre;
            }
        }

        return null;
    }

    /**
     * Escape LIKE wildcards so user input is matched literally.
     */
    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
