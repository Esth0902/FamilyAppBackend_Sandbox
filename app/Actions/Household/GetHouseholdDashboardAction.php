<?php

namespace App\Actions\Household;

use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\MealPoll;
use App\Models\MealPollVote;
use App\Models\TaskInstance;
use Carbon\Carbon;

class GetHouseholdDashboardAction
{
    private const TASK_STATUS_TODO = 'à faire';
    private const TASK_STATUS_DONE = 'réalisée';

    /**
     * @return array<string, mixed>
     */
    public function execute(Household $household): array
    {
        $household->loadMissing('users');

        $settings = HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->first();

        $polls = MealPoll::query()
            ->where('household_id', $household->id)
            ->with(['options.recipe', 'votes.user'])
            ->orderByDesc('starts_at')
            ->get();

        $favoriteRecipeRows = MealPollVote::query()
            ->join('meal_poll_options', 'meal_poll_options.id', '=', 'meal_poll_votes.meal_poll_option_id')
            ->join('meal_polls', 'meal_polls.id', '=', 'meal_poll_votes.meal_poll_id')
            ->join('recipes', 'recipes.id', '=', 'meal_poll_options.recipe_id')
            ->where('meal_polls.household_id', (int) $household->id)
            ->whereIn('meal_polls.status', ['closed', 'validated'])
            ->groupBy('meal_poll_options.recipe_id', 'recipes.title')
            ->selectRaw('meal_poll_options.recipe_id as recipe_id')
            ->selectRaw('recipes.title as title')
            ->selectRaw('COUNT(meal_poll_votes.id) as votes_count')
            ->selectRaw('COUNT(DISTINCT meal_poll_votes.meal_poll_id) as polls_count')
            ->orderByDesc('votes_count')
            ->orderBy('recipes.title')
            ->limit(10)
            ->get();

        $tasksEnabled = (bool) ($settings?->has_tasks ?? false);
        $weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd = now()->endOfWeek(Carbon::SUNDAY)->toDateString();
        $tasksSummary = [
            'enabled' => $tasksEnabled,
            'range' => [
                'from' => $weekStart,
                'to' => $weekEnd,
            ],
            'todo_count' => 0,
            'done_count' => 0,
            'validated_count' => 0,
        ];

        if ($tasksEnabled) {
            $taskInstances = TaskInstance::query()
                ->whereHas('template', fn($query) => $query->where('household_id', $household->id))
                ->whereBetween('due_date', [$weekStart, $weekEnd])
                ->get(['status', 'validated_by_parent']);

            $tasksSummary['todo_count'] = $taskInstances
                ->filter(fn(TaskInstance $instance): bool => (string) $instance->status === self::TASK_STATUS_TODO)
                ->count();
            $tasksSummary['done_count'] = $taskInstances
                ->filter(fn(TaskInstance $instance): bool => (string) $instance->status === self::TASK_STATUS_DONE)
                ->count();
            $tasksSummary['validated_count'] = $taskInstances
                ->filter(fn(TaskInstance $instance): bool => (bool) $instance->validated_by_parent)
                ->count();
        }

        return [
            'household' => $household,
            'polls' => $polls,
            'favorite_recipe_rows' => $favoriteRecipeRows,
            'tasks_summary' => $tasksSummary,
        ];
    }
}

