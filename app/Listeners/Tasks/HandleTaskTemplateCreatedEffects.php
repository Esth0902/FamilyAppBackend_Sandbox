<?php

namespace App\Listeners\Tasks;

use App\Events\Tasks\TaskTemplateCreatedEvent;
use App\Models\Household;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;
use App\Support\Normalization;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleTaskTemplateCreatedEffects implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(TaskTemplateCreatedEvent $event): void
    {
        $template = $event->template->loadMissing('fixedUser:id,name');
        $householdName = $this->resolveHouseholdName($event->householdId);

        $isRotation = (bool) $template->is_rotation;
        $fixedUserId = $template->fixed_user_id ? (int) $template->fixed_user_id : null;
        $assigneeUserIds = Normalization::memberIds($template->assignee_user_ids);
        $rotationUserIds = Normalization::memberIds($template->rotation_user_ids);
        $routineAssigneeIds = $isRotation ? $rotationUserIds : $assigneeUserIds;
        if (count($routineAssigneeIds) === 0 && $fixedUserId !== null) {
            $routineAssigneeIds = [$fixedUserId];
        }

        $this->notificationService->notifyUsers(
            userIds: array_values(array_filter(
                $routineAssigneeIds,
                fn(int $id): bool => $id !== $event->actorUserId
            )),
            householdId: $event->householdId,
            type: 'task_routine_assigned',
            title: 'Nouvelle routine attribuée',
            body: sprintf(
                'La routine "%s" t\'a été attribuée dans le foyer %s.',
                (string) $template->name,
                $householdName
            ),
            data: [
                'household_id' => $event->householdId,
                'household_name' => $householdName,
                'task_template_id' => (int) $template->id,
                'task_name' => (string) $template->name,
                'recurrence' => (string) $template->recurrence,
                'assigned_by_user_id' => $event->actorUserId,
                'assigned_by_name' => $event->actorName,
                'assignee_ids' => $routineAssigneeIds,
            ],
        );

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'tasks',
            type: 'template.created',
            payload: [
                'template_id' => (int) $template->id,
                'name' => (string) $template->name,
                'recurrence' => (string) $template->recurrence,
                'household_id' => $event->householdId,
            ],
        );
    }

    private function resolveHouseholdName(int $householdId): string
    {
        $household = Household::query()->find($householdId);

        return (string) ($household?->name ?? 'ce foyer');
    }
}
