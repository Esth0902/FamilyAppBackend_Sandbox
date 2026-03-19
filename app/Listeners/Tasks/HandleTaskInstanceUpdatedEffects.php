<?php

namespace App\Listeners\Tasks;

use App\Events\Tasks\TaskInstanceUpdatedEvent;
use App\Models\Household;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;
use App\Support\Normalization;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleTaskInstanceUpdatedEffects implements ShouldQueue
{
    private const STATUS_DONE = "r\u{00E9}alis\u{00E9}e";
    private const STATUS_CANCELLED = "annul\u{00E9}e";

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(TaskInstanceUpdatedEvent $event): void
    {
        $instance = $event->instance->loadMissing('template:id,name');
        $taskTitle = (string) ($instance->template?->name ?? 'Tâche');
        $statusNow = (string) $instance->status;

        $sharedPayload = [
            'household_id' => $event->householdId,
            'household_name' => $event->householdName,
            'task_instance_id' => (int) $instance->id,
            'task_template_id' => (int) $instance->task_template_id,
            'task_name' => $taskTitle,
            'due_date' => optional($instance->due_date)->toDateString(),
            'actor_user_id' => $event->actorUserId,
            'actor_name' => $event->actorName,
        ];

        if ($event->previousStatus !== self::STATUS_DONE && $statusNow === self::STATUS_DONE) {
            $this->notificationService->notifyUsers(
                userIds: array_values(array_filter(
                    $this->resolveParentUserIds($event->householdId),
                    fn(int $id): bool => $id !== $event->actorUserId
                )),
                householdId: $event->householdId,
                type: 'task_done_validation_needed',
                title: 'Validation de tâche requise',
                body: sprintf('La tâche "%s" a été marquée réalisée dans le foyer %s.', $taskTitle, $event->householdName),
                data: $sharedPayload,
            );
        }

        if (!$event->previousValidatedByParent && (bool) $instance->validated_by_parent) {
            $this->notificationService->notifyUsers(
                userIds: $event->currentAssigneeIds,
                householdId: $event->householdId,
                type: 'task_validated',
                title: 'Tâche validée',
                body: sprintf('La tâche "%s" a été validée par un parent dans le foyer %s.', $taskTitle, $event->householdName),
                data: $sharedPayload,
            );
        }

        if ($event->previousStatus !== self::STATUS_CANCELLED && $statusNow === self::STATUS_CANCELLED) {
            $this->notificationService->notifyUsers(
                userIds: $event->currentAssigneeIds,
                householdId: $event->householdId,
                type: 'task_cancelled',
                title: 'Tâche annulée',
                body: sprintf('La tâche "%s" a été annulée dans le foyer %s.', $taskTitle, $event->householdName),
                data: $sharedPayload,
            );
        }

        if (!$this->memberIdsEquals($event->previousAssigneeIds, $event->currentAssigneeIds)) {
            $this->notificationService->notifyUsers(
                userIds: array_values(array_filter(
                    $event->currentAssigneeIds,
                    fn(int $id): bool => $id !== $event->actorUserId
                )),
                householdId: $event->householdId,
                type: 'task_reassigned',
                title: 'Réattribution de tâche',
                body: sprintf('La tâche "%s" vous a été réattribuée dans le foyer %s.', $taskTitle, $event->householdName),
                data: $sharedPayload + [
                    'previous_assignee_ids' => $event->previousAssigneeIds,
                    'assignee_ids' => $event->currentAssigneeIds,
                ],
            );
        }

        $allMemberIdsExceptActor = $this->resolveHouseholdMemberIds($event->householdId, $event->actorUserId);
        $isDeletion = $event->previousStatus !== self::STATUS_CANCELLED && $statusNow === self::STATUS_CANCELLED;
        $this->notificationService->notifyUsers(
            userIds: $allMemberIdsExceptActor,
            householdId: $event->householdId,
            type: $isDeletion ? 'calendar_task_deleted' : 'calendar_task_updated',
            title: $isDeletion ? 'Tâche supprimée du calendrier' : 'Tâche modifiée',
            body: $isDeletion
                ? sprintf('La tâche "%s" a été supprimée du calendrier du foyer %s.', $taskTitle, $event->householdName)
                : sprintf('La tâche "%s" a été modifiée dans le calendrier du foyer %s.', $taskTitle, $event->householdName),
            data: $sharedPayload + ['change' => $isDeletion ? 'deleted' : 'updated'],
        );

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'tasks',
            type: 'instance.updated',
            payload: $sharedPayload + [
                'status' => $statusNow,
                'validated_by_parent' => (bool) $instance->validated_by_parent,
                'assignee_ids' => $event->currentAssigneeIds,
                'household_id' => $event->householdId,
            ],
        );
    }

    /**
     * @return array<int, int>
     */
    private function resolveParentUserIds(int $householdId): array
    {
        $household = Household::query()->find($householdId);
        if (!$household) {
            return [];
        }

        return $household->users()
            ->wherePivot('role', User::ROLE_PARENT)
            ->pluck('users.id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0)
            ->values()
            ->all();
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

    /**
     * @param  array<int, int>  $left
     * @param  array<int, int>  $right
     */
    private function memberIdsEquals(array $left, array $right): bool
    {
        $leftNormalized = Normalization::memberIds($left);
        $rightNormalized = Normalization::memberIds($right);
        sort($leftNormalized);
        sort($rightNormalized);

        return $leftNormalized === $rightNormalized;
    }
}
