<?php

namespace App\Listeners\Tasks;

use App\Events\Tasks\TaskInstanceCreatedEvent;
use App\Models\Household;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;

class HandleTaskInstanceCreatedEffects
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(TaskInstanceCreatedEvent $event): void
    {
        $instance = $event->instance->loadMissing('template:id,name');
        $taskTitle = (string) ($instance->template?->name ?? 'Tâche');
        $householdName = $this->resolveHouseholdName($event->householdId);

        $notificationPayload = [
            'household_id' => $event->householdId,
            'household_name' => $householdName,
            'task_instance_id' => (int) $instance->id,
            'task_template_id' => (int) $instance->task_template_id,
            'task_name' => $taskTitle,
            'due_date' => optional($instance->due_date)->toDateString(),
            'assigned_by_user_id' => $event->actorUserId,
            'assigned_by_name' => $event->actorName,
        ];

        $this->notificationService->notifyUsers(
            userIds: array_values(array_filter($event->assigneeIds, fn(int $id): bool => $id !== $event->actorUserId)),
            householdId: $event->householdId,
            type: 'task_assigned',
            title: 'Nouvelle tâche assignée',
            body: sprintf('La tâche "%s" vous a été assignée dans le foyer %s.', $taskTitle, $householdName),
            data: $notificationPayload,
        );

        $allMemberIdsExceptActor = $this->resolveHouseholdMemberIds($event->householdId, $event->actorUserId);
        $this->notificationService->notifyUsers(
            userIds: $allMemberIdsExceptActor,
            householdId: $event->householdId,
            type: 'calendar_task_added',
            title: 'Tâche ajoutée au calendrier',
            body: sprintf('La tâche "%s" a été ajoutée dans le calendrier du foyer %s.', $taskTitle, $householdName),
            data: $notificationPayload + ['change' => 'added'],
        );

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'tasks',
            type: 'instance.upserted',
            payload: [
                'task_instance_id' => (int) $instance->id,
                'task_template_id' => (int) $instance->task_template_id,
                'task_name' => $taskTitle,
                'due_date' => optional($instance->due_date)->toDateString(),
                'assignee_ids' => $event->assigneeIds,
                'instance_ids' => $event->instanceIds,
                'household_id' => $event->householdId,
            ],
        );
    }

    private function resolveHouseholdName(int $householdId): string
    {
        $household = Household::query()->find($householdId);

        return (string) ($household?->name ?? 'ce foyer');
    }

    /**
     * @return array<int, int>
     */
    private function resolveHouseholdMemberIds(int $householdId, int $excludeUserId): array
    {
        $household = Household::query()->find($householdId);
        if (!$household) {
            return [];
        }

        return $household->users()
            ->pluck('users.id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0 && $id !== $excludeUserId)
            ->values()
            ->all();
    }
}
