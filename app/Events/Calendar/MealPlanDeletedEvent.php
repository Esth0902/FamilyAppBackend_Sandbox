<?php

namespace App\Events\Calendar;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MealPlanDeletedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $mealPlanId,
        public readonly ?string $mealPlanDate,
        public readonly string $mealType,
        public readonly int $householdId,
        public readonly int $actorUserId,
        public readonly string $actorName,
    ) {
    }
}
