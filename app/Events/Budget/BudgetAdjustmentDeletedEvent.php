<?php

namespace App\Events\Budget;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BudgetAdjustmentDeletedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $householdId,
        public readonly int $transactionId,
        public readonly int $userId,
        public readonly float $amount,
        public readonly string $type,
        public readonly ?string $justification,
    ) {
    }
}
