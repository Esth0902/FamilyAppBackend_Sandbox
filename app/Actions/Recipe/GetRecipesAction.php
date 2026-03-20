<?php

namespace App\Actions\Recipe;

use App\Models\Household;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Collection;

class GetRecipesAction
{
    /**
     * @return Collection<int, Recipe>
     */
    public function execute(Household $household, string $scope): Collection
    {
        $householdId = (int) $household->id;

        $query = Recipe::query()
            ->with(['ingredients', 'household.mealSettings'])
            ->withExists([
                'savedByHouseholds as is_bookmarked_for_household' => fn ($savedByHouseholdsQuery) => $savedByHouseholdsQuery
                    ->whereKey($householdId),
            ]);

        if ($scope === 'all') {
            $query->visibleForHousehold($householdId);
        } else {
            $query->mineForHousehold($householdId);
        }

        return $query
            ->orderBy('title', 'asc')
            ->get();
    }
}
