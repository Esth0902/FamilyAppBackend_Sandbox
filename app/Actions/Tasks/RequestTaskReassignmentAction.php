<?php

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskReassignmentRequestedEvent;
use App\Http\Controllers\Api\Concerns\InteractsWithTaskContext;
use App\Models\Household;
use App\Models\TaskInstance;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestTaskReassignmentAction
{
    use InteractsWithTaskContext;

    public function execute(
        Household $household,
        User $currentUser,
        TaskInstance $instance,
        int $invitedUserId,
    ): UserNotification {
        $currentUserId = (int) $currentUser->id;
        $invitedUserId = $this->ensureUserBelongsToHousehold($invitedUserId, $household);
        if ($invitedUserId === $currentUserId) {
            throw ValidationException::withMessages([
                'invited_user_id' => ['Sélectionnez un autre membre.'],
            ]);
        }

        $instance->loadMissing([
            'template:id,household_id,name',
            'assignees:id,name',
        ]);

        if ($this->isUserAssignedToInstance($instance, $invitedUserId)) {
            throw ValidationException::withMessages([
                'invited_user_id' => ['Ce membre est déjà assigné à cette tâche.'],
            ]);
        }

        $taskTitle = (string) ($instance->template?->name ?? 'Tâche');
        $householdName = (string) ($household->name ?? 'ce foyer');

        $invitationNotification = DB::transaction(function () use (
            $household,
            $instance,
            $currentUser,
            $currentUserId,
            $invitedUserId,
            $taskTitle,
            $householdName,
        ): UserNotification {
            $alreadyPending = UserNotification::query()
                ->where('user_id', $invitedUserId)
                ->where('type', 'task_reassignment_invite')
                ->where('data->status', 'pending')
                ->where('data->task_instance_id', (int) $instance->id)
                ->where('data->requester_user_id', $currentUserId)
                ->exists();

            if ($alreadyPending) {
                throw ValidationException::withMessages([
                    'invited_user_id' => ['Une demande est déjà en attente pour ce membre.'],
                ]);
            }

            return UserNotification::query()->create([
                'household_id' => $household->id,
                'user_id' => $invitedUserId,
                'type' => 'task_reassignment_invite',
                'title' => 'Demande de reprise de tâche',
                'body' => sprintf(
                    '%s te demande de reprendre la tâche %s prévue le %s (foyer : %s).',
                    (string) ($currentUser->name ?? 'Un membre'),
                    $taskTitle,
                    optional($instance->due_date)->toDateString() ?? '',
                    $householdName,
                ),
                'data' => [
                    'household_id' => (int) $household->id,
                    'household_name' => $householdName,
                    'task_instance_id' => (int) $instance->id,
                    'task_template_id' => (int) $instance->task_template_id,
                    'task_name' => $taskTitle,
                    'due_date' => optional($instance->due_date)->toDateString(),
                    'requester_user_id' => $currentUserId,
                    'requester_name' => (string) ($currentUser->name ?? 'Membre'),
                    'invited_user_id' => $invitedUserId,
                    'status' => 'pending',
                ],
            ]);
        });

        event(new TaskReassignmentRequestedEvent(
            invitationNotification: $invitationNotification,
            householdId: (int) $household->id,
            invitedUserId: $invitedUserId,
        ));

        return $invitationNotification;
    }
}