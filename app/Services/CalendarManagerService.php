<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Household;
use App\Models\MealPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CalendarManagerService
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function createEvent(Household $household, int $createdByUserId, array $validated): Event
    {
        return Event::query()->create([
            'household_id' => $household->id,
            'created_by_user_id' => $createdByUserId,
            'title' => trim((string) $validated['title']),
            'description' => $validated['description'] ?? null,
            'start_at' => Carbon::parse((string) $validated['start_at']),
            'end_at' => Carbon::parse((string) $validated['end_at']),
            'is_shared_with_other_household' => (bool) ($validated['is_shared_with_other_household'] ?? false),
        ])->load('creator:id,name');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $mealPlanUpdatePayload
     */
    public function storeMealPlan(
        Household $household,
        array $validated,
        array $mealPlanUpdatePayload,
        ?int $recipeId,
        int $servings
    ): MealPlan {
        return DB::transaction(function () use ($household, $validated, $mealPlanUpdatePayload, $recipeId, $servings): MealPlan {
            $mealPlan = MealPlan::query()->updateOrCreate(
                [
                    'household_id' => $household->id,
                    'date' => (string) $validated['date'],
                    'meal_type' => (string) $validated['meal_type'],
                ],
                $mealPlanUpdatePayload
            );

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
    }
}
