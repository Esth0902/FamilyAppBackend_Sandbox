<?php

namespace App\Events\Budget;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BudgetSettingUpdatedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $householdId,
        public readonly int $userId,
        public readonly string $recurrence,
        public readonly int $resetDay,
        public readonly bool $allowAdvances,
    ) {
    }
}
