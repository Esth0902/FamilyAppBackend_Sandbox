<?php

namespace App\Http\Resources\Notification;

use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserNotification */
class UserNotificationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];
        $typeMeta = $this->resolveTypeMeta((string) $this->type);

        return [
            'id' => (int) $this->id,
            'household_id' => $this->household_id !== null ? (int) $this->household_id : null,
            'type' => (string) $this->type,
            'type_label' => $typeMeta['label'],
            'type_icon' => $typeMeta['icon'],
            'title' => (string) $this->title,
            'body' => (string) $this->body,
            'data' => $data,
            'payload' => $this->resolvePayload($data),
            'scheduled_for' => optional($this->scheduled_for)->toIso8601String(),
            'sent_at' => optional($this->sent_at)->toIso8601String(),
            'read_at' => optional($this->read_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'created_at_human' => optional($this->created_at)?->diffForHumans(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolvePayload(array $data): array
    {
        return [
            'status' => (string) ($data['status'] ?? 'pending'),
            'household_id' => isset($data['household_id']) ? (int) $data['household_id'] : null,
            'household_name' => isset($data['household_name']) ? (string) $data['household_name'] : null,
            'action_required' => in_array((string) $this->type, [
                'household_invite',
                'household_link_request',
                'task_reassignment_invite',
                'household_deletion_approval_request',
                'household_deletion_cancel_window',
            ], true),
        ];
    }

    /**
     * @return array{label:string,icon:string}
     */
    private function resolveTypeMeta(string $type): array
    {
        return match ($type) {
            'household_invite' => ['label' => 'Invitation foyer', 'icon' => 'account-group-outline'],
            'household_link_request' => ['label' => 'Demande de liaison', 'icon' => 'link-variant'],
            'household_link_request_responded' => ['label' => 'Reponse liaison', 'icon' => 'link-check'],
            'task_reassignment_invite' => ['label' => 'Reprise de tache', 'icon' => 'swap-horizontal-bold'],
            'task_reassignment_invite_responded' => ['label' => 'Reponse reprise de tache', 'icon' => 'check-decagram-outline'],
            'household_deletion_approval_request' => ['label' => 'Validation suppression', 'icon' => 'alert-outline'],
            'household_deletion_cancel_window' => ['label' => 'Suppression planifiee', 'icon' => 'timer-sand'],
            'household_deletion_scheduled' => ['label' => 'Suppression programmee', 'icon' => 'calendar-clock-outline'],
            'household_deletion_request_refused' => ['label' => 'Suppression refusee', 'icon' => 'close-octagon-outline'],
            'household_deletion_request_accepted' => ['label' => 'Validation suppression recue', 'icon' => 'check-circle-outline'],
            'household_deletion_cancelled' => ['label' => 'Suppression annulee', 'icon' => 'cancel'],
            default => ['label' => 'Notification', 'icon' => 'bell-outline'],
        };
    }
}

