<?php

namespace App\Actions\ShoppingList;

use App\Models\Household;
use App\Models\MealPlan;
use App\Models\ShoppingList;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ShowShoppingListAction
{
    /**
     * @return array{
     *     can_manage: bool,
     *     can_add_manual_items: bool,
     *     list: ShoppingList,
     *     from_date: string,
     *     to_date: string,
     *     meal_plans: Collection<int, MealPlan>
     * }
     */
    public function execute(Household $household, string $role, ShoppingList $list): array
    {
        $list->load([
            'items',
            'items.checkedBy:id,name',
            'items.createdBy:id,name',
            'items.ingredient:id,category',
        ]);

        $fromDate = now()->startOfDay();
        $toDate = (clone $fromDate)->addDays(13)->endOfDay();

        $mealPlans = MealPlan::query()
            ->where('household_id', $household->id)
            ->whereBetween('date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->with(['items.recipe.ingredients'])
            ->orderBy('date')
            ->get();

        return [
            'can_manage' => $role === User::ROLE_PARENT,
            'can_add_manual_items' => in_array($role, [User::ROLE_PARENT, User::ROLE_CHILD], true),
            'list' => $list,
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
            'meal_plans' => $mealPlans,
        ];
    }
}
