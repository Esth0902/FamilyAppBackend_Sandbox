<?php

namespace App\Actions\Calendar;

use App\Events\Calendar\MealPlanAttendanceConfirmedEvent;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\MealPlanAttendance;
use App\Models\User;

class ConfirmMealPlanAttendanceAction
{
    /**
     * @param array<string, mixed> $validated
     */
    public function execute(Household $household, User $actor, MealPlan $mealPlan, array $validated): MealPlanAttendance
    {
        $status = (string) $validated['status'];
        $reason = trim((string) ($validated['reason'] ?? ''));
        if ($status === 'present') {
            $reason = '';
        }

        $attendance = MealPlanAttendance::query()->updateOrCreate(
            [
                'household_id' => $household->id,
                'meal_plan_id' => $mealPlan->id,
                'user_id' => $actor->id,
            ],
            [
                'status' => $status,
                'reason' => $reason !== '' ? $reason : null,
                'responded_at' => now(),
            ]
        );

        event(new MealPlanAttendanceConfirmedEvent(
            attendance: $attendance,
            mealPlan: $mealPlan,
            householdId: (int) $household->id,
            actorUserId: (int) $actor->id,
            actorName: (string) ($actor->name ?? 'Un membre'),
        ));

        return $attendance;
    }
}
