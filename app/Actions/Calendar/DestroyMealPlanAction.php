<?php

namespace App\Actions\Calendar;

use App\Events\Calendar\MealPlanDeletedEvent;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DestroyMealPlanAction
{
    public function execute(Household $household, User $actor, MealPlan $mealPlan): void
    {
        $mealPlanId = (int) $mealPlan->id;
        $mealPlanDate = optional($mealPlan->date)->toDateString();
        $mealType = (string) $mealPlan->meal_type;

        DB::transaction(function () use ($mealPlan): void {
            $mealPlan->items()->delete();
            $mealPlan->delete();
        });

        event(new MealPlanDeletedEvent(
            mealPlanId: $mealPlanId,
            mealPlanDate: $mealPlanDate,
            mealType: $mealType,
            householdId: (int) $household->id,
            actorUserId: (int) $actor->id,
            actorName: (string) ($actor->name ?? 'Un membre'),
        ));
    }
}
