<?php

namespace App\Http\Resources\Tasks;

use App\Models\TaskInstance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskBoardResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fromDate = $this->resource['from_date'] ?? null;
        $toDate = $this->resource['to_date'] ?? null;
        $alternatingCustody = is_array($this->resource['alternating_custody'] ?? null)
            ? $this->resource['alternating_custody']
            : [];
        $currentUser = is_array($this->resource['current_user'] ?? null)
            ? $this->resource['current_user']
            : [];

        $currentUserId = (int) ($currentUser['id'] ?? 0);
        $currentUserRole = (string) ($currentUser['role'] ?? User::ROLE_CHILD);

        return [
            'tasks_enabled' => (bool) ($this->resource['tasks_enabled'] ?? false),
            'range' => [
                'from' => $fromDate instanceof Carbon ? $fromDate->toDateString() : null,
                'to' => $toDate instanceof Carbon ? $toDate->toDateString() : null,
            ],
            'settings' => [
                'alternating_custody_enabled' => (bool) ($alternatingCustody['enabled'] ?? false),
                'custody_change_day' => (int) ($alternatingCustody['change_day'] ?? 5),
                'custody_home_week_start' => $alternatingCustody['home_week_start'] ?? null,
            ],
            'can_manage_templates' => (bool) ($this->resource['can_manage_templates'] ?? false),
            'can_manage_instances' => (bool) ($this->resource['can_manage_instances'] ?? false),
            'current_user' => [
                'id' => $currentUserId,
                'role' => $currentUserRole,
            ],
            'members' => collect($this->resource['members'] ?? [])
                ->map(static fn (array $member): array => [
                    'id' => (int) ($member['id'] ?? 0),
                    'name' => (string) ($member['name'] ?? ''),
                    'role' => (string) ($member['role'] ?? User::ROLE_CHILD),
                ])
                ->values()
                ->all(),
            'templates' => TaskTemplateResource::collection($this->resource['templates'] ?? collect())
                ->resolve($request),
            'instances' => collect($this->resource['instances'] ?? [])
                ->map(static fn (TaskInstance $instance): array => TaskInstanceResource::toBoardPayload(
                    $instance,
                    $currentUserRole,
                    $currentUserId
                ))
                ->values()
                ->all(),
        ];
    }
}
