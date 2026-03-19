<?php

namespace App\Listeners\Tasks;

use App\Events\Tasks\TaskInstanceValidatedEvent;
use App\Models\Household;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleTaskInstanceValidatedEffects implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(TaskInstanceValidatedEvent $event): void
    {
        $instance = $event->instance->loadMissing('template:id,name');
        $taskTitle = (string) ($instance->template?->name ?? 'Tâche');
        $householdName = $this->resolveHouseholdName($event->householdId);

        $payload = [
            'household_id' => $event->householdId,
            'household_name' => $householdName,
            'task_instance_id' => (int) $instance->id,
            'task_template_id' => (int) $instance->task_template_id,
            'task_name' => $taskTitle,
            'due_date' => optional($instance->due_date)->toDateString(),
            'validated_by_user_id' => $event->validatedByUserId,
            'validated_by_name' => $event->validatedByName,
        ];

        $this->notificationService->notifyUsers(
            userIds: $event->assigneeIds,
            householdId: $event->householdId,
            type: 'task_validated',
            title: 'Tâche validée',
            body: sprintf('La tâche "%s" a été validée dans le foyer %s.', $taskTitle, $householdName),
            data: $payload,
        );

        $allMemberIdsExceptActor = $this->resolveHouseholdMemberIds($event->householdId, $event->validatedByUserId);
        $this->notificationService->notifyUsers(
            userIds: $allMemberIdsExceptActor,
            householdId: $event->householdId,
            type: 'calendar_task_updated',
            title: 'Tâche modifiée',
            body: sprintf('La tâche "%s" a été modifiée dans le calendrier du foyer %s.', $taskTitle, $householdName),
            data: $payload + ['change' => 'updated'],
        );

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'tasks',
            type: 'instance.validated',
            payload: $payload + [
                'assignee_ids' => $event->assigneeIds,
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
