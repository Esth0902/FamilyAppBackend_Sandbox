<?php

namespace App\Http\Requests\Recipe;

use App\Models\Recipe;
use App\Models\User;

class UpdateRecipeRequest extends RecipeContextRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $recipe = $this->route('recipe');

        return $user instanceof User
            && $recipe instanceof Recipe
            && $user->can('update', $recipe);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:' . implode(',', self::RECIPE_TYPES)],
            'description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.name' => ['required', 'string', 'max:255'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:50'],
            'ingredients.*.quantity' => ['nullable', 'numeric'],
            'ingredients.*.category' => ['nullable', 'string', 'in:' . implode(',', self::INGREDIENT_CATEGORIES)],
            'base_servings' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }
}
