<?php

namespace App\Http\Requests\Recipe;

use App\Models\Recipe;
use App\Models\User;

class ShowRecipeRequest extends RecipeContextRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $recipe = $this->route('recipe');

        return $user instanceof User
            && $recipe instanceof Recipe
            && $user->can('view', $recipe)
            && $this->household()->exists;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'servings' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ];
    }
}
