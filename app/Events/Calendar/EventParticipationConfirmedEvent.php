<?php

namespace App\Events\Calendar;

use App\Models\Event;
use App\Models\EventParticipation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EventParticipationConfirmedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly EventParticipation $participation,
        public readonly Event $event,
        public readonly int $householdId,
        public readonly int $actorUserId,
        public readonly string $actorName,
    ) {
    }
}
