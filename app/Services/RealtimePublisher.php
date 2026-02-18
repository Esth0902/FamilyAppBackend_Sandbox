<?php

namespace App\Services;

use App\Events\HouseholdRealtimeEvent;

class RealtimePublisher
{
    public function publishHousehold(
        int $householdId,
        string $module,
        string $type,
        array $payload = []
    ): void {
        event(new HouseholdRealtimeEvent(
            householdId: $householdId,
            module: $module,
            type: $type,
            payload: $payload,
        ));
    }
}
