<?php

namespace App\Events\Budget;

use App\Models\PocketMoneyTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentValidatedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PocketMoneyTransaction $transaction,
        public readonly int $householdId,
        public readonly int $userId,
        public readonly float $amount,
        public readonly int $resetAdjustmentsCount,
    ) {
    }
}
