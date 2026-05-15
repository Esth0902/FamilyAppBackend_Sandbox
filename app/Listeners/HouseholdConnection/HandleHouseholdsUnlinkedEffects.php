<?php

namespace App\Listeners\HouseholdConnection;

use App\Events\HouseholdConnection\HouseholdsUnlinkedEvent;
use App\Services\NotificationDispatchService;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleHouseholdsUnlinkedEffects implements ShouldQueue
{
    public function __construct(
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(HouseholdsUnlinkedEvent $event): void
    {
        if ($event->linkedHouseholdId !== null && $event->linkedHouseholdName !== null) {
            $targetParentIds = $this->notificationDispatchService->resolveParentUserIds($event->linkedHouseholdId);
            foreach ($targetParentIds as $parentId) {
                $notification = $this->notificationDispatchService->createUserNotification(
                    userId: (int) $parentId,
                    householdId: $event->linkedHouseholdId,
                    type: 'household_link_disconnected',
                    title: 'Liaison de foyer rompue',
                    body: sprintf(
                        'Le foyer %s a rompu la liaison avec ton foyer.',
                        $event->sourceHouseholdName
                    ),
                    data: [
                        'source_household_id' => $event->sourceHouseholdId,
                        'source_household_name' => $event->sourceHouseholdName,
                        'target_household_id' => $event->linkedHouseholdId,
                        'target_household_name' => $event->linkedHouseholdName,
                        'triggered_by_user_id' => $event->actorUserId,
                        'triggered_by_user_name' => $event->actorUserName,
                        'household_name' => $event->linkedHouseholdName,
                    ],
                );

                $this->notificationDispatchService->publishNotificationCreated($notification);
            }
        }

        $this->realtimePublisher->publishHousehold(
            householdId: $event->sourceHouseholdId,
            module: 'household',
            type: 'connection_updated',
            payload: [
                'household_id' => $event->sourceHouseholdId,
                'linked_household_id' => null,
                'status' => 'disconnected',
            ],
        );

        if ($event->linkedHouseholdId !== null) {
            $this->realtimePublisher->publishHousehold(
                householdId: $event->linkedHouseholdId,
                module: 'household',
                type: 'connection_updated',
                payload: [
                    'household_id' => $event->linkedHouseholdId,
                    'linked_household_id' => null,
                    'status' => 'disconnected',
                ],
            );
        }
    }
}

