<?php

namespace App\Listeners\Calendar;

use App\Events\Calendar\EventParticipationConfirmedEvent;
use App\Listeners\Calendar\Concerns\InteractsWithCalendarAudience;
use App\Models\Event;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleEventParticipationConfirmedEffects implements ShouldQueue
{
    use InteractsWithCalendarAudience;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(EventParticipationConfirmedEvent $event): void
    {
        $participation = $event->participation;
        $calendarEvent = $event->event;
        $audienceMode = Event::normalizeAudienceMode((string) $calendarEvent->audience_mode);
        $audienceUserIds = $this->resolveEventAudienceUserIds($calendarEvent, $event->householdId);

        $parentIds = $this->resolveParentUserIds($event->householdId, $event->actorUserId);
        $recipientParentIds = $this->intersectUserIds($parentIds, $audienceUserIds);
        if (!empty($recipientParentIds)) {
            $status = (string) $participation->status;
            $statusLabel = $status === 'participate' ? 'participe' : 'ne participe pas';
            $this->notificationService->notifyUsers(
                userIds: $recipientParentIds,
                householdId: $event->householdId,
                type: 'calendar_event_participation_updated',
                title: 'Participation événement mise à jour',
                body: sprintf(
                    '%s %s à l\'évènement "%s".',
                    $event->actorName,
                    $statusLabel,
                    (string) $calendarEvent->title,
                ),
                data: [
                    'event_id' => (int) $calendarEvent->id,
                    'event_title' => (string) $calendarEvent->title,
                    'status' => $status,
                    'reason' => $participation->reason,
                    'actor_user_id' => $event->actorUserId,
                    'actor_name' => $event->actorName,
                ],
            );
        }

        $payload = [
            'event_id' => (int) $calendarEvent->id,
            'user_id' => $event->actorUserId,
            'status' => (string) $participation->status,
            'household_id' => $event->householdId,
        ];

        if ($audienceMode === Event::AUDIENCE_ALL_MEMBERS) {
            $this->realtimePublisher->publishHousehold(
                householdId: $event->householdId,
                module: 'calendar',
                type: 'event.participation.updated',
                payload: $payload,
            );
        } else {
            foreach ($audienceUserIds as $userId) {
                $this->realtimePublisher->publishUser(
                    userId: $userId,
                    module: 'calendar',
                    type: 'event.participation.updated',
                    payload: $payload + ['user_id' => $userId],
                );
            }
        }
    }
}

