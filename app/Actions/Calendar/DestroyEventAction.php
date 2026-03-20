<?php

namespace App\Actions\Calendar;

use App\Actions\Calendar\Concerns\ResolvesLinkedHousehold;
use App\Events\Calendar\CalendarEventDeletedEvent;
use App\Models\Event;
use App\Models\Household;
use App\Models\User;

class DestroyEventAction
{
    use ResolvesLinkedHousehold;

    public function execute(Household $household, User $actor, Event $event): void
    {
        $eventId = (int) $event->id;
        $eventTitle = (string) $event->title;
        $wasSharedWithOtherHousehold = (bool) $event->is_shared_with_other_household;
        $linkedHouseholdId = $wasSharedWithOtherHousehold ? $this->resolveConnectedHouseholdId($household) : null;

        $event->delete();

        event(new CalendarEventDeletedEvent(
            eventId: $eventId,
            eventTitle: $eventTitle,
            householdId: (int) $household->id,
            actorUserId: (int) $actor->id,
            actorName: (string) ($actor->name ?? 'Un membre'),
            wasSharedWithOtherHousehold: $wasSharedWithOtherHousehold,
            linkedHouseholdId: $linkedHouseholdId,
        ));
    }
}
