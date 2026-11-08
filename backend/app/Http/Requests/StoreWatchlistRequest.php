<?php

namespace App\Http\Requests;

use App\Rules\StrictInteger;
use Illuminate\Foundation\Http\FormRequest;

class StoreWatchlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'movieId' => ['required', 'integer', new StrictInteger, 'min:1', 'max:9223372036854775807'],
        ];
    }
}
