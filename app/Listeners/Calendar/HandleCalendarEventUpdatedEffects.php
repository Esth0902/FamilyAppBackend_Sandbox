<?php

namespace App\Listeners\Calendar;

use App\Events\Calendar\CalendarEventUpdatedEvent;
use App\Listeners\Calendar\Concerns\InteractsWithCalendarAudience;
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
        $memberIds = $this->resolveHouseholdMemberIds($event->householdId, $event->actorUserId);
        if (!empty($memberIds)) {
            $this->notificationService->notifyUsers(
                userIds: $memberIds,
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

        $payload = [
            'event_id' => (int) $calendarEvent->id,
            'title' => (string) $calendarEvent->title,
            'start_at' => optional($calendarEvent->start_at)->toIso8601String(),
            'end_at' => optional($calendarEvent->end_at)->toIso8601String(),
            'is_shared_with_other_household' => (bool) $calendarEvent->is_shared_with_other_household,
            'household_id' => $event->householdId,
        ];

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'calendar',
            type: 'event.updated',
            payload: $payload,
        );

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
