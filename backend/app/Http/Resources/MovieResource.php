<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovieResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'year' => (int) $this->year,
            'director' => $this->director,
            'genres' => array_values((array) $this->genres),
            'rating' => (float) $this->rating,
            'duration' => $this->duration,
            'synopsis' => $this->synopsis,
            'poster_url' => $this->poster_url,
            'cast' => array_values((array) $this->cast_members),
        ];
    }
}
