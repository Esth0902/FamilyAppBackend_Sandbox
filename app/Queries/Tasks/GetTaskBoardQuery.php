<?php

namespace App\Queries\Tasks;

use App\Domain\Tasks\Services\TaskRecurrenceService;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\TaskInstance;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\Tasks\TaskInstanceGenerationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GetTaskBoardQuery
{
    public function __construct(
        private readonly TaskRecurrenceService $taskRecurrenceService,
        private readonly TaskInstanceGenerationService $taskInstanceGenerationService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Household $household, string $role, int $currentUserId, Carbon $fromDate, Carbon $toDate): array
    {
        $tasksEnabled = $this->isTasksModuleEnabled($household);
        $members = $this->resolveHouseholdMembers($household);
        $alternatingCustody = $this->resolveAlternatingCustodySettings($household);
        $interHouseholdWeekStartDay = $this->taskRecurrenceService->resolveInterHouseholdWeekStartDay($alternatingCustody);

        $templates = TaskTemplate::query()
            ->where('household_id', $household->id)
            ->with('fixedUser:id,name')
            ->orderBy('id')
            ->get();

        if ($tasksEnabled) {
            $this->taskInstanceGenerationService->ensureRecurringInstances(
                $templates,
                $members,
                $fromDate,
                $toDate,
                $alternatingCustody,
                (int) $household->id,
                $interHouseholdWeekStartDay,
            );
        }

        $instances = TaskInstance::query()
            ->whereHas('template', fn ($query) => $query->where('household_id', $household->id))
            ->whereBetween('due_date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->with([
                'template:id,household_id,name,description,recurrence,start_date,end_date,recurrence_days,assignee_user_ids,rotation_user_ids,is_rotation,rotation_cycle_weeks,is_inter_household_alternating,inter_household_week_start,fixed_user_id',
                'user:id,name',
                'assignees:id,name',
            ])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        return [
            'tasks_enabled' => $tasksEnabled,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'alternating_custody' => $alternatingCustody,
            'can_manage_templates' => $role === User::ROLE_PARENT,
            'can_manage_instances' => $tasksEnabled,
            'current_user' => [
                'id' => $currentUserId,
                'role' => $role,
            ],
            'members' => $members,
            'templates' => $templates,
            'instances' => $instances,
        ];
    }

    private function isTasksModuleEnabled(Household $household): bool
    {
        $settings = HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->first();

        return (bool) ($settings?->has_tasks ?? false);
    }

    /**
     * @return Collection<int, array{id:int,name:string,role:string}>
     */
    private function resolveHouseholdMembers(Household $household): Collection
    {
        return $household->users()
            ->select('users.id', 'users.name')
            ->orderBy('users.id')
            ->get()
            ->map(static function (User $user): array {
                return [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'role' => (string) ($user->pivot->role ?? User::ROLE_CHILD),
                ];
            })
            ->values();
    }

    /**
     * @return array{enabled:bool,change_day:int,home_week_start:string|null}
     */
    private function resolveAlternatingCustodySettings(Household $household): array
    {
        $settings = HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->first();
        $tasksConfig = is_array($settings?->tasks_config) ? $settings->tasks_config : [];

        return $this->taskRecurrenceService->resolveAlternatingCustodySettings($tasksConfig);
    }
}