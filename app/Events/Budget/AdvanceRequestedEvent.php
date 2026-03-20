<?php

namespace App\Events\Budget;

use App\Models\PocketMoneyTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdvanceRequestedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PocketMoneyTransaction $transaction,
        public readonly int $householdId,
        public readonly int $requesterUserId,
        public readonly string $requesterName,
        public readonly float $amount,
        public readonly string $requestKind,
        public readonly ?string $comment,
    ) {
    }
}

