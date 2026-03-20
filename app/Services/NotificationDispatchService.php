<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

class NotificationDispatchService
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function createUserNotification(
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

    /**
     * @return array<int, int>
     */
    public function resolveParentUserIds(int $householdId): array
    {
        return DB::table('household_user')
            ->where('household_id', $householdId)
            ->where('role', User::ROLE_PARENT)
            ->pluck('user_id')
            ->map(static fn ($userId): int => (int) $userId)
            ->filter(static fn (int $userId): bool => $userId > 0)
            ->values()
            ->all();
    }

    public function publishNotificationCreated(UserNotification $notification): void
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

