<?php

namespace App\Actions\Calendar;

use App\Events\Calendar\EventParticipationConfirmedEvent;
use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\Household;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ConfirmEventParticipationAction
{
    /**
     * @param array<string, mixed> $validated
     */
    public function execute(Household $household, User $actor, Event $event, array $validated): EventParticipation
    {
        if (!(bool) ($event->response_required ?? true)) {
            throw ValidationException::withMessages([
                'status' => ['Cet événement est en mode information. Aucune réponse n est attendue.'],
            ]);
        }

        $audienceMode = Event::normalizeAudienceMode((string) $event->audience_mode);
        $isInvited = $audienceMode === Event::AUDIENCE_ALL_MEMBERS
            || $event->invitations()
                ->where('household_id', (int) $household->id)
                ->where('user_id', (int) $actor->id)
                ->exists();
        if (!$isInvited) {
            throw ValidationException::withMessages([
                'status' => ['Tu ne peux pas répondre à cet événement.'],
            ]);
        }

        $status = (string) $validated['status'];
        $reason = trim((string) ($validated['reason'] ?? ''));
        if ($status === 'participate') {
            $reason = '';
        }

        $participation = EventParticipation::query()->updateOrCreate(
            [
                'household_id' => $household->id,
                'event_id' => $event->id,
                'user_id' => $actor->id,
            ],
            [
                'status' => $status,
                'reason' => $reason !== '' ? $reason : null,
                'responded_at' => now(),
            ]
        );

        event(new EventParticipationConfirmedEvent(
            participation: $participation,
            event: $event,
            householdId: (int) $household->id,
            actorUserId: (int) $actor->id,
            actorName: (string) ($actor->name ?? 'Un membre'),
        ));

        return $participation;
    }
}
