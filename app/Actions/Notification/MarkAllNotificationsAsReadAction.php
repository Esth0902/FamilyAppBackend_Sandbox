<?php

namespace App\Actions\Notification;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\RealtimePublisher;

class MarkAllNotificationsAsReadAction
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function execute(User $user, bool $includeAllHouseholds, int $activeHouseholdId): int
    {
        $householdId = null;
        if (!$includeAllHouseholds && $activeHouseholdId > 0) {
            $canFilterByHousehold = $user
                ->households()
                ->where('households.id', $activeHouseholdId)
                ->exists();

            if ($canFilterByHousehold) {
                $householdId = $activeHouseholdId;
            }
        }

        $query = UserNotification::query()
            ->where('user_id', (int) $user->id)
            ->whereNull('read_at');

        if ($householdId !== null && $householdId > 0) {
            $query->where(function ($scopedQuery) use ($householdId): void {
                $scopedQuery
                    ->whereNull('household_id')
                    ->orWhere('household_id', $householdId);
            });
        }

        $updated = $query->update([
            'read_at' => now(),
        ]);

        if ($updated > 0) {
            $this->realtimePublisher->publishUser(
                userId: (int) $user->id,
                module: 'notifications',
                type: 'notifications.read_all',
                payload: [
                    'updated_count' => $updated,
                    'household_id' => $householdId,
                ],
            );
        }

        return $updated;
    }
}
