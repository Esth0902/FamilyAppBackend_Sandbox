<?php

namespace App\Actions\Calendar;

use App\Events\Calendar\MealPlanUpdatedEvent;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateMealPlanAction
{
    /**
     * @param array<string, mixed> $mealPlanUpdatePayload
     */
    public function execute(
        Household $household,
        User $actor,
        MealPlan $mealPlan,
        array $mealPlanUpdatePayload,
        ?int $recipeId,
        int $servings
    ): MealPlan {
        $mealPlan = DB::transaction(function () use ($mealPlan, $mealPlanUpdatePayload, $recipeId, $servings): MealPlan {
            $mealPlan->update($mealPlanUpdatePayload);
            $mealPlan->items()->delete();
            if ($recipeId !== null) {
                $mealPlan->items()->create([
                    'recipe_id' => $recipeId,
                    'servings' => $servings,
                    'position' => 1,
                ]);
            }

            return $mealPlan->load(['items.recipe:id,title,type']);
        });

        event(new MealPlanUpdatedEvent(
            mealPlan: $mealPlan,
            householdId: (int) $household->id,
            actorUserId: (int) $actor->id,
            actorName: (string) ($actor->name ?? 'Un membre'),
        ));

        return $mealPlan;
    }
}
