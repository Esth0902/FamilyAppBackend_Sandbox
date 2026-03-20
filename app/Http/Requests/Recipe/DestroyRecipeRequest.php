<?php

namespace App\Http\Requests\Recipe;

use App\Models\Recipe;
use App\Models\User;

class DestroyRecipeRequest extends RecipeContextRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $recipe = $this->route('recipe');

        return $user instanceof User
            && $recipe instanceof Recipe
            && $user->can('delete', $recipe);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
