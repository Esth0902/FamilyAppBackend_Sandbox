<?php

namespace App\Queries\Notification;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\HouseholdDeletionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class GetUnreadNotificationsQuery
{
    private const HOUSEHOLD_LINK_REQUEST_TYPE = 'household_link_request';

    /**
     * @return EloquentCollection<int, UserNotification>
     */
    public function execute(User $user, bool $includeAllHouseholds, int $activeHouseholdId): EloquentCollection
    {
        $userId = (int) $user->id;
        $now = now();
        $canFilterByHousehold = false;
        if (!$includeAllHouseholds && $activeHouseholdId > 0) {
            $canFilterByHousehold = $user
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
                    ->orWhere(function ($inviteQuery): void {
                        $inviteQuery
                            ->where('type', self::HOUSEHOLD_LINK_REQUEST_TYPE)
                            ->whereNull('read_at')
                            ->where(function ($statusQuery): void {
                                $statusQuery
                                    ->whereNull('data->status')
                                    ->orWhere('data->status', 'pending');
                            });
                    })
                    ->orWhere(function ($unreadQuery) use ($now): void {
                        $unreadQuery
                            ->whereNull('read_at')
                            ->whereNotIn('type', [
                                'household_invite',
                                self::HOUSEHOLD_LINK_REQUEST_TYPE,
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
                    ->orWhere('type', 'household_invite')
                    ->orWhere('type', self::HOUSEHOLD_LINK_REQUEST_TYPE);
            });
        }

        $notifications = $notificationsQuery->get();
        $this->markUnsentAsSent($notifications, $now);

        return $notifications;
    }

    /**
     * @param  Collection<int, UserNotification>  $notifications
     */
    private function markUnsentAsSent(Collection $notifications, \DateTimeInterface $now): void
    {
        $unsentNotificationIds = $notifications
            ->filter(fn (UserNotification $notification): bool => $notification->sent_at === null)
            ->pluck('id');

        if ($unsentNotificationIds->isEmpty()) {
            return;
        }

        UserNotification::query()
            ->whereIn('id', $unsentNotificationIds)
            ->update(['sent_at' => $now]);

        $sentIdLookup = $unsentNotificationIds
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        $notifications->each(function (UserNotification $notification) use ($sentIdLookup, $now): void {
            if (in_array((int) $notification->id, $sentIdLookup, true)) {
                $notification->sent_at = $now;
            }
        });
    }
}
