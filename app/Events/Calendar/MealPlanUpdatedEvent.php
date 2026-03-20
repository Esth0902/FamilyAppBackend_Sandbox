<?php

namespace App\Events\Calendar;

use App\Models\MealPlan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MealPlanUpdatedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly MealPlan $mealPlan,
        public readonly int $householdId,
        public readonly int $actorUserId,
        public readonly string $actorName,
    ) {
    }
}
