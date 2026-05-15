<?php

namespace App\Services;

use App\Models\Household;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HouseholdDeletionService
{
    public const TYPE_CONTROL = 'household_deletion_control';
    public const TYPE_APPROVAL_REQUEST = 'household_deletion_approval_request';
    public const TYPE_CANCEL_WINDOW = 'household_deletion_cancel_window';
    public const TYPE_SCHEDULED_INFO = 'household_deletion_scheduled';
    public const TYPE_REQUEST_REFUSED = 'household_deletion_request_refused';
    public const TYPE_REQUEST_ACCEPTED = 'household_deletion_request_accepted';
    public const TYPE_CANCELLED = 'household_deletion_cancelled';

    public function __construct(
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    /**
     * @return array{
     *     request_id: string,
     *     status: 'pending_approvals'|'scheduled',
     *     scheduled_for: string|null,
     *     approvals_required: int,
     *     approvals_received: int
     * }
     */
    public function requestDeletion(Household $household, User $requester): array
    {
        $requesterId = (int) ($requester->id ?? 0);
        $householdId = (int) $household->id;

        if ($requesterId <= 0 || $householdId <= 0) {
            throw ValidationException::withMessages([
                'household' => ['Demande de suppression invalide.'],
            ]);
        }

        $parentIds = $this->resolveParentUserIds($household);
        if (!in_array($requesterId, $parentIds, true)) {
            abort(403, 'Action réservée aux parents.');
        }

        $pendingControl = $this->findActiveControlNotification($householdId);
        if ($pendingControl instanceof UserNotification) {
            $status = (string) data_get($pendingControl->data, 'status', '');
            $field = $status === 'scheduled' ? 'household' : 'deletion_request';
            $message = $status === 'scheduled'
                ? 'Une suppression est déjà planifiée pour ce foyer.'
                : 'Une demande de suppression est déjà en cours pour ce foyer.';

            throw ValidationException::withMessages([
                $field => [$message],
            ]);
        }

        $now = now()->startOfSecond();
        $requestId = (string) Str::uuid();
        $otherParentIds = collect($parentIds)
            ->reject(static fn(int $id): bool => $id === $requesterId)
            ->values()
            ->all();

        $createdNotifications = collect();

        DB::transaction(function () use (
            $household,
            $requester,
            $requestId,
            $now,
            $parentIds,
            $otherParentIds,
            &$createdNotifications
        ): void {
            $controlStatus = count($otherParentIds) > 0 ? 'pending_approvals' : 'scheduled';
            $scheduledDeleteAt = $controlStatus === 'scheduled'
                ? $now->copy()->addHours(24)->toIso8601String()
                : null;

            UserNotification::query()->create([
                'household_id' => (int) $household->id,
                'user_id' => (int) $requester->id,
                'type' => self::TYPE_CONTROL,
                'title' => 'Contrôle suppression foyer',
                'body' => 'Notification interne de contrôle.',
                'data' => [
                    'request_id' => $requestId,
                    'household_id' => (int) $household->id,
                    'household_name' => (string) $household->name,
                    'initiator_user_id' => (int) $requester->id,
                    'initiator_name' => (string) ($requester->name ?? 'Un parent'),
                    'status' => $controlStatus,
                    'requested_at' => $now->toIso8601String(),
                    'scheduled_delete_at' => $scheduledDeleteAt,
                    'parent_user_ids' => array_values($parentIds),
                    'required_parent_user_ids' => array_values($otherParentIds),
                    'accepted_parent_user_ids' => [],
                    'refused_parent_user_ids' => [],
                    'cancel_reason' => null,
                    'cancelled_at' => null,
                    'cancelled_by_user_id' => null,
                ],
                'sent_at' => $now,
                'read_at' => $now,
            ]);

            if (count($otherParentIds) === 0) {
                $createdNotifications = $createdNotifications->merge(
                    $this->createScheduledNotifications(
                        $household,
                        $requestId,
                        $now->copy()->addHours(24)->startOfSecond(),
                        (int) $requester->id,
                        (string) ($requester->name ?? 'Un parent'),
                    )
                );

                return;
            }

            foreach ($otherParentIds as $parentId) {
                $createdNotifications->push($this->createNotification(
                    userId: (int) $parentId,
                    householdId: (int) $household->id,
                    type: self::TYPE_APPROVAL_REQUEST,
                    title: 'Suppression du foyer à confirmer',
                    body: sprintf(
                        '%s demande la suppression du foyer %s. Acceptez ou refusez cette demande.',
                        (string) ($requester->name ?? 'Un parent'),
                        (string) $household->name
                    ),
                    data: [
                        'request_id' => $requestId,
                        'household_id' => (int) $household->id,
                        'household_name' => (string) $household->name,
                        'initiator_user_id' => (int) $requester->id,
                        'initiator_name' => (string) ($requester->name ?? 'Un parent'),
                        'status' => 'pending',
                        'requested_at' => $now->toIso8601String(),
                    ]
                ));
            }
        });

        if ($createdNotifications->isNotEmpty()) {
            DB::afterCommit(function () use ($createdNotifications): void {
                $createdNotifications->each(function (UserNotification $notification): void {
                    $this->publishNotificationCreated($notification);
                });
            });
        }

        $isScheduled = count($otherParentIds) === 0;
        $scheduledFor = $isScheduled ? $now->copy()->addHours(24)->startOfSecond()->toIso8601String() : null;

        return [
            'request_id' => $requestId,
            'status' => $isScheduled ? 'scheduled' : 'pending_approvals',
            'scheduled_for' => $scheduledFor,
            'approvals_required' => count($otherParentIds),
            'approvals_received' => 0,
        ];
    }

    /**
     * @return array{
     *     status: 'pending_approvals'|'scheduled'|'cancelled',
     *     request_id: string,
     *     scheduled_for: string|null,
     *     approvals_required: int,
     *     approvals_received: int
     * }
     */
    public function respondToApproval(UserNotification $notification, User $responder, string $action): array
    {
        $responderId = (int) ($responder->id ?? 0);
        if ($responderId <= 0) {
            throw ValidationException::withMessages([
                'notification' => ['Réponse invalide.'],
            ]);
        }

        $now = now()->startOfSecond();
        $createdNotifications = collect();
        $result = [];

        DB::transaction(function () use (
            $notification,
            $responder,
            $action,
            $responderId,
            $now,
            &$createdNotifications,
            &$result
        ): void {
            /** @var UserNotification $locked */
            $locked = UserNotification::query()
                ->whereKey($notification->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $locked->type !== self::TYPE_APPROVAL_REQUEST) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette notification ne concerne pas une validation de suppression.'],
                ]);
            }

            if ((int) $locked->user_id !== $responderId) {
                abort(403, 'Accès refusé.');
            }

            $data = is_array($locked->data) ? $locked->data : [];
            $requestId = (string) ($data['request_id'] ?? '');
            $householdId = (int) ($data['household_id'] ?? $locked->household_id ?? 0);
            $householdName = (string) ($data['household_name'] ?? 'ce foyer');
            $initiatorUserId = (int) ($data['initiator_user_id'] ?? 0);

            if ($requestId === '' || $householdId <= 0) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande est invalide.'],
                ]);
            }

            $currentStatus = (string) ($data['status'] ?? 'pending');
            if ($currentStatus !== 'pending') {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande a déjà été traitée.'],
                ]);
            }

            $household = Household::query()->find($householdId);
            if (!$household) {
                throw ValidationException::withMessages([
                    'household' => ['Ce foyer n\'existe plus.'],
                ]);
            }

            $isParent = $household->users()
                ->where('users.id', $responderId)
                ->wherePivot('role', User::ROLE_PARENT)
                ->exists();
            if (!$isParent) {
                abort(403, 'Action réservée aux parents.');
            }

            /** @var UserNotification $control */
            $control = $this->findControlNotificationByRequest(
                householdId: $householdId,
                requestId: $requestId,
                lockForUpdate: true
            );
            if (!$control) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande a expiré.'],
                ]);
            }

            $controlData = is_array($control->data) ? $control->data : [];
            $controlStatus = (string) ($controlData['status'] ?? '');
            if ($controlStatus !== 'pending_approvals') {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande ne peut plus être traitée.'],
                ]);
            }

            $requiredParentIds = collect($controlData['required_parent_user_ids'] ?? [])
                ->map(static fn($id): int => (int) $id)
                ->filter(static fn(int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            if (!in_array($responderId, $requiredParentIds, true)) {
                throw ValidationException::withMessages([
                    'notification' => ['tu n\'es pas concerné par cette demande.'],
                ]);
            }

            $acceptedIds = collect($controlData['accepted_parent_user_ids'] ?? [])
                ->map(static fn($id): int => (int) $id)
                ->filter(static fn(int $id): bool => $id > 0)
                ->unique()
                ->values();
            $refusedIds = collect($controlData['refused_parent_user_ids'] ?? [])
                ->map(static fn($id): int => (int) $id)
                ->filter(static fn(int $id): bool => $id > 0)
                ->unique()
                ->values();

            $isAccepted = $action === 'accept';

            $data['status'] = $isAccepted ? 'accepted' : 'refused';
            $data['responded_at'] = $now->toIso8601String();
            $data['responded_action'] = $action;
            $locked->forceFill([
                'data' => $data,
                'read_at' => $now,
            ])->save();

            if (!$isAccepted) {
                $controlData['status'] = 'cancelled';
                $controlData['cancel_reason'] = 'refused';
                $controlData['cancelled_at'] = $now->toIso8601String();
                $controlData['cancelled_by_user_id'] = $responderId;
                $controlData['refused_parent_user_ids'] = $refusedIds
                    ->push($responderId)
                    ->unique()
                    ->values()
                    ->all();
                $control->forceFill(['data' => $controlData])->save();

                $this->cancelPendingApprovalNotifications($householdId, $requestId, $now, (int) $locked->id);

                if ($initiatorUserId > 0 && $initiatorUserId !== $responderId) {
                    $createdNotifications->push($this->createNotification(
                        userId: $initiatorUserId,
                        householdId: $householdId,
                        type: self::TYPE_REQUEST_REFUSED,
                        title: 'Suppression du foyer refusée',
                        body: sprintf(
                            '%s a refusé la suppression du foyer %s. tu peux quitter ce foyer si tu le souhaites.',
                            (string) ($responder->name ?? 'Un parent'),
                            $householdName
                        ),
                        data: [
                            'request_id' => $requestId,
                            'household_id' => $householdId,
                            'household_name' => $householdName,
                            'status' => 'refused',
                            'responder_user_id' => $responderId,
                            'responder_name' => (string) ($responder->name ?? 'Un parent'),
                            'responded_at' => $now->toIso8601String(),
                        ]
                    ));
                }

                $result = [
                    'status' => 'cancelled',
                    'request_id' => $requestId,
                    'scheduled_for' => null,
                    'approvals_required' => count($requiredParentIds),
                    'approvals_received' => $acceptedIds->count(),
                ];

                return;
            }

            $acceptedIds = $acceptedIds->push($responderId)->unique()->values();
            $controlData['accepted_parent_user_ids'] = $acceptedIds->all();

            $hasAllApprovals = collect($requiredParentIds)
                ->every(static fn(int $parentId): bool => $acceptedIds->contains($parentId));

            if (!$hasAllApprovals) {
                $control->forceFill(['data' => $controlData])->save();

                if ($initiatorUserId > 0 && $initiatorUserId !== $responderId) {
                    $createdNotifications->push($this->createNotification(
                        userId: $initiatorUserId,
                        householdId: $householdId,
                        type: self::TYPE_REQUEST_ACCEPTED,
                        title: 'Validation de suppression reçue',
                        body: sprintf(
                            '%s a accepté la suppression du foyer %s.',
                            (string) ($responder->name ?? 'Un parent'),
                            $householdName
                        ),
                        data: [
                            'request_id' => $requestId,
                            'household_id' => $householdId,
                            'household_name' => $householdName,
                            'status' => 'accepted',
                            'responder_user_id' => $responderId,
                            'responder_name' => (string) ($responder->name ?? 'Un parent'),
                            'responded_at' => $now->toIso8601String(),
                        ]
                    ));
                }

                $result = [
                    'status' => 'pending_approvals',
                    'request_id' => $requestId,
                    'scheduled_for' => null,
                    'approvals_required' => count($requiredParentIds),
                    'approvals_received' => $acceptedIds->count(),
                ];

                return;
            }

            $scheduledDeleteAt = $now->copy()->addHours(24)->startOfSecond();
            $controlData['status'] = 'scheduled';
            $controlData['scheduled_delete_at'] = $scheduledDeleteAt->toIso8601String();
            $control->forceFill(['data' => $controlData])->save();

            $createdNotifications = $createdNotifications->merge(
                $this->createScheduledNotifications(
                    $household,
                    $requestId,
                    $scheduledDeleteAt,
                    (int) data_get($controlData, 'initiator_user_id', 0),
                    (string) data_get($controlData, 'initiator_name', 'Un parent')
                )
            );

            $result = [
                'status' => 'scheduled',
                'request_id' => $requestId,
                'scheduled_for' => $scheduledDeleteAt->toIso8601String(),
                'approvals_required' => count($requiredParentIds),
                'approvals_received' => $acceptedIds->count(),
            ];
        });

        if ($createdNotifications->isNotEmpty()) {
            DB::afterCommit(function () use ($createdNotifications): void {
                $createdNotifications->each(function (UserNotification $notification): void {
                    $this->publishNotificationCreated($notification);
                });
            });
        }

        return $result;
    }

    /**
     * @return array{
     *     status: 'cancelled',
     *     request_id: string,
     *     cancelled_at: string
     * }
     */
    public function cancelScheduledDeletion(UserNotification $notification, User $actor): array
    {
        $actorId = (int) ($actor->id ?? 0);
        if ($actorId <= 0) {
            throw ValidationException::withMessages([
                'notification' => ['Annulation invalide.'],
            ]);
        }

        $now = now()->startOfSecond();
        $createdNotifications = collect();
        $result = [];

        DB::transaction(function () use (
            $notification,
            $actor,
            $actorId,
            $now,
            &$createdNotifications,
            &$result
        ): void {
            /** @var UserNotification $locked */
            $locked = UserNotification::query()
                ->whereKey($notification->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $locked->type !== self::TYPE_CANCEL_WINDOW) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette notification ne permet pas d\'annuler une suppression.'],
                ]);
            }

            if ((int) $locked->user_id !== $actorId) {
                abort(403, 'Accès refusé.');
            }

            $data = is_array($locked->data) ? $locked->data : [];
            $requestId = (string) ($data['request_id'] ?? '');
            $householdId = (int) ($data['household_id'] ?? $locked->household_id ?? 0);
            $householdName = (string) ($data['household_name'] ?? 'ce foyer');

            if ($requestId === '' || $householdId <= 0) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande est invalide.'],
                ]);
            }

            $household = Household::query()->find($householdId);
            if (!$household) {
                throw ValidationException::withMessages([
                    'household' => ['Ce foyer n\'existe plus.'],
                ]);
            }

            $isParent = $household->users()
                ->where('users.id', $actorId)
                ->wherePivot('role', User::ROLE_PARENT)
                ->exists();
            if (!$isParent) {
                abort(403, 'Action réservée aux parents.');
            }

            /** @var UserNotification $control */
            $control = $this->findControlNotificationByRequest(
                householdId: $householdId,
                requestId: $requestId,
                lockForUpdate: true
            );
            if (!$control) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette suppression est introuvable.'],
                ]);
            }

            $controlData = is_array($control->data) ? $control->data : [];
            $controlStatus = (string) ($controlData['status'] ?? '');
            if ($controlStatus !== 'scheduled') {
                throw ValidationException::withMessages([
                    'notification' => ['Cette suppression n\'est plus planifiée.'],
                ]);
            }

            $controlData['status'] = 'cancelled';
            $controlData['cancel_reason'] = 'parent_cancelled';
            $controlData['cancelled_at'] = $now->toIso8601String();
            $controlData['cancelled_by_user_id'] = $actorId;
            $control->forceFill(['data' => $controlData])->save();

            $this->markRequestNotificationsAsCancelled($householdId, $requestId, $now);

            $lockedData = is_array($locked->data) ? $locked->data : [];
            $lockedData['status'] = 'cancelled';
            $lockedData['cancelled_at'] = $now->toIso8601String();
            $locked->forceFill([
                'data' => $lockedData,
                'read_at' => $now,
            ])->save();

            $memberIds = $household->users()
                ->pluck('users.id')
                ->map(static fn($id): int => (int) $id)
                ->filter(static fn(int $id): bool => $id > 0)
                ->values()
                ->all();

            foreach ($memberIds as $memberId) {
                $createdNotifications->push($this->createNotification(
                    userId: $memberId,
                    householdId: $householdId,
                    type: self::TYPE_CANCELLED,
                    title: 'Suppression du foyer annulée',
                    body: sprintf(
                        '%s a annulé la suppression planifiée du foyer %s.',
                        (string) ($actor->name ?? 'Un parent'),
                        $householdName
                    ),
                    data: [
                        'request_id' => $requestId,
                        'household_id' => $householdId,
                        'household_name' => $householdName,
                        'status' => 'cancelled',
                        'cancelled_by_user_id' => $actorId,
                        'cancelled_by_name' => (string) ($actor->name ?? 'Un parent'),
                        'cancelled_at' => $now->toIso8601String(),
                    ],
                ));
            }

            $result = [
                'status' => 'cancelled',
                'request_id' => $requestId,
                'cancelled_at' => $now->toIso8601String(),
            ];
        });

        if ($createdNotifications->isNotEmpty()) {
            DB::afterCommit(function () use ($createdNotifications): void {
                $createdNotifications->each(function (UserNotification $notification): void {
                    $this->publishNotificationCreated($notification);
                });
            });
        }

        return $result;
    }

    public function processScheduledDeletions(): void
    {
        $now = now()->startOfSecond();

        UserNotification::query()
            ->where('type', self::TYPE_CONTROL)
            ->where('data->status', 'scheduled')
            ->orderBy('id')
            ->chunkById(100, function (Collection $notifications) use ($now): void {
                foreach ($notifications as $notification) {
                    $this->processScheduledDeletionNotification($notification, $now);
                }
            });
    }

    private function processScheduledDeletionNotification(UserNotification $candidate, Carbon $now): void
    {
        $memberIds = [];
        $householdId = (int) $candidate->household_id;
        $householdName = (string) data_get($candidate->data, 'household_name', 'ce foyer');

        DB::transaction(function () use ($candidate, $now, &$memberIds, &$householdId, &$householdName): void {
            /** @var UserNotification|null $control */
            $control = UserNotification::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->first();

            if (!$control) {
                return;
            }

            $data = is_array($control->data) ? $control->data : [];
            if ((string) ($data['status'] ?? '') !== 'scheduled') {
                return;
            }

            $deleteAtRaw = (string) ($data['scheduled_delete_at'] ?? '');
            if ($deleteAtRaw === '') {
                return;
            }

            try {
                $deleteAt = Carbon::parse($deleteAtRaw)->startOfSecond();
            } catch (\Throwable) {
                return;
            }

            if ($deleteAt->greaterThan($now)) {
                return;
            }

            $householdId = (int) ($control->household_id ?? 0);
            if ($householdId <= 0) {
                return;
            }

            $household = Household::query()->find($householdId);
            if (!$household) {
                return;
            }

            $householdName = (string) ($household->name ?? $householdName);
            $memberIds = $household->users()
                ->pluck('users.id')
                ->map(static fn($id): int => (int) $id)
                ->filter(static fn(int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            $household->delete();
        });

        if (count($memberIds) === 0 || $householdId <= 0) {
            return;
        }

        DB::afterCommit(function () use ($memberIds, $householdId, $householdName): void {
            foreach ($memberIds as $memberId) {
                $this->realtimePublisher->publishUser(
                    userId: (int) $memberId,
                    module: 'household',
                    type: 'household_deleted',
                    payload: [
                        'household_id' => $householdId,
                        'household_name' => $householdName,
                    ],
                );
            }
        });
    }

    /**
     * @return Collection<int, UserNotification>
     */
    private function createScheduledNotifications(
        Household $household,
        string $requestId,
        Carbon $scheduledDeleteAt,
        int $initiatorUserId,
        string $initiatorName,
    ): Collection {
        $householdId = (int) $household->id;
        $householdName = (string) $household->name;
        $scheduledForIso = $scheduledDeleteAt->toIso8601String();
        $members = $household->users()
            ->select(['users.id', 'users.name'])
            ->withPivot('role')
            ->get();

        $created = collect();

        foreach ($members as $member) {
            $memberId = (int) $member->id;
            if ($memberId <= 0) {
                continue;
            }

            $isParent = (string) ($member->pivot->role ?? User::ROLE_CHILD) === User::ROLE_PARENT;
            $type = $isParent ? self::TYPE_CANCEL_WINDOW : self::TYPE_SCHEDULED_INFO;
            $title = 'Suppression du foyer planifiée';
            $body = $isParent
                ? sprintf(
                    'Le foyer %s sera supprimé dans 24h. Cette suppression peut encore être annulée.',
                    $householdName
                )
                : sprintf(
                    'Le foyer %s sera supprimé dans 24h.',
                    $householdName
                );

            $created->push($this->createNotification(
                userId: $memberId,
                householdId: $householdId,
                type: $type,
                title: $title,
                body: $body,
                data: [
                    'request_id' => $requestId,
                    'household_id' => $householdId,
                    'household_name' => $householdName,
                    'initiator_user_id' => $initiatorUserId,
                    'initiator_name' => $initiatorName,
                    'status' => 'scheduled',
                    'scheduled_for' => $scheduledForIso,
                ]
            ));
        }

        return $created;
    }

    private function cancelPendingApprovalNotifications(int $householdId, string $requestId, Carbon $now, int $exceptNotificationId): void
    {
        UserNotification::query()
            ->where('household_id', $householdId)
            ->where('type', self::TYPE_APPROVAL_REQUEST)
            ->where('data->request_id', $requestId)
            ->where('id', '!=', $exceptNotificationId)
            ->get()
            ->each(function (UserNotification $notification) use ($now): void {
                $data = is_array($notification->data) ? $notification->data : [];
                if ((string) ($data['status'] ?? 'pending') !== 'pending') {
                    return;
                }

                $data['status'] = 'cancelled';
                $data['cancelled_at'] = $now->toIso8601String();

                $notification->forceFill([
                    'data' => $data,
                    'read_at' => $now,
                ])->save();
            });
    }

    private function markRequestNotificationsAsCancelled(int $householdId, string $requestId, Carbon $now): void
    {
        UserNotification::query()
            ->where('household_id', $householdId)
            ->whereIn('type', [self::TYPE_CANCEL_WINDOW, self::TYPE_SCHEDULED_INFO])
            ->where('data->request_id', $requestId)
            ->get()
            ->each(function (UserNotification $notification) use ($now): void {
                $data = is_array($notification->data) ? $notification->data : [];
                $data['status'] = 'cancelled';
                $data['cancelled_at'] = $now->toIso8601String();

                $notification->forceFill([
                    'data' => $data,
                ])->save();
            });
    }

    private function findActiveControlNotification(int $householdId): ?UserNotification
    {
        return UserNotification::query()
            ->where('household_id', $householdId)
            ->where('type', self::TYPE_CONTROL)
            ->whereIn('data->status', ['pending_approvals', 'scheduled'])
            ->latest('id')
            ->first();
    }

    private function findControlNotificationByRequest(
        int $householdId,
        string $requestId,
        bool $lockForUpdate = false
    ): ?UserNotification {
        $query = UserNotification::query()
            ->where('household_id', $householdId)
            ->where('type', self::TYPE_CONTROL)
            ->where('data->request_id', $requestId)
            ->latest('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @return array<int, int>
     */
    private function resolveParentUserIds(Household $household): array
    {
        return $household->users()
            ->wherePivot('role', User::ROLE_PARENT)
            ->pluck('users.id')
            ->map(static fn($id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function createNotification(
        int $userId,
        int $householdId,
        string $type,
        string $title,
        string $body,
        array $data
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

