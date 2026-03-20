<?php

namespace App\Actions\Calendar;

use App\Actions\Calendar\Concerns\ResolvesLinkedHousehold;
use App\Events\Calendar\CalendarEventCreatedEvent;
use App\Models\Event;
use App\Models\Household;
use App\Models\User;
use Carbon\Carbon;

class StoreEventAction
{
    use ResolvesLinkedHousehold;

    /**
     * @param array<string, mixed> $validated
     */
    public function execute(Household $household, User $actor, array $validated): Event
    {
        $shouldShare = (bool) ($validated['is_shared_with_other_household'] ?? false);
        $linkedHouseholdId = $shouldShare ? $this->resolveConnectedHouseholdId($household) : null;

        $event = Event::query()->create([
            'household_id' => $household->id,
            'created_by_user_id' => (int) $actor->id,
            'title' => trim((string) $validated['title']),
            'description' => $validated['description'] ?? null,
            'start_at' => Carbon::parse((string) $validated['start_at']),
            'end_at' => Carbon::parse((string) $validated['end_at']),
            'is_shared_with_other_household' => $shouldShare,
        ])->load('creator:id,name');

        event(new CalendarEventCreatedEvent(
            event: $event,
            householdId: (int) $household->id,
            actorUserId: (int) $actor->id,
            actorName: (string) ($actor->name ?? 'Un membre'),
            linkedHouseholdId: $linkedHouseholdId,
        ));

        return $event;
    }
}
