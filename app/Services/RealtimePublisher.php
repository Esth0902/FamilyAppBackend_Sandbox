<?php

namespace App\Services;

use App\Events\HouseholdRealtimeEvent;
use App\Events\UserRealtimeEvent;

class RealtimePublisher
{
    public function __construct(private readonly PushNotificationService $pushNotificationService)
    {
    }

    public function publishHousehold(
        int $householdId,
        string $module,
        string $type,
        array $payload = []
    ): void {
        event(new HouseholdRealtimeEvent(
            householdId: $householdId,
            module: $module,
            type: $type,
            payload: $payload,
        ));
    }

    public function publishUser(
        int $userId,
        string $module,
        string $type,
        array $payload = []
    ): void {
        event(new UserRealtimeEvent(
            userId: $userId,
            module: $module,
            type: $type,
            payload: $payload,
        ));

        $this->dispatchPushIfEligible($userId, $module, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchPushIfEligible(int $userId, string $module, array $payload): void
    {
        if ($module !== 'notifications' || $userId <= 0) {
            return;
        }

        $title = trim((string) ($payload['title'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        $notificationId = (int) ($payload['notification_id'] ?? 0);

        if ($title === '' || $body === '' || $notificationId <= 0) {
            return;
        }

        $this->pushNotificationService->sendToUser(
            userId: $userId,
            title: $title,
            body: $body,
            data: [
                'notification_id' => $notificationId,
                'notification_type' => (string) ($payload['type'] ?? $payload['notification_type'] ?? ''),
                'household_id' => (int) ($payload['household_id'] ?? 0),
            ],
        );
    }
}
