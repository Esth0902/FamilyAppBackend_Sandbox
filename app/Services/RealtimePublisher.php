<?php

namespace App\Services;

use App\Events\HouseholdRealtimeEvent;
use App\Events\UserRealtimeEvent;

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

    public function publishUser(
        int $userId,
        string $module,
        string $type,
        array $payload = []
    ): void {
        event(new UserRealtimeEvent(
            userId: $userId,
            module: $module,
            type: $type,
            payload: $payload,
        ));
    }
}
