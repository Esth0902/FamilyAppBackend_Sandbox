<?php

namespace App\Events\Calendar;

use App\Models\MealPlan;
use App\Models\MealPlanAttendance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MealPlanAttendanceConfirmedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly MealPlanAttendance $attendance,
        public readonly MealPlan $mealPlan,
        public readonly int $householdId,
        public readonly int $actorUserId,
        public readonly string $actorName,
    ) {
    }
}
