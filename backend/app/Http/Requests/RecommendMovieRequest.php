<?php

namespace App\Http\Requests;

use App\Rules\NoNullBytes;
use Illuminate\Foundation\Http\FormRequest;

class RecommendMovieRequest extends FormRequest
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
            'prompt' => ['required', 'string', 'max:1000', new NoNullBytes],
        ];
    }

    protected function prepareForValidation(): void
    {
        $prompt = $this->input('prompt');

        if (is_string($prompt)) {
            $this->merge(['prompt' => trim($prompt)]);
        }
    }
}
