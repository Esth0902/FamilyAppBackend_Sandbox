<?php

namespace App\Listeners\Calendar;

use App\Events\Calendar\CalendarEventUpdatedEvent;
use App\Listeners\Calendar\Concerns\InteractsWithCalendarAudience;
use App\Models\Event;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleCalendarEventUpdatedEffects implements ShouldQueue
{
    use InteractsWithCalendarAudience;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(CalendarEventUpdatedEvent $event): void
    {
        $calendarEvent = $event->event;
        $audienceMode = Event::normalizeAudienceMode((string) $calendarEvent->audience_mode);
        $currentAudienceUserIds = array_values(array_unique($event->currentAudienceUserIds));
        $previousAudienceUserIds = array_values(array_unique($event->previousAudienceUserIds));

        $currentRecipients = array_values(array_filter(
            $currentAudienceUserIds,
            static fn (int $id): bool => $id !== $event->actorUserId
        ));
        if (!empty($currentRecipients)) {
            $this->notificationService->notifyUsers(
                userIds: $currentRecipients,
                householdId: $event->householdId,
                type: 'calendar_event_updated',
                title: 'Événement modifié',
                body: sprintf('L\'événement "%s" a été modifié.', (string) $calendarEvent->title),
                data: [
                    'event_id' => (int) $calendarEvent->id,
                    'event_title' => (string) $calendarEvent->title,
                    'change' => 'updated',
                    'actor_user_id' => $event->actorUserId,
                    'actor_name' => $event->actorName,
                ],
            );
        }

        $removedRecipients = array_values(array_filter(
            $previousAudienceUserIds,
            fn (int $id): bool => !in_array($id, $currentAudienceUserIds, true) && $id !== $event->actorUserId
        ));
        if (!empty($removedRecipients)) {
            $this->notificationService->notifyUsers(
                userIds: $removedRecipients,
                householdId: $event->householdId,
                type: 'calendar_event_deleted',
                title: 'Événement retiré',
                body: sprintf('L\'événement "%s" n\'est plus visible dans ton calendrier.', (string) $calendarEvent->title),
                data: [
                    'event_id' => (int) $calendarEvent->id,
                    'event_title' => (string) $calendarEvent->title,
                    'change' => 'deleted',
                    'actor_user_id' => $event->actorUserId,
                    'actor_name' => $event->actorName,
                ],
            );
        }

        $payload = [
            'event_id' => (int) $calendarEvent->id,
            'title' => (string) $calendarEvent->title,
            'start_at' => optional($calendarEvent->start_at)->toIso8601String(),
            'end_at' => optional($calendarEvent->end_at)->toIso8601String(),
            'is_shared_with_other_household' => (bool) $calendarEvent->is_shared_with_other_household,
            'household_id' => $event->householdId,
            'audience_mode' => $audienceMode,
            'response_required' => (bool) ($calendarEvent->response_required ?? true),
        ];

        if ($audienceMode === Event::AUDIENCE_ALL_MEMBERS) {
            $this->realtimePublisher->publishHousehold(
                householdId: $event->householdId,
                module: 'calendar',
                type: 'event.updated',
                payload: $payload,
            );
        } else {
            foreach ($currentAudienceUserIds as $userId) {
                $this->realtimePublisher->publishUser(
                    userId: $userId,
                    module: 'calendar',
                    type: 'event.updated',
                    payload: $payload + ['user_id' => $userId],
                );
            }
            foreach ($removedRecipients as $userId) {
                $this->realtimePublisher->publishUser(
                    userId: $userId,
                    module: 'calendar',
                    type: 'event.deleted',
                    payload: [
                        'event_id' => (int) $calendarEvent->id,
                        'title' => (string) $calendarEvent->title,
                        'household_id' => $event->householdId,
                        'user_id' => $userId,
                    ],
                );
            }
        }

        if ($event->linkedHouseholdId === null) {
            return;
        }

        if (!$event->wasSharedWithOtherHousehold && $event->isSharedWithOtherHousehold) {
            $this->realtimePublisher->publishHousehold(
                householdId: $event->linkedHouseholdId,
                module: 'calendar',
                type: 'event.created',
                payload: $payload + ['household_id' => $event->linkedHouseholdId],
            );
            return;
        }

        if ($event->wasSharedWithOtherHousehold && !$event->isSharedWithOtherHousehold) {
            $this->realtimePublisher->publishHousehold(
                householdId: $event->linkedHouseholdId,
                module: 'calendar',
                type: 'event.deleted',
                payload: [
                    'event_id' => (int) $calendarEvent->id,
                    'title' => (string) $calendarEvent->title,
                    'household_id' => $event->linkedHouseholdId,
                ],
            );
            return;
        }

        if ($event->wasSharedWithOtherHousehold && $event->isSharedWithOtherHousehold) {
            $this->realtimePublisher->publishHousehold(
                householdId: $event->linkedHouseholdId,
                module: 'calendar',
                type: 'event.updated',
                payload: $payload + ['household_id' => $event->linkedHouseholdId],
            );
        }
    }
}

