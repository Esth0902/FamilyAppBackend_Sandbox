<?php

namespace App\Http\Requests\Recipe;

use App\Models\User;

class PreviewAiRecipeRequest extends RecipeContextRequest
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
            'title' => ['required', 'string', 'max:255'],
            'dietary_preferences' => ['nullable', 'string', 'max:500'],
        ];
    }
}
