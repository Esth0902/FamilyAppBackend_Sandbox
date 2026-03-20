<?php

namespace App\Http\Requests\Recipe;

use App\Models\User;

class SuggestAiRecipeRequest extends RecipeContextRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['nullable', 'string', 'max:500'],
            'dietary_preferences' => ['nullable', 'string', 'max:500'],
            'count' => ['nullable', 'integer', 'max:5'],
            'intent' => ['nullable', 'string', 'in:ideas,specific'],
        ];
    }
}
