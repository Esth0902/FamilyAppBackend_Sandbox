<?php

namespace App\Listeners\HouseholdConnection;

use App\Events\HouseholdConnection\HouseholdLinkRequestedEvent;
use App\Services\NotificationDispatchService;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleHouseholdLinkRequestedEffects implements ShouldQueue
{
    public function __construct(
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(HouseholdLinkRequestedEvent $event): void
    {
        foreach ($event->targetParentIds as $parentId) {
            $notification = $this->notificationDispatchService->createUserNotification(
                userId: (int) $parentId,
                householdId: $event->targetHouseholdId,
                type: 'household_link_request',
                title: 'Demande de liaison de foyer',
                body: sprintf(
                    'Le foyer %s souhaite se connecter à ton foyer %s.',
                    $event->requesterHouseholdName,
                    $event->targetHouseholdName
                ),
                data: [
                    'status' => 'pending',
                    'link_request_id' => $event->linkRequestId,
                    'requester_household_id' => $event->requesterHouseholdId,
                    'requester_household_name' => $event->requesterHouseholdName,
                    'target_household_id' => $event->targetHouseholdId,
                    'target_household_name' => $event->targetHouseholdName,
                    'requested_by_user_id' => $event->requestedByUserId,
                    'requested_by_user_name' => $event->requestedByUserName,
                    'household_name' => $event->targetHouseholdName,
                ],
            );

            $this->notificationDispatchService->publishNotificationCreated($notification);
        }

        $payload = [
            'from_household_id' => $event->requesterHouseholdId,
            'to_household_id' => $event->targetHouseholdId,
            'status' => 'pending',
        ];

        $this->realtimePublisher->publishHousehold(
            householdId: $event->requesterHouseholdId,
            module: 'household',
            type: 'connection_request_created',
            payload: $payload,
        );

        $this->realtimePublisher->publishHousehold(
            householdId: $event->targetHouseholdId,
            module: 'household',
            type: 'connection_request_created',
            payload: $payload,
        );
    }
}

