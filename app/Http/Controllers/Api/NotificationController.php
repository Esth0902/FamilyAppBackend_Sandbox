<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\TaskInstance;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\HouseholdDeletionService;
use App\Services\RealtimePublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationController extends Controller
{
    public function __construct(
        private readonly RealtimePublisher $realtimePublisher,
        private readonly HouseholdDeletionService $householdDeletionService,
    ) {
    }

    public function pending(Request $request): JsonResponse
    {
        $userId = (int)$request->user()->id;
        $now = now();
        $includeAllHouseholds = filter_var(
            $request->query('all_households', false),
            FILTER_VALIDATE_BOOLEAN
        );
        $activeHouseholdId = (int) $request->header('X-Household-Id', 0);
        $canFilterByHousehold = false;
        if (!$includeAllHouseholds && $activeHouseholdId > 0) {
            $canFilterByHousehold = $request->user()
                ->households()
                ->where('households.id', $activeHouseholdId)
                ->exists();
        }

        $notificationsQuery = UserNotification::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($now): void {
                $query
                    ->where(function ($pendingSendQuery) use ($now): void {
                        $pendingSendQuery
                            ->whereNull('sent_at')
                            ->where(function ($scheduleQuery) use ($now): void {
                                $scheduleQuery
                                    ->whereNull('scheduled_for')
                                    ->orWhere('scheduled_for', '<=', $now);
                            });
                    })
                    ->orWhere(function ($inviteQuery): void {
                        $inviteQuery
                            ->where('type', 'household_invite')
                            ->whereNull('read_at')
                            ->where(function ($statusQuery): void {
                                $statusQuery
                                    ->whereNull('data->status')
                                    ->orWhere('data->status', 'pending');
                            });
                    })
                    ->orWhere(function ($inviteQuery): void {
                        $inviteQuery
                            ->where('type', 'task_reassignment_invite')
                            ->whereNull('read_at')
                            ->where(function ($statusQuery): void {
                                $statusQuery
                                    ->whereNull('data->status')
                                    ->orWhere('data->status', 'pending');
                            });
                    })
                    ->orWhere(function ($inviteQuery): void {
                        $inviteQuery
                            ->where('type', HouseholdDeletionService::TYPE_APPROVAL_REQUEST)
                            ->whereNull('read_at')
                            ->where(function ($statusQuery): void {
                                $statusQuery
                                    ->whereNull('data->status')
                                    ->orWhere('data->status', 'pending');
                            });
                    })
                    ->orWhere(function ($inviteQuery): void {
                        $inviteQuery
                            ->where('type', HouseholdDeletionService::TYPE_CANCEL_WINDOW)
                            ->whereNull('read_at')
                            ->where(function ($statusQuery): void {
                                $statusQuery
                                    ->whereNull('data->status')
                                    ->orWhere('data->status', 'scheduled');
                            });
                    })
                    ->orWhere(function ($unreadQuery) use ($now): void {
                        $unreadQuery
                            ->whereNull('read_at')
                            ->whereNotIn('type', [
                                'household_invite',
                                'task_reassignment_invite',
                                HouseholdDeletionService::TYPE_APPROVAL_REQUEST,
                                HouseholdDeletionService::TYPE_CANCEL_WINDOW,
                                HouseholdDeletionService::TYPE_CONTROL,
                            ])
                            ->where(function ($scheduleQuery) use ($now): void {
                                $scheduleQuery
                                    ->whereNull('scheduled_for')
                                    ->orWhere('scheduled_for', '<=', $now);
                            });
                    });
            })
            ->orderBy('created_at')
            ->limit(30);

        if ($canFilterByHousehold) {
            $notificationsQuery->where(function ($query) use ($activeHouseholdId): void {
                $query
                    ->where(function ($householdScopeQuery) use ($activeHouseholdId): void {
                        $householdScopeQuery
                            ->whereNull('household_id')
                            ->orWhere('household_id', $activeHouseholdId);
                    })
                    ->orWhere('type', 'household_invite');
            });
        }

        $notifications = $notificationsQuery->get();

        $unsentNotificationIds = $notifications
            ->filter(fn(UserNotification $notification): bool => is_null($notification->sent_at))
            ->pluck('id');

        if ($unsentNotificationIds->isNotEmpty()) {
            UserNotification::query()
                ->whereIn('id', $unsentNotificationIds)
                ->update(['sent_at' => $now]);

            $sentIdLookup = $unsentNotificationIds
                ->map(static fn($id): int => (int) $id)
                ->values()
                ->all();
            $notifications->each(function (UserNotification $notification) use ($sentIdLookup, $now): void {
                if (in_array((int) $notification->id, $sentIdLookup, true)) {
                    $notification->sent_at = $now;
                }
            });
        }

        return response()->json([
            'notifications' => $notifications->map(fn(UserNotification $notification): array => [
                'id' => $notification->id,
                'household_id' => $notification->household_id ? (int) $notification->household_id : null,
                'type' => $notification->type,
                'title' => $notification->title,
                'body' => $notification->body,
                'data' => $notification->data ?? [],
                'scheduled_for' => optional($notification->scheduled_for)->toIso8601String(),
                'sent_at' => optional($notification->sent_at)->toIso8601String(),
                'read_at' => optional($notification->read_at)->toIso8601String(),
                'created_at' => optional($notification->created_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    public function read(Request $request, UserNotification $notification): JsonResponse
    {
        if ((int)$notification->user_id !== (int)$request->user()->id) {
            return response()->json(['message' => 'Acces refuse.'], 403);
        }

        if (is_null($notification->read_at)) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['message' => 'Notification lue.']);
    }

    public function respondHouseholdInvite(Request $request, UserNotification $notification): JsonResponse
    {
        if ((int)$notification->user_id !== (int)$request->user()->id) {
            return response()->json(['message' => 'Acces refuse.'], 403);
        }

        $validated = $request->validate([
            'action' => ['required', 'in:accept,refuse'],
        ]);
        $action = (string) $validated['action'];

        if ((string) $notification->type !== 'household_invite') {
            throw ValidationException::withMessages([
                'notification' => ["Cette notification n'est pas une invitation de foyer."],
            ]);
        }

        $user = $request->user();
        $now = now();

        return DB::transaction(function () use ($notification, $user, $action, $now): JsonResponse {
            /** @var UserNotification $locked */
            $locked = UserNotification::query()
                ->whereKey($notification->id)
                ->lockForUpdate()
                ->firstOrFail();

            $data = is_array($locked->data) ? $locked->data : [];
            $currentStatus = (string) ($data['status'] ?? 'pending');

            if (in_array($currentStatus, ['accepted', 'refused'], true)) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette invitation a deja ete traitee.'],
                ]);
            }

            $householdId = (int) ($data['household_id'] ?? 0);
            $invitedRole = (string) ($data['invited_role'] ?? User::ROLE_CHILD);
            if (!in_array($invitedRole, [User::ROLE_PARENT, User::ROLE_CHILD], true)) {
                $invitedRole = User::ROLE_CHILD;
            }

            $household = Household::query()->find($householdId);
            if (!$household) {
                throw ValidationException::withMessages([
                    'household' => ['Ce foyer n existe plus.'],
                ]);
            }

            $isAccepted = $action === 'accept';
            if ($isAccepted) {
                $alreadyMember = $household->users()
                    ->where('users.id', $user->id)
                    ->exists();

                if (!$alreadyMember) {
                    $household->users()->attach($user->id, [
                        'role' => $invitedRole,
                        'nickname' => (string) ($user->name ?? 'Membre'),
                    ]);
                }

                if ($invitedRole === User::ROLE_CHILD) {
                    BudgetSetting::query()->firstOrCreate(
                        [
                            'household_id' => $household->id,
                            'user_id' => $user->id,
                        ],
                        [
                            'base_amount' => 0,
                            'recurrence' => 'weekly',
                            'reset_day' => 1,
                            'allow_advances' => false,
                            'max_advance_amount' => 0,
                        ]
                    );
                }
            }

            $data['status'] = $isAccepted ? 'accepted' : 'refused';
            $data['responded_at'] = $now->toIso8601String();
            $data['responded_action'] = $action;

            $locked->forceFill([
                'data' => $data,
                'read_at' => $now,
            ])->save();

            $inviterNotification = null;
            $inviterUserId = (int) ($data['inviter_user_id'] ?? 0);
            if ($inviterUserId > 0 && $inviterUserId !== (int) ($user->id ?? 0)) {
                $isAcceptedStatus = (string) ($data['status'] ?? 'pending') === 'accepted';
                $inviterNotification = $this->createUserNotification(
                    userId: $inviterUserId,
                    householdId: (int) $household->id,
                    type: 'household_invite_responded',
                    title: $isAcceptedStatus ? 'Invitation foyer acceptée' : 'Invitation foyer refusée',
                    body: $isAcceptedStatus
                        ? sprintf(
                            '%s a accepté l\'invitation à rejoindre le foyer %s.',
                            (string) ($user->name ?? 'Un membre'),
                            (string) $household->name
                        )
                        : sprintf(
                            '%s a refusé l\'invitation à rejoindre le foyer %s.',
                            (string) ($user->name ?? 'Un membre'),
                            (string) $household->name
                        ),
                    data: [
                        'household_id' => (int) $household->id,
                        'household_name' => (string) $household->name,
                        'invited_user_id' => (int) ($user->id ?? 0),
                        'invited_user_name' => (string) ($user->name ?? 'Un membre'),
                        'status' => (string) ($data['status'] ?? 'pending'),
                        'action' => (string) ($data['responded_action'] ?? ''),
                        'responded_at' => (string) ($data['responded_at'] ?? ''),
                    ],
                );
            }

            DB::afterCommit(function () use ($household, $user, $data, $inviterNotification): void {
                $this->realtimePublisher->publishHousehold(
                    householdId: (int) $household->id,
                    module: 'household',
                    type: 'member_invite_responded',
                    payload: [
                        'household_id' => (int) $household->id,
                        'user_id' => (int) ($user->id ?? 0),
                        'user_name' => (string) ($user->name ?? 'Membre'),
                        'status' => (string) ($data['status'] ?? 'pending'),
                        'responded_action' => (string) ($data['responded_action'] ?? ''),
                        'responded_at' => (string) ($data['responded_at'] ?? ''),
                    ],
                );

                if ($inviterNotification instanceof UserNotification) {
                    $this->publishNotificationCreated($inviterNotification);
                }
            });

            $freshUser = User::query()
                ->whereKey($user->id)
                ->with('households')
                ->firstOrFail();

            return response()->json([
                'message' => $isAccepted ? 'Invitation acceptee.' : 'Invitation refusee.',
                'invitation' => [
                    'status' => $data['status'],
                    'household_id' => $household->id,
                    'household_name' => $household->name,
                    'role' => $invitedRole,
                ],
                'user' => $freshUser,
            ]);
        });
    }

    public function respondTaskReassignmentInvite(Request $request, UserNotification $notification): JsonResponse
    {
        if ((int) $notification->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Acces refuse.'], 403);
        }

        $validated = $request->validate([
            'action' => ['required', 'in:accept,refuse'],
        ]);
        $action = (string) $validated['action'];

        if ((string) $notification->type !== 'task_reassignment_invite') {
            throw ValidationException::withMessages([
                'notification' => ["Cette notification n'est pas une demande de reprise de tache."],
            ]);
        }

        $user = $request->user();
        $now = now();

        return DB::transaction(function () use ($notification, $user, $action, $now): JsonResponse {
            /** @var UserNotification $locked */
            $locked = UserNotification::query()
                ->whereKey($notification->id)
                ->lockForUpdate()
                ->firstOrFail();

            $data = is_array($locked->data) ? $locked->data : [];
            $currentStatus = (string) ($data['status'] ?? 'pending');
            if (in_array($currentStatus, ['accepted', 'refused'], true)) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande a deja ete traitee.'],
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
                    'notification' => ['Cette demande ne vous est pas destinee.'],
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
            if (!$instance || (int) ($instance->template?->household_id ?? 0) !== $householdId) {
                throw ValidationException::withMessages([
                    'task' => ['La tache liee a cette demande est introuvable.'],
                ]);
            }

            $isAccepted = $action === 'accept';
            if ($isAccepted) {
                $assigneeIds = $instance->assignees
                    ->map(static fn(User $assignee): int => (int) $assignee->id)
                    ->filter(static fn(int $id): bool => $id > 0)
                    ->values()
                    ->all();
                if (count($assigneeIds) === 0 && (int) $instance->user_id > 0) {
                    $assigneeIds = [(int) $instance->user_id];
                }

                $assigneeIds[] = $invitedUserId;
                $assigneeIds = collect($assigneeIds)
                    ->map(static fn($id): int => (int) $id)
                    ->filter(static fn(int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all();

                if ($requesterUserId !== $invitedUserId) {
                    $assigneeIds = collect($assigneeIds)
                        ->reject(static fn(int $id): bool => $id === $requesterUserId)
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
                $taskName = (string) data_get($data, 'task_name', 'cette tâche');
                $householdName = (string) data_get($data, 'household_name', 'ce foyer');
                $requesterNotification = $this->createUserNotification(
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
                    $this->publishNotificationCreated($requesterNotification);
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
                            'task_name' => (string) data_get($data, 'task_name', 'Tâche'),
                            'status' => (string) data_get($data, 'status', 'pending'),
                            'action' => $action,
                            'responder_user_id' => (int) ($user->id ?? 0),
                            'responder_name' => (string) ($user->name ?? 'Un membre'),
                        ],
                    );
                }
            });

            return response()->json([
                'message' => $isAccepted ? 'Demande acceptee.' : 'Demande refusee.',
                'invitation' => [
                    'status' => (string) $data['status'],
                    'task_instance_id' => $instanceId,
                    'task_name' => (string) ($data['task_name'] ?? 'Tache'),
                ],
                'instance' => [
                    'id' => (int) $instance->id,
                    'assignee_id' => (int) $instance->user_id,
                    'assignee_ids' => $instance->assignees
                        ->map(static fn(User $assignee): int => (int) $assignee->id)
                        ->values()
                        ->all(),
                ],
            ]);
        });
    }

    public function respondHouseholdDeletion(Request $request, UserNotification $notification): JsonResponse
    {
        if ((int) $notification->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        $validated = $request->validate([
            'action' => ['required', 'in:accept,refuse,cancel'],
        ]);
        $action = (string) $validated['action'];
        $type = (string) $notification->type;

        if ($type === HouseholdDeletionService::TYPE_APPROVAL_REQUEST) {
            if (!in_array($action, ['accept', 'refuse'], true)) {
                throw ValidationException::withMessages([
                    'action' => ['Action invalide pour cette demande.'],
                ]);
            }

            $result = $this->householdDeletionService->respondToApproval(
                $notification,
                $request->user(),
                $action
            );

            return response()->json([
                'message' => $action === 'accept'
                    ? 'Demande de suppression acceptée.'
                    : 'Demande de suppression refusée.',
                'deletion_request' => $result,
            ]);
        }

        if ($type === HouseholdDeletionService::TYPE_CANCEL_WINDOW) {
            if ($action !== 'cancel') {
                throw ValidationException::withMessages([
                    'action' => ['Action invalide pour cette demande.'],
                ]);
            }

            $result = $this->householdDeletionService->cancelScheduledDeletion(
                $notification,
                $request->user()
            );

            return response()->json([
                'message' => 'Suppression planifiée annulée.',
                'deletion_request' => $result,
            ]);
        }

        throw ValidationException::withMessages([
            'notification' => ['Cette notification ne concerne pas une suppression de foyer.'],
        ]);
    }

    private function createUserNotification(
        int $userId,
        int $householdId,
        string $type,
        string $title,
        string $body,
        array $data = []
    ): UserNotification {
        return UserNotification::query()->create([
            'household_id' => $householdId,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data + ['household_id' => $householdId],
        ]);
    }

    private function publishNotificationCreated(UserNotification $notification): void
    {
        $this->realtimePublisher->publishUser(
            userId: (int) $notification->user_id,
            module: 'notifications',
            type: 'notification_created',
            payload: [
                'notification_id' => (int) $notification->id,
                'household_id' => (int) $notification->household_id,
                'type' => (string) $notification->type,
                'title' => (string) $notification->title,
                'body' => (string) $notification->body,
            ],
        );
    }
}
