<?php

namespace App\Actions\Household;

use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\MealPoll;
use App\Models\TaskInstance;
use Carbon\Carbon;

class GetHouseholdDashboardAction
{
    private const TASK_STATUS_TODO = 'à faire';
    private const TASK_STATUS_DONE = 'réalisée';

    /**
     * @return array<string, int>
     */
    public function execute(Household $household): array
    {
        $pollsOpenCount = MealPoll::query()
            ->where('household_id', $household->id)
            ->where('status', 'open')
            ->count();

        $pollsClosedCount = MealPoll::query()
            ->where('household_id', $household->id)
            ->whereIn('status', ['closed', 'validated'])
            ->count();

        $tasksEnabled = (bool) HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->value('has_tasks');

        $tasksTodoCount = 0;
        $tasksDoneCount = 0;
        $tasksValidatedCount = 0;

        if ($tasksEnabled) {
            $weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();
            $weekEnd = now()->endOfWeek(Carbon::SUNDAY)->toDateString();

            $tasksBaseQuery = TaskInstance::query()
                ->whereHas('template', fn ($query) => $query->where('household_id', $household->id))
                ->whereBetween('due_date', [$weekStart, $weekEnd]);

            $tasksTodoCount = (clone $tasksBaseQuery)
                ->where('status', self::TASK_STATUS_TODO)
                ->count();
            $tasksDoneCount = (clone $tasksBaseQuery)
                ->where('status', self::TASK_STATUS_DONE)
                ->count();
            $tasksValidatedCount = (clone $tasksBaseQuery)
                ->where('validated_by_parent', true)
                ->count();
        }

        return [
            'polls_open_count' => $pollsOpenCount,
            'polls_closed_count' => $pollsClosedCount,
            'tasks_todo_count' => $tasksTodoCount,
            'tasks_done_count' => $tasksDoneCount,
            'tasks_validated_count' => $tasksValidatedCount,
        ];
    }
}
