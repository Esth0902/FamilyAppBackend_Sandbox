<?php

namespace App\Http\Resources\Tasks;

use App\Models\TaskInstance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaskInstance */
class TaskInstanceResource extends JsonResource
{
    public static $wrap = null;

    private const STATUS_DONE = "r\u{00E9}alis\u{00E9}e";

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::toPayload($this->resource);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toPayload(TaskInstance $instance): array
    {
        return [
            'id' => (int) $instance->id,
            'task_template_id' => (int) $instance->task_template_id,
            'title' => (string) ($instance->template?->name ?? 'T\u{00E2}che'),
            'description' => $instance->template?->description,
            'due_date' => optional($instance->due_date)->toDateString(),
            'status' => (string) $instance->status,
            'completed_at' => optional($instance->completed_at)->toIso8601String(),
            'validated_by_parent' => (bool) $instance->validated_by_parent,
            'assignee' => [
                'id' => (int) ($instance->user?->id ?? 0),
                'name' => (string) ($instance->user?->name ?? 'Membre'),
            ],
            'assignees' => $instance->assignees
                ->sortBy('id')
                ->map(static fn(User $user): array => [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                ])
                ->values()
                ->all(),
            'template' => TaskTemplateResource::toInstanceTemplatePayload($instance->template),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toBoardPayload(TaskInstance $instance, string $role, int $currentUserId): array
    {
        $payload = self::toPayload($instance);

        $assigneeIds = $instance->assignees
            ->map(static fn(User $assignee): int => (int) $assignee->id)
            ->filter(static fn(int $id): bool => $id > 0)
            ->values()
            ->all();

        if (count($assigneeIds) === 0 && (int) $instance->user_id > 0) {
            $assigneeIds = [(int) $instance->user_id];
        }

        $isParent = $role === User::ROLE_PARENT;
        $isAssignedUser = in_array($currentUserId, $assigneeIds, true);

        $payload['permissions'] = [
            'can_toggle' => $isParent || $isAssignedUser,
            'can_validate' => $isParent && (string) $instance->status === self::STATUS_DONE,
            'can_cancel' => $isParent,
        ];

        return $payload;
    }
}
