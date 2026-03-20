<?php

namespace App\Actions\Recipe;

use App\Models\Household;
use App\Models\Recipe;

class ToggleRecipeBookmarkAction
{
    public function execute(Household $household, Recipe $recipe, bool $shouldSave, int $actorUserId): Recipe
    {
        if ($shouldSave) {
            $household->savedRecipes()->syncWithoutDetaching([
                (int) $recipe->id => ['added_by_user_id' => $actorUserId],
            ]);
        } else {
            $household->savedRecipes()->detach((int) $recipe->id);
        }

        return $recipe->fresh()->loadMissing(['ingredients', 'household.mealSettings'])->load([
            'savedByHouseholds' => fn ($savedByHouseholdsQuery) => $savedByHouseholdsQuery->whereKey((int) $household->id),
        ]);
    }
}
