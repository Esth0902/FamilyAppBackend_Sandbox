<?php

namespace App\Listeners\HouseholdConnection;

use App\Events\HouseholdConnection\HouseholdLinkRespondedEvent;
use App\Services\NotificationDispatchService;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleHouseholdLinkRespondedEffects implements ShouldQueue
{
    public function __construct(
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(HouseholdLinkRespondedEvent $event): void
    {
        $requesterParentIds = $this->notificationDispatchService->resolveParentUserIds($event->fromHouseholdId);
        $accepted = $event->status === 'accepted';

        foreach ($requesterParentIds as $requesterParentId) {
            $notification = $this->notificationDispatchService->createUserNotification(
                userId: (int) $requesterParentId,
                householdId: $event->fromHouseholdId,
                type: 'household_link_request_responded',
                title: $accepted ? 'Liaison de foyer acceptee' : 'Liaison de foyer refusee',
                body: $accepted
                    ? sprintf(
                        'Le foyer %s a accepte votre demande de liaison.',
                        $event->toHouseholdName
                    )
                    : sprintf(
                        'Le foyer %s a refuse votre demande de liaison.',
                        $event->toHouseholdName
                    ),
                data: [
                    'status' => $event->status,
                    'link_request_id' => $event->linkRequestId,
                    'requester_household_id' => $event->fromHouseholdId,
                    'requester_household_name' => $event->fromHouseholdName,
                    'target_household_id' => $event->toHouseholdId,
                    'target_household_name' => $event->toHouseholdName,
                    'responded_by_user_id' => $event->respondedByUserId,
                    'responded_by_user_name' => $event->respondedByUserName,
                ],
            );

            $this->notificationDispatchService->publishNotificationCreated($notification);
        }

        $payload = [
            'from_household_id' => $event->fromHouseholdId,
            'to_household_id' => $event->toHouseholdId,
            'status' => $event->status,
        ];

        $this->realtimePublisher->publishHousehold(
            householdId: $event->fromHouseholdId,
            module: 'household',
            type: 'connection_updated',
            payload: $payload,
        );

        $this->realtimePublisher->publishHousehold(
            householdId: $event->toHouseholdId,
            module: 'household',
            type: 'connection_updated',
            payload: $payload,
        );
    }
}

