<?php

namespace App\Http\Resources\Tasks;

use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserNotification */
class TaskInvitationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, int|string>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'notification_id' => (int) $this->id,
            'status' => (string) ($data['status'] ?? 'pending'),
            'task_instance_id' => (int) ($data['task_instance_id'] ?? 0),
            'invited_user_id' => (int) ($data['invited_user_id'] ?? 0),
        ];
    }
}
