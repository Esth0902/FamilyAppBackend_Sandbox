<?php

namespace App\Actions\Budget\Results;

use App\Models\PocketMoneyTransaction;

class ValidatePaymentResult
{
    public function __construct(
        public readonly string $message,
        public readonly int $statusCode = 200,
        public readonly ?PocketMoneyTransaction $transaction = null,
        public readonly ?float $carryAmount = null,
        public readonly ?string $periodStart = null,
        public readonly ?string $periodEnd = null,
        public readonly ?string $nextPeriodStart = null,
    ) {
    }
}
