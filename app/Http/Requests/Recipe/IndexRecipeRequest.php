<?php

namespace App\Http\Requests\Recipe;

use App\Models\User;

class IndexRecipeRequest extends RecipeContextRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->household()->exists;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', 'string', 'in:mine,all'],
            'q' => ['sometimes', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'servings' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'type' => ['sometimes', 'string', 'in:petit-déjeuner,entrée,plat principal,dessert,collation,boisson,autre'],
        ];
    }
}
