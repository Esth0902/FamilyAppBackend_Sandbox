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
        $audienceMode = Event::normalizeAudienceMode((string) $event->audience_mode);
        $audienceUserIds = $audienceMode === Event::AUDIENCE_ALL_MEMBERS
            ? $household->users()
                ->pluck('users.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all()
            : $event->invitations()
                ->where('household_id', (int) $household->id)
                ->pluck('user_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();

        $event->delete();

        event(new CalendarEventDeletedEvent(
            eventId: $eventId,
            eventTitle: $eventTitle,
            householdId: (int) $household->id,
            actorUserId: (int) $actor->id,
            actorName: (string) ($actor->name ?? 'Un membre'),
            wasSharedWithOtherHousehold: $wasSharedWithOtherHousehold,
            linkedHouseholdId: $linkedHouseholdId,
            audienceMode: $audienceMode,
            audienceUserIds: $audienceUserIds,
        ));
    }
}
