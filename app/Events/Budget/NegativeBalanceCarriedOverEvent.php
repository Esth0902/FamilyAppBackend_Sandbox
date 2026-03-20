<?php

namespace App\Events\Budget;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NegativeBalanceCarriedOverEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $householdId,
        public readonly int $userId,
        public readonly float $carryAmount,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly string $nextPeriodStart,
        public readonly int $resetAdjustmentsCount,
    ) {
    }
}
