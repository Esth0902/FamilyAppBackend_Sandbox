<?php

namespace App\Events\HouseholdConnection;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HouseholdLinkRespondedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $linkRequestId,
        public readonly int $fromHouseholdId,
        public readonly string $fromHouseholdName,
        public readonly int $toHouseholdId,
        public readonly string $toHouseholdName,
        public readonly int $respondedByUserId,
        public readonly string $respondedByUserName,
        public readonly string $status,
    ) {
    }
}

