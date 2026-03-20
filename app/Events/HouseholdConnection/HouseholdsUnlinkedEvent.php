<?php

namespace App\Events\HouseholdConnection;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HouseholdsUnlinkedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $sourceHouseholdId,
        public readonly string $sourceHouseholdName,
        public readonly ?int $linkedHouseholdId,
        public readonly ?string $linkedHouseholdName,
        public readonly int $actorUserId,
        public readonly string $actorUserName,
    ) {
    }
}

