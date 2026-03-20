<?php

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskInstanceValidatedEvent;
use App\Models\Household;
use App\Models\TaskInstance;
use App\Models\User;
use App\Support\Normalization;
use Illuminate\Validation\ValidationException;

class ValidateTaskInstanceAction
{
    private const STATUS_DONE = "r\u{00E9}alis\u{00E9}e";

    public function execute(Household $household, User $actor, TaskInstance $instance): TaskInstance
    {
        if ((string) $instance->status !== self::STATUS_DONE) {
            throw ValidationException::withMessages([
                'status' => ['La tâche doit être réalisée avant validation.'],
            ]);
        }

        $instance->update([
            'validated_by_parent' => true,
        ]);

        $instance->load([
            'template:id,household_id,name,description,recurrence,start_date,end_date,recurrence_days,assignee_user_ids,rotation_user_ids,is_rotation,rotation_cycle_weeks,is_inter_household_alternating,inter_household_week_start,fixed_user_id',
            'user:id,name',
            'assignees:id,name',
        ]);

        $assigneeIds = Normalization::memberIds(
            $instance->assignees
                ->map(static fn(User $assignee): int => (int) $assignee->id)
                ->values()
                ->all(),
        );
        if (count($assigneeIds) === 0 && (int) $instance->user_id > 0) {
            $assigneeIds = [(int) $instance->user_id];
        }

        event(new TaskInstanceValidatedEvent(
            instance: $instance,
            householdId: (int) $household->id,
            validatedByUserId: (int) $actor->id,
            validatedByName: (string) ($actor->name ?? 'Parent'),
            assigneeIds: $assigneeIds,
        ));

        return $instance;
    }
}
