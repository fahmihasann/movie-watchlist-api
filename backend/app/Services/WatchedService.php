<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class WatchedService
{
    /**
     * Watched entries (reviews) of the authenticated user, newest first.
     */
    public function list(User $user): Collection
    {
        return $user->reviews()
            ->with('movie')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Record a watched movie and remove it from the same user's watchlist.
     *
     * @param  array{movieId: int|string, rating: int|string, review: string}  $data
     */
    public function add(User $user, array $data): Review
    {
        $movieId = (int) $data['movieId'];

        $movie = Movie::query()->find($movieId);

        if ($movie === null) {
            throw ApiException::movieNotFound();
        }

        return DB::transaction(function () use ($user, $movie, $movieId, $data) {
            if ($this->alreadyReviewed($user, $movieId)) {
                throw ApiException::movieAlreadyReviewed();
            }

            try {
                $review = Review::query()->create([
                    'user_id' => $user->id,
                    'movie_id' => $movieId,
                    'rating' => $data['rating'],
                    'review' => $data['review'],
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                throw ApiException::movieAlreadyReviewed();
            }

            Watchlist::query()
                ->where('user_id', $user->id)
                ->where('movie_id', $movieId)
                ->delete();

            $review->setRelation('movie', $movie);

            return $review;
        });
    }

    private function alreadyReviewed(User $user, int $movieId): bool
    {
        return Review::query()
            ->where('user_id', $user->id)
            ->where('movie_id', $movieId)
            ->exists();
    }
}
