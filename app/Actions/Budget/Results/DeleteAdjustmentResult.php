<?php

namespace App\Actions\Budget\Results;

class DeleteAdjustmentResult
{
    public function __construct(
        public readonly string $message,
        public readonly int $deletedTransactionId,
    ) {
    }
}
