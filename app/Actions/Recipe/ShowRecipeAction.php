<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;

class ShowRecipeAction
{
    public function execute(Recipe $recipe): Recipe
    {
        return $recipe->loadMissing(['ingredients', 'household.mealSettings']);
    }
}
