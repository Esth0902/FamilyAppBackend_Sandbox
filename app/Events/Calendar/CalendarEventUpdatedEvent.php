<?php

namespace App\Events\Calendar;

use App\Models\Event;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CalendarEventUpdatedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Event $event,
        public readonly int $householdId,
        public readonly int $actorUserId,
        public readonly string $actorName,
        public readonly ?int $linkedHouseholdId,
        public readonly bool $wasSharedWithOtherHousehold,
        public readonly bool $isSharedWithOtherHousehold,
        /** @var array<int, int> */
        public readonly array $previousAudienceUserIds,
        /** @var array<int, int> */
        public readonly array $currentAudienceUserIds,
    ) {
    }
}
