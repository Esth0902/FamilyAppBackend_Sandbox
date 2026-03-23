<?php

namespace App\Actions\Recipe;

use App\Models\Household;
use App\Models\Recipe;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetRecipesAction
{
    public function execute(Household $household, string $scope, ?string $searchTerm = null, int $limit = 20): LengthAwarePaginator
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

        if ($searchTerm !== null) {
            $driver = $query->getConnection()->getDriverName();
            $operator = $driver === 'pgsql' ? 'ilike' : 'like';
            $query->where('title', $operator, '%' . trim($searchTerm) . '%');
        }

        $limit = max(1, min($limit, 100));

        return $query
            ->orderBy('title', 'asc')
            ->paginate($limit);
    }
}
