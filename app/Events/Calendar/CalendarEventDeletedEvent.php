<?php

namespace App\Events\Calendar;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CalendarEventDeletedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $eventId,
        public readonly string $eventTitle,
        public readonly int $householdId,
        public readonly int $actorUserId,
        public readonly string $actorName,
        public readonly bool $wasSharedWithOtherHousehold,
        public readonly ?int $linkedHouseholdId,
        public readonly string $audienceMode,
        /** @var array<int, int> */
        public readonly array $audienceUserIds,
    ) {
    }
}
