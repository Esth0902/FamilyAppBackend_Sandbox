<?php

namespace App\Services;

use App\Models\UserNotification;
use App\Support\Normalization;

class NotificationService
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function notifyUser(
        int $userId,
        int $householdId,
        string $type,
        string $title,
        string $body,
        array $data = [],
        string $realtimeType = 'notification_created'
    ): ?UserNotification {
        if ($userId <= 0 || $householdId <= 0) {
            return null;
        }

        $notification = UserNotification::query()->create([
            'household_id' => $householdId,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data + ['household_id' => $householdId],
        ]);

        $this->realtimePublisher->publishUser(
            userId: $userId,
            module: 'notifications',
            type: $realtimeType,
            payload: [
                'notification_id' => (int) $notification->id,
                'household_id' => $householdId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
            ],
        );

        return $notification;
    }

    /**
     * @param array<int, int> $userIds
     * @param array<string, mixed> $data
     */
    public function notifyUsers(
        array $userIds,
        int $householdId,
        string $type,
        string $title,
        string $body,
        array $data = [],
        string $realtimeType = 'notification_created'
    ): void {
        $ids = collect(Normalization::memberIds($userIds))
            ->unique()
            ->values()
            ->all();

        foreach ($ids as $userId) {
            $this->notifyUser(
                userId: (int) $userId,
                householdId: $householdId,
                type: $type,
                title: $title,
                body: $body,
                data: $data,
                realtimeType: $realtimeType,
            );
        }
    }
}
