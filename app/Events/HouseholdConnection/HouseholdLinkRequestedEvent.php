<?php

namespace App\Events\HouseholdConnection;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HouseholdLinkRequestedEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<int, int>  $targetParentIds
     */
    public function __construct(
        public readonly int $linkRequestId,
        public readonly int $requesterHouseholdId,
        public readonly string $requesterHouseholdName,
        public readonly int $targetHouseholdId,
        public readonly string $targetHouseholdName,
        public readonly int $requestedByUserId,
        public readonly string $requestedByUserName,
        public readonly array $targetParentIds,
    ) {
    }
}

