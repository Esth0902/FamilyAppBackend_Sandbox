<?php

namespace App\Listeners\Calendar;

use App\Events\Calendar\CalendarEventDeletedEvent;
use App\Listeners\Calendar\Concerns\InteractsWithCalendarAudience;
use App\Models\Event;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleCalendarEventDeletedEffects implements ShouldQueue
{
    use InteractsWithCalendarAudience;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(CalendarEventDeletedEvent $event): void
    {
        $audienceUserIds = array_values(array_unique($event->audienceUserIds));
        $memberIds = array_values(array_filter(
            $audienceUserIds,
            static fn (int $id): bool => $id !== $event->actorUserId
        ));
        if (!empty($memberIds)) {
            $this->notificationService->notifyUsers(
                userIds: $memberIds,
                householdId: $event->householdId,
                type: 'calendar_event_deleted',
                title: 'Evenement supprime',
                body: sprintf('L evenement "%s" a ete supprime du calendrier.', $event->eventTitle),
                data: [
                    'event_id' => $event->eventId,
                    'event_title' => $event->eventTitle,
                    'change' => 'deleted',
                    'actor_user_id' => $event->actorUserId,
                    'actor_name' => $event->actorName,
                ],
            );
        }

        $payload = [
            'event_id' => $event->eventId,
            'title' => $event->eventTitle,
            'household_id' => $event->householdId,
        ];

        if ($event->audienceMode === Event::AUDIENCE_ALL_MEMBERS) {
            $this->realtimePublisher->publishHousehold(
                householdId: $event->householdId,
                module: 'calendar',
                type: 'event.deleted',
                payload: $payload,
            );
        } else {
            foreach ($audienceUserIds as $userId) {
                $this->realtimePublisher->publishUser(
                    userId: $userId,
                    module: 'calendar',
                    type: 'event.deleted',
                    payload: $payload + ['user_id' => $userId],
                );
            }
        }

        if ($event->wasSharedWithOtherHousehold && $event->linkedHouseholdId !== null) {
            $this->realtimePublisher->publishHousehold(
                householdId: $event->linkedHouseholdId,
                module: 'calendar',
                type: 'event.deleted',
                payload: $payload + ['household_id' => $event->linkedHouseholdId],
            );
        }
    }
}

