<?php

namespace App\DTOs\Budget;

final readonly class AdvanceRequestDTO
{
    public function __construct(
        public float $amount,
        public string $comment,
    ) {
    }
}

