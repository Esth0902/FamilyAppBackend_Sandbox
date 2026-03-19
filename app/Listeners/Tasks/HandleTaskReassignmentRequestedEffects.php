<?php

namespace App\Listeners\Tasks;

use App\Events\Tasks\TaskReassignmentRequestedEvent;
use App\Services\RealtimePublisher;

class HandleTaskReassignmentRequestedEffects
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function handle(TaskReassignmentRequestedEvent $event): void
    {
        $notification = $event->invitationNotification;

        $this->realtimePublisher->publishUser(
            userId: $event->invitedUserId,
            module: 'notifications',
            type: 'task_reassignment_invite_created',
            payload: [
                'notification_id' => (int) $notification->id,
                'household_id' => $event->householdId,
                'household_name' => (string) data_get($notification->data, 'household_name', 'ce foyer'),
                'task_instance_id' => (int) data_get($notification->data, 'task_instance_id'),
                'task_name' => (string) data_get($notification->data, 'task_name', 'Tâche'),
                'requester_user_id' => (int) data_get($notification->data, 'requester_user_id'),
                'requester_name' => (string) data_get($notification->data, 'requester_name', 'Membre'),
            ],
        );

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'tasks',
            type: 'instance.reassignment_requested',
            payload: [
                'task_instance_id' => (int) data_get($notification->data, 'task_instance_id'),
                'task_name' => (string) data_get($notification->data, 'task_name', 'Tâche'),
                'requester_user_id' => (int) data_get($notification->data, 'requester_user_id'),
                'invited_user_id' => $event->invitedUserId,
                'notification_id' => (int) $notification->id,
                'household_id' => $event->householdId,
            ],
        );
    }
}
