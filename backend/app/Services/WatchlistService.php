<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Movie;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;

class WatchlistService
{
    /**
     * Watchlist entries scoped to the authenticated user, newest first.
     */
    public function list(User $user): Collection
    {
        return $user->movies()
            ->orderByDesc('watchlists.created_at')
            ->orderByDesc('watchlists.id')
            ->get();
    }

    public function add(User $user, int $movieId): Movie
    {
        $movie = Movie::query()->find($movieId);

        if ($movie === null) {
            throw ApiException::movieNotFound();
        }

        if ($this->exists($user, $movieId)) {
            throw ApiException::movieAlreadyInWatchlist();
        }

        try {
            Watchlist::query()->create([
                'user_id' => $user->id,
                'movie_id' => $movieId,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw ApiException::movieAlreadyInWatchlist();
        }

        return $movie;
    }

    public function remove(User $user, int $movieId): void
    {
        $deleted = Watchlist::query()
            ->where('user_id', $user->id)
            ->where('movie_id', $movieId)
            ->delete();

        if ($deleted === 0) {
            throw ApiException::movieNotInWatchlist();
        }
    }

    private function exists(User $user, int $movieId): bool
    {
        return Watchlist::query()
            ->where('user_id', $user->id)
            ->where('movie_id', $movieId)
            ->exists();
    }
}
