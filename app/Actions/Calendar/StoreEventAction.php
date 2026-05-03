<?php

namespace App\Actions\Calendar;

use App\Actions\Calendar\Concerns\ResolvesLinkedHousehold;
use App\Events\Calendar\CalendarEventCreatedEvent;
use App\Models\Event;
use App\Models\Household;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
        $audienceMode = Event::normalizeAudienceMode((string) ($validated['audience_mode'] ?? Event::AUDIENCE_ALL_MEMBERS));
        $responseRequired = array_key_exists('response_required', $validated)
            ? (bool) $validated['response_required']
            : true;
        $invitedUserIds = $this->resolveInvitedUserIds($audienceMode, $actor, $validated);

        $event = DB::transaction(function () use (
            $household,
            $actor,
            $validated,
            $shouldShare,
            $audienceMode,
            $responseRequired,
            $invitedUserIds
        ): Event {
            $event = Event::query()->create([
                'household_id' => $household->id,
                'created_by_user_id' => (int) $actor->id,
                'title' => trim((string) $validated['title']),
                'description' => $validated['description'] ?? null,
                'start_at' => Carbon::parse((string) $validated['start_at']),
                'end_at' => Carbon::parse((string) $validated['end_at']),
                'is_shared_with_other_household' => $shouldShare,
                'audience_mode' => $audienceMode,
                'response_required' => $responseRequired,
            ]);

            $this->syncInvitations($event, (int) $household->id, $invitedUserIds);

            return $event;
        });

        $event->load([
            'creator:id,name',
            'invitations:id,event_id,household_id,user_id',
        ]);

        event(new CalendarEventCreatedEvent(
            event: $event,
            householdId: (int) $household->id,
            actorUserId: (int) $actor->id,
            actorName: (string) ($actor->name ?? 'Un membre'),
            linkedHouseholdId: $linkedHouseholdId,
        ));

        return $event;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<int, int>
     */
    private function resolveInvitedUserIds(string $audienceMode, User $actor, array $validated): array
    {
        if ($audienceMode === Event::AUDIENCE_ALL_MEMBERS) {
            return [];
        }

        if ($audienceMode === Event::AUDIENCE_ONLY_ME) {
            return [(int) $actor->id];
        }

        return collect($validated['invited_user_ids'] ?? [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, int> $invitedUserIds
     */
    private function syncInvitations(Event $event, int $householdId, array $invitedUserIds): void
    {
        if (count($invitedUserIds) === 0) {
            $event->invitations()->delete();
            return;
        }

        $event->invitations()
            ->where('household_id', $householdId)
            ->whereNotIn('user_id', $invitedUserIds)
            ->delete();

        $now = now();
        $existingUserIds = $event->invitations()
            ->where('household_id', $householdId)
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $rows = collect($invitedUserIds)
            ->reject(static fn (int $id): bool => in_array($id, $existingUserIds, true))
            ->map(static fn (int $userId) => [
                'household_id' => $householdId,
                'event_id' => (int) $event->id,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if (count($rows) > 0) {
            $event->invitations()->insert($rows);
        }
    }
}

