<?php

namespace App\Http\Requests\Recipe;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;

class ToggleBookmarkRequest extends RecipeContextRequest
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $recipe = $this->route('recipe');
            if (!$recipe instanceof Recipe) {
                return;
            }

            if (!(bool) $recipe->is_global) {
                $validator->errors()->add('recipe', 'Seules les recettes globales peuvent être ajoutées ou retirées.');
            }
        });
    }
}
