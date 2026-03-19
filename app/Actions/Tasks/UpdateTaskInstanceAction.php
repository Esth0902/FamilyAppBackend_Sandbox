<?php

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskInstanceUpdatedEvent;
use App\Http\Controllers\Api\Concerns\InteractsWithTaskContext;
use App\Models\Household;
use App\Models\TaskInstance;
use App\Models\User;
use App\Support\Normalization;
use Illuminate\Validation\ValidationException;

class UpdateTaskInstanceAction
{
    use InteractsWithTaskContext;

    private const STATUS_TODO = "\u{00E0} faire";
    private const STATUS_DONE = "r\u{00E9}alis\u{00E9}e";
    private const STATUS_CANCELLED = "annul\u{00E9}e";

    public function __construct(private readonly ToggleTaskStatusAction $toggleTaskStatusAction)
    {
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(
        Household $household,
        string $role,
        User $actor,
        TaskInstance $instance,
        array $validated
    ): TaskInstance {
        $this->ensureTasksModuleEnabled($household);
        $this->ensureInstanceBelongsToHousehold($instance, $household);

        $instance->loadMissing([
            'assignees:id,name',
            'template:id,household_id,name',
        ]);

        $previousStatus = (string) $instance->status;
        $previousValidatedByParent = (bool) $instance->validated_by_parent;
        $previousAssigneeIds = Normalization::memberIds(
            $instance->assignees
                ->map(static fn(User $assignee): int => (int) $assignee->id)
                ->values()
                ->all()
        );
        if (count($previousAssigneeIds) === 0 && (int) $instance->user_id > 0) {
            $previousAssigneeIds = [(int) $instance->user_id];
        }

        $isParent = $role === User::ROLE_PARENT;
        $currentUserId = (int) $actor->id;

        if (!$isParent) {
            if (!$this->isUserAssignedToInstance($instance, $currentUserId)) {
                abort(403, 'Vous pouvez modifier uniquement vos tâches.');
            }

            if (
                array_key_exists('user_id', $validated)
                || array_key_exists('user_ids', $validated)
                || array_key_exists('due_date', $validated)
                || array_key_exists('validated_by_parent', $validated)
            ) {
                abort(403, 'Action réservée aux parents.');
            }

            if (
                array_key_exists('status', $validated)
                && !in_array((string) $validated['status'], [self::STATUS_TODO, self::STATUS_DONE], true)
            ) {
                abort(403, 'Statut non autorisé.');
            }
        }

        $updates = [];
        $nextAssigneeIds = null;

        if (array_key_exists('due_date', $validated)) {
            $updates['due_date'] = (string) $validated['due_date'];
        }

        if (array_key_exists('user_id', $validated) || array_key_exists('user_ids', $validated)) {
            $requestedIds = Normalization::memberIds($validated['user_ids'] ?? null);
            if (array_key_exists('user_id', $validated) && !empty($validated['user_id'])) {
                $requestedIds[] = (int) $validated['user_id'];
            }
            $requestedIds = Normalization::memberIds($requestedIds);
            $requestedIds = $this->ensureUsersBelongToHousehold($requestedIds, $household, 'user_ids');
            if (count($requestedIds) === 0) {
                throw ValidationException::withMessages([
                    'user_ids' => ['Sélectionnez au moins un membre à assigner.'],
                ]);
            }

            $nextAssigneeIds = $requestedIds;
            $updates['user_id'] = $this->resolvePrimaryAssigneeId($requestedIds);
        }

        if (array_key_exists('status', $validated)) {
            $instance = $this->toggleTaskStatusAction->execute(
                instance: $instance,
                status: (string) $validated['status'],
            );
        }

        if (array_key_exists('validated_by_parent', $validated)) {
            $shouldValidate = (bool) $validated['validated_by_parent'];
            if ($shouldValidate && (string) $instance->status !== self::STATUS_DONE) {
                throw ValidationException::withMessages([
                    'validated_by_parent' => ['Seule une tâche réalisée peut être validée.'],
                ]);
            }
            $updates['validated_by_parent'] = $shouldValidate;
        }

        if (count($updates) > 0) {
            $instance->update($updates);
        }

        if (is_array($nextAssigneeIds)) {
            $this->syncInstanceAssignees($instance, $nextAssigneeIds);
        }

        $instance->load([
            'template:id,household_id,name,description,recurrence,start_date,end_date,recurrence_days,assignee_user_ids,rotation_user_ids,is_rotation,rotation_cycle_weeks,is_inter_household_alternating,inter_household_week_start,fixed_user_id',
            'user:id,name',
            'assignees:id,name',
        ]);

        $currentAssigneeIds = Normalization::memberIds(
            $instance->assignees
                ->map(static fn(User $assignee): int => (int) $assignee->id)
                ->values()
                ->all()
        );
        if (count($currentAssigneeIds) === 0 && (int) $instance->user_id > 0) {
            $currentAssigneeIds = [(int) $instance->user_id];
        }

        event(new TaskInstanceUpdatedEvent(
            instance: $instance,
            householdId: (int) $household->id,
            householdName: (string) ($household->name ?? 'ce foyer'),
            actorUserId: $currentUserId,
            actorName: (string) ($actor->name ?? 'Un membre'),
            previousStatus: $previousStatus,
            previousValidatedByParent: $previousValidatedByParent,
            previousAssigneeIds: $previousAssigneeIds,
            currentAssigneeIds: $currentAssigneeIds,
        ));

        return $instance;
    }

    private function resolvePrimaryAssigneeId(array $assigneeIds): int
    {
        return (int) (Normalization::memberIds($assigneeIds)[0] ?? 0);
    }

    /**
     * @param  array<int, int>  $assigneeIds
     */
    private function syncInstanceAssignees(TaskInstance $instance, array $assigneeIds): void
    {
        $normalized = Normalization::memberIds($assigneeIds);
        if (count($normalized) === 0) {
            $fallbackUserId = (int) $instance->user_id;
            if ($fallbackUserId > 0) {
                $normalized = [$fallbackUserId];
            }
        }

        if (count($normalized) === 0) {
            return;
        }

        $instance->assignees()->sync($normalized);

        $primaryAssigneeId = $this->resolvePrimaryAssigneeId($normalized);
        if ($primaryAssigneeId > 0 && (int) $instance->user_id !== $primaryAssigneeId) {
            $instance->update(['user_id' => $primaryAssigneeId]);
        }

        $instance->unsetRelation('assignees');
    }
}
