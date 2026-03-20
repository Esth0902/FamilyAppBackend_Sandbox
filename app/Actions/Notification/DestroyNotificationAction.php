<?php

namespace App\Actions\Notification;

use App\Models\UserNotification;

class DestroyNotificationAction
{
    public function execute(UserNotification $notification): void
    {
        $notification->delete();
    }
}

