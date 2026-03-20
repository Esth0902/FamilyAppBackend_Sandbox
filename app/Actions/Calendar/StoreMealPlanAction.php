<?php

namespace App\Actions\Calendar;

use App\Events\Calendar\MealPlanStoredEvent;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StoreMealPlanAction
{
    /**
     * @param array<string, mixed> $validated
     * @param array<string, mixed> $mealPlanUpdatePayload
     */
    public function execute(
        Household $household,
        User $actor,
        array $validated,
        array $mealPlanUpdatePayload,
        ?int $recipeId,
        int $servings
    ): MealPlan {
        $mealPlan = DB::transaction(function () use ($household, $validated, $mealPlanUpdatePayload, $recipeId, $servings): MealPlan {
            $storedMealPlan = MealPlan::query()->updateOrCreate(
                [
                    'household_id' => $household->id,
                    'date' => (string) $validated['date'],
                    'meal_type' => (string) $validated['meal_type'],
                ],
                $mealPlanUpdatePayload
            );

            $storedMealPlan->items()->delete();
            if ($recipeId !== null) {
                $storedMealPlan->items()->create([
                    'recipe_id' => $recipeId,
                    'servings' => $servings,
                    'position' => 1,
                ]);
            }

            return $storedMealPlan->load(['items.recipe:id,title,type']);
        });

        event(new MealPlanStoredEvent(
            mealPlan: $mealPlan,
            householdId: (int) $household->id,
            actorUserId: (int) $actor->id,
            actorName: (string) ($actor->name ?? 'Un membre'),
            wasRecentlyCreated: (bool) $mealPlan->wasRecentlyCreated,
        ));

        return $mealPlan;
    }
}
