<?php

namespace App\Actions\Budget\Results;

use App\Models\PocketMoneyTransaction;

class BudgetTransactionResult
{
    public function __construct(
        public readonly string $message,
        public readonly PocketMoneyTransaction $transaction,
        public readonly int $statusCode = 200,
    ) {
    }
}
