<?php

namespace App\Http\Requests\Recipe;

use App\Models\Recipe;
use App\Models\User;

class FinalizeAiRecipeStoreRequest extends RecipeContextRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user instanceof User) {
            return false;
        }

        $householdId = $this->input('household_id');
        if (!is_numeric($householdId)) {
            return true;
        }

        return $user->can('create', [Recipe::class, (int) $householdId]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'household_id' => ['required', 'integer', 'exists:households,id'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:' . implode(',', self::RECIPE_TYPES)],
            'description' => ['required', 'string'],
            'instructions' => ['required', 'string'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.name' => ['required', 'string', 'max:255'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:50'],
            'ingredients.*.quantity' => ['required', 'numeric'],
            'ingredients.*.category' => ['nullable', 'string', 'in:' . implode(',', self::INGREDIENT_CATEGORIES)],
        ];
    }
}
