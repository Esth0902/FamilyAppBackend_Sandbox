<?php

namespace App\Http\Requests\Notification;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Http\FormRequest;

class MarkNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $notification = $this->notification();

        if (!$user instanceof User || !$notification instanceof UserNotification) {
            return false;
        }

        return $user->can('view', $notification);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function notification(): ?UserNotification
    {
        $notification = $this->route('notification');

        return $notification instanceof UserNotification ? $notification : null;
    }
}

