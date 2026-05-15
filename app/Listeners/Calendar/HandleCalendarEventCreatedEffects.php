<?php

namespace App\Listeners\Calendar;

use App\Events\Calendar\CalendarEventCreatedEvent;
use App\Listeners\Calendar\Concerns\InteractsWithCalendarAudience;
use App\Models\Event;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleCalendarEventCreatedEffects implements ShouldQueue
{
    use InteractsWithCalendarAudience;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(CalendarEventCreatedEvent $event): void
    {
        $calendarEvent = $event->event;
        $audienceMode = Event::normalizeAudienceMode((string) $calendarEvent->audience_mode);
        $audienceUserIds = $this->resolveEventAudienceUserIds($calendarEvent, $event->householdId);

        $memberIds = array_values(array_filter(
            $audienceUserIds,
            static fn (int $id): bool => $id !== $event->actorUserId
        ));
        if (!empty($memberIds)) {
            $this->notificationService->notifyUsers(
                userIds: $memberIds,
                householdId: $event->householdId,
                type: 'calendar_event_added',
                title: 'Événement ajouté',
                body: sprintf('L\' événement "%s" a été ajouté au calendrier.', (string) $calendarEvent->title),
                data: [
                    'event_id' => (int) $calendarEvent->id,
                    'event_title' => (string) $calendarEvent->title,
                    'change' => 'added',
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
                type: 'event.created',
                payload: $payload,
            );
        } else {
            foreach ($audienceUserIds as $userId) {
                $this->realtimePublisher->publishUser(
                    userId: $userId,
                    module: 'calendar',
                    type: 'event.created',
                    payload: $payload + ['user_id' => $userId],
                );
            }
        }

        if ($event->linkedHouseholdId !== null) {
            $this->realtimePublisher->publishHousehold(
                householdId: $event->linkedHouseholdId,
                module: 'calendar',
                type: 'event.created',
                payload: $payload + ['household_id' => $event->linkedHouseholdId],
            );
        }
    }
}

