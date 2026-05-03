<?php

namespace App\Actions\Calendar;

use App\Actions\Calendar\Concerns\ResolvesLinkedHousehold;
use App\Events\Calendar\CalendarEventUpdatedEvent;
use App\Models\Event;
use App\Models\Household;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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

        $audienceMode = array_key_exists('audience_mode', $validated)
            ? Event::normalizeAudienceMode((string) $validated['audience_mode'])
            : Event::normalizeAudienceMode((string) $event->audience_mode);
        $responseRequired = array_key_exists('response_required', $validated)
            ? (bool) $validated['response_required']
            : (bool) ($event->response_required ?? true);
        $invitedUserIds = $this->resolveInvitedUserIds($audienceMode, $actor, $event, $validated);
        $previousAudienceUserIds = $this->resolveAudienceUserIds($event, $household);

        DB::transaction(function () use (
            $event,
            $validated,
            $shouldShare,
            $audienceMode,
            $responseRequired,
            $invitedUserIds,
            $household
        ): void {
            $event->update([
                'title' => trim((string) $validated['title']),
                'description' => $validated['description'] ?? null,
                'start_at' => Carbon::parse((string) $validated['start_at']),
                'end_at' => Carbon::parse((string) $validated['end_at']),
                'is_shared_with_other_household' => $shouldShare,
                'audience_mode' => $audienceMode,
                'response_required' => $responseRequired,
            ]);

            $this->syncInvitations($event, (int) $household->id, $invitedUserIds);
            $this->pruneParticipationsOutsideAudience($event, $audienceMode, $invitedUserIds, (int) $household->id);
        });

        $event->load([
            'creator:id,name',
            'invitations:id,event_id,household_id,user_id',
        ]);

        $currentAudienceUserIds = $this->resolveAudienceUserIds($event, $household);

        event(new CalendarEventUpdatedEvent(
            event: $event,
            householdId: (int) $household->id,
            actorUserId: (int) $actor->id,
            actorName: (string) ($actor->name ?? 'Un membre'),
            linkedHouseholdId: $linkedHouseholdId,
            wasSharedWithOtherHousehold: $wasSharedWithOtherHousehold,
            isSharedWithOtherHousehold: (bool) $event->is_shared_with_other_household,
            previousAudienceUserIds: $previousAudienceUserIds,
            currentAudienceUserIds: $currentAudienceUserIds,
        ));

        return $event;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<int, int>
     */
    private function resolveInvitedUserIds(string $audienceMode, User $actor, Event $event, array $validated): array
    {
        if ($audienceMode === Event::AUDIENCE_ALL_MEMBERS) {
            return [];
        }

        if ($audienceMode === Event::AUDIENCE_ONLY_ME) {
            return [(int) $actor->id];
        }

        if (!array_key_exists('invited_user_ids', $validated)) {
            return $event->invitations()
                ->where('household_id', (int) $event->household_id)
                ->pluck('user_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        return collect($validated['invited_user_ids'] ?? [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function resolveAudienceUserIds(Event $event, Household $household): array
    {
        $audienceMode = Event::normalizeAudienceMode((string) $event->audience_mode);
        if ($audienceMode === Event::AUDIENCE_ALL_MEMBERS) {
            return $household->users()
                ->pluck('users.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();
        }

        return $event->invitations()
            ->where('household_id', (int) $household->id)
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
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

    /**
     * @param array<int, int> $invitedUserIds
     */
    private function pruneParticipationsOutsideAudience(
        Event $event,
        string $audienceMode,
        array $invitedUserIds,
        int $householdId
    ): void {
        if ($audienceMode === Event::AUDIENCE_ALL_MEMBERS) {
            return;
        }

        if (count($invitedUserIds) === 0) {
            $event->participations()
                ->where('household_id', $householdId)
                ->delete();
            return;
        }

        $event->participations()
            ->where('household_id', $householdId)
            ->whereNotIn('user_id', $invitedUserIds)
            ->delete();
    }
}
