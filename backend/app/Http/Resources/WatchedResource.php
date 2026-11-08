<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WatchedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'movie_id' => $this->movie_id,
            'rating' => (int) $this->rating,
            'review' => $this->review,
            'created_at' => $this->created_at?->toIso8601String(),
            'movie' => $this->whenLoaded('movie', fn () => new MovieResource($this->movie)),
        ];
    }
}
