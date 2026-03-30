<?php

namespace App\Actions\Notification;

use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationDispatchService;
use App\Services\RealtimePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RespondHouseholdInviteAction
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
        if ((string) $notification->type !== 'household_invite') {
            throw ValidationException::withMessages([
                'notification' => ["Cette notification n'est pas une invitation de foyer."],
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
                    'notification' => ['Cette invitation a déjà été traitée.'],
                ]);
            }

            $householdId = (int) ($data['household_id'] ?? 0);
            $invitedRole = (string) ($data['invited_role'] ?? User::ROLE_CHILD);
            if (!in_array($invitedRole, [User::ROLE_PARENT, User::ROLE_CHILD], true)) {
                $invitedRole = User::ROLE_CHILD;
            }

            $household = Household::query()->find($householdId);
            if (!$household instanceof Household) {
                throw ValidationException::withMessages([
                    'household' => ['Ce foyer n\'existe plus.'],
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
                $inviterNotification = $this->notificationDispatchService->createUserNotification(
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
                    $this->notificationDispatchService->publishNotificationCreated($inviterNotification);
                }
            });

            $freshUser = User::query()
                ->whereKey($user->id)
                ->with('households')
                ->firstOrFail();

            return [
                'message' => $isAccepted ? 'Invitation acceptée.' : 'Invitation refusée.',
                'invitation' => [
                    'status' => (string) $data['status'],
                    'household_id' => (int) $household->id,
                    'household_name' => (string) $household->name,
                    'role' => $invitedRole,
                ],
                'user' => $freshUser,
            ];
        });
    }
}

