<?php

namespace App\Actions\Notification;

use App\Models\UserNotification;

class MarkNotificationAsReadAction
{
    public function execute(UserNotification $notification): void
    {
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }
    }
}

