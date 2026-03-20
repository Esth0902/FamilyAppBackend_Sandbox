<?php

namespace App\Actions\Calendar;

use App\Actions\Calendar\Concerns\ResolvesLinkedHousehold;
use App\Events\Calendar\CalendarEventUpdatedEvent;
use App\Models\Event;
use App\Models\Household;
use App\Models\User;
use Carbon\Carbon;

class UpdateEventAction
{
    use ResolvesLinkedHousehold;

    /**
     * @param array<string, mixed> $validated
     */
    public function execute(Household $household, User $actor, Event $event, array $validated): Event
    {
        $shouldShare = (bool) ($validated['is_shared_with_other_household'] ?? false);
        $wasSharedWithOtherHousehold = (bool) $event->is_shared_with_other_household;
        $linkedHouseholdId = $this->resolveConnectedHouseholdId($household);

        $event->update([
            'title' => trim((string) $validated['title']),
            'description' => $validated['description'] ?? null,
            'start_at' => Carbon::parse((string) $validated['start_at']),
            'end_at' => Carbon::parse((string) $validated['end_at']),
            'is_shared_with_other_household' => $shouldShare,
        ]);

        $event->load('creator:id,name');

        event(new CalendarEventUpdatedEvent(
            event: $event,
            householdId: (int) $household->id,
            actorUserId: (int) $actor->id,
            actorName: (string) ($actor->name ?? 'Un membre'),
            linkedHouseholdId: $linkedHouseholdId,
            wasSharedWithOtherHousehold: $wasSharedWithOtherHousehold,
            isSharedWithOtherHousehold: (bool) $event->is_shared_with_other_household,
        ));

        return $event;
    }
}
