<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserNotification;

class UserNotificationPolicy
{
    public function view(User $user, UserNotification $notification): bool
    {
        return (int) $notification->user_id === (int) $user->id;
    }

    public function respond(User $user, UserNotification $notification): bool
    {
        return $this->view($user, $notification);
    }
}

