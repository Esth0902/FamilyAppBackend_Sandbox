<?php

namespace App\Actions\Notification;

use App\Models\TaskInstance;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationDispatchService;
use App\Services\RealtimePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RespondTaskReassignmentInviteAction
{
    public function __construct(
        private readonly RealtimePublisher $realtimePublisher,
        private readonly NotificationDispatchService $notificationDispatchService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(UserNotification $notification, User $user, string $action): array
    {
        if ((string) $notification->type !== 'task_reassignment_invite') {
            throw ValidationException::withMessages([
                'notification' => ["Cette notification n'est pas une demande de reprise de tâche."],
            ]);
        }

        $now = now();

        return DB::transaction(function () use ($notification, $user, $action, $now): array {
            /** @var UserNotification $locked */
            $locked = UserNotification::query()
                ->whereKey($notification->id)
                ->lockForUpdate()
                ->firstOrFail();

            $data = is_array($locked->data) ? $locked->data : [];
            $currentStatus = (string) ($data['status'] ?? 'pending');
            if (in_array($currentStatus, ['accepted', 'refused'], true)) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande a déjà été traitée.'],
                ]);
            }

            $instanceId = (int) ($data['task_instance_id'] ?? 0);
            $householdId = (int) ($data['household_id'] ?? 0);
            $requesterUserId = (int) ($data['requester_user_id'] ?? 0);
            $invitedUserId = (int) ($data['invited_user_id'] ?? 0);
            if ($instanceId <= 0 || $householdId <= 0 || $requesterUserId <= 0 || $invitedUserId <= 0) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande est invalide.'],
                ]);
            }

            if ((int) $user->id !== $invitedUserId) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande ne t\'est pas destinée.'],
                ]);
            }

            $instance = TaskInstance::query()
                ->whereKey($instanceId)
                ->with([
                    'template:id,household_id,name',
                    'assignees:id,name',
                ])
                ->lockForUpdate()
                ->first();
            if (!$instance instanceof TaskInstance || (int) ($instance->template?->household_id ?? 0) !== $householdId) {
                throw ValidationException::withMessages([
                    'task' => ['La tâche liée à cette demande est introuvable.'],
                ]);
            }

            $isAccepted = $action === 'accept';
            if ($isAccepted) {
                $assigneeIds = $instance->assignees
                    ->map(static fn (User $assignee): int => (int) $assignee->id)
                    ->filter(static fn (int $id): bool => $id > 0)
                    ->values()
                    ->all();
                if (count($assigneeIds) === 0 && (int) $instance->user_id > 0) {
                    $assigneeIds = [(int) $instance->user_id];
                }

                $assigneeIds[] = $invitedUserId;
                $assigneeIds = collect($assigneeIds)
                    ->map(static fn ($id): int => (int) $id)
                    ->filter(static fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all();

                if ($requesterUserId !== $invitedUserId) {
                    $assigneeIds = collect($assigneeIds)
                        ->reject(static fn (int $id): bool => $id === $requesterUserId)
                        ->values()
                        ->all();
                }

                if (count($assigneeIds) === 0) {
                    $assigneeIds = [$invitedUserId];
                }

                $primaryAssigneeId = (int) ($assigneeIds[0] ?? $invitedUserId);
                $instance->assignees()->sync($assigneeIds);
                if ((int) $instance->user_id !== $primaryAssigneeId) {
                    $instance->update(['user_id' => $primaryAssigneeId]);
                }
                $instance->load('assignees:id,name');
            }

            $data['status'] = $isAccepted ? 'accepted' : 'refused';
            $data['responded_at'] = $now->toIso8601String();
            $data['responded_action'] = $action;

            $locked->forceFill([
                'data' => $data,
                'read_at' => $now,
            ])->save();

            $requesterNotification = null;
            if ($requesterUserId > 0 && $requesterUserId !== (int) ($user->id ?? 0)) {
                $taskName = (string) data_get($data, 'task_name', 'cette tache');
                $householdName = (string) data_get($data, 'household_name', 'ce foyer');
                $requesterNotification = $this->notificationDispatchService->createUserNotification(
                    userId: $requesterUserId,
                    householdId: $householdId,
                    type: 'task_reassignment_invite_responded',
                    title: $isAccepted ? 'Reprise de tâche acceptée' : 'Reprise de tâche refusée',
                    body: $isAccepted
                        ? sprintf('%s a accepté de reprendre %s dans le foyer %s.', (string) ($user->name ?? 'Un membre'), $taskName, $householdName)
                        : sprintf('%s a refusé de reprendre %s dans le foyer %s.', (string) ($user->name ?? 'Un membre'), $taskName, $householdName),
                    data: [
                        'household_id' => $householdId,
                        'household_name' => $householdName,
                        'task_instance_id' => $instanceId,
                        'task_name' => $taskName,
                        'status' => (string) ($data['status'] ?? 'pending'),
                        'action' => $action,
                        'requester_user_id' => $requesterUserId,
                        'responder_user_id' => (int) ($user->id ?? 0),
                        'responder_name' => (string) ($user->name ?? 'Un membre'),
                        'responded_at' => (string) ($data['responded_at'] ?? ''),
                    ],
                );
            }

            DB::afterCommit(function () use ($requesterUserId, $locked, $data, $user, $action, $requesterNotification): void {
                $this->realtimePublisher->publishUser(
                    userId: $requesterUserId,
                    module: 'notifications',
                    type: 'task_reassignment_invite_responded',
                    payload: [
                        'notification_id' => (int) $locked->id,
                        'household_id' => (int) data_get($data, 'household_id', 0),
                        'household_name' => (string) data_get($data, 'household_name', 'ce foyer'),
                        'task_instance_id' => (int) data_get($data, 'task_instance_id'),
                        'task_name' => (string) data_get($data, 'task_name', 'Tache'),
                        'status' => (string) data_get($data, 'status', 'pending'),
                        'action' => $action,
                        'responder_user_id' => (int) ($user->id ?? 0),
                        'responder_name' => (string) ($user->name ?? 'Un membre'),
                        'responded_at' => (string) data_get($data, 'responded_at', ''),
                    ],
                );

                if ($requesterNotification instanceof UserNotification) {
                    $this->notificationDispatchService->publishNotificationCreated($requesterNotification);
                }

                $householdId = (int) data_get($data, 'household_id', 0);
                if ($householdId > 0) {
                    $this->realtimePublisher->publishHousehold(
                        householdId: $householdId,
                        module: 'tasks',
                        type: 'instance.reassignment_responded',
                        payload: [
                            'notification_id' => (int) $locked->id,
                            'task_instance_id' => (int) data_get($data, 'task_instance_id'),
                            'task_name' => (string) data_get($data, 'task_name', 'Tache'),
                            'status' => (string) data_get($data, 'status', 'pending'),
                            'action' => $action,
                            'responder_user_id' => (int) ($user->id ?? 0),
                            'responder_name' => (string) ($user->name ?? 'Un membre'),
                        ],
                    );
                }
            });

            return [
                'message' => $isAccepted ? 'Demande acceptée.' : 'Demande refusée.',
                'invitation' => [
                    'status' => (string) $data['status'],
                    'task_instance_id' => $instanceId,
                    'task_name' => (string) ($data['task_name'] ?? 'Tache'),
                ],
                'instance' => [
                    'id' => (int) $instance->id,
                    'assignee_id' => (int) $instance->user_id,
                    'assignee_ids' => $instance->assignees
                        ->map(static fn (User $assignee): int => (int) $assignee->id)
                        ->values()
                        ->all(),
                ],
            ];
        });
    }
}

