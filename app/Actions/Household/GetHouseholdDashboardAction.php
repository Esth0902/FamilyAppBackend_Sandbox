<?php

namespace App\Actions\Household;

use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\MealPoll;
use App\Models\MealPollVote;
use App\Models\TaskInstance;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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

        $pollsPayload = $polls
            ->map(fn(MealPoll $poll): array => $this->toDashboardPollPayload($poll))
            ->values();

        $openPolls = $pollsPayload
            ->filter(fn(array $poll): bool => ($poll['status'] ?? null) === 'open')
            ->values();

        $closedPolls = $pollsPayload
            ->filter(fn(array $poll): bool => in_array((string) ($poll['status'] ?? ''), ['closed', 'validated'], true))
            ->values();

        $favoriteRecipes = $this->buildFavoriteRecipesPayload((int) $household->id);
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
            'household_name' => (string) $household->name,
            'members' => $household->users,
            'active_poll' => $openPolls->first(),
            'polls_open' => $openPolls,
            'polls_closed' => $closedPolls,
            'polls' => $pollsPayload,
            'favorite_recipes' => $favoriteRecipes,
            'tasks_summary' => $tasksSummary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toDashboardPollPayload(MealPoll $poll): array
    {
        $votesByOption = $poll->votes->groupBy('meal_poll_option_id');

        $options = $poll->options
            ->sortBy('id')
            ->map(function ($option) use ($votesByOption): array {
                $optionVotes = $votesByOption->get($option->id, collect());

                return [
                    'id' => (int) $option->id,
                    'recipe_id' => (int) $option->recipe_id,
                    'title' => (string) ($option->recipe?->title ?? 'Recette'),
                    'votes_count' => (int) $optionVotes->count(),
                ];
            })
            ->values();

        $votesByUser = $poll->votes
            ->groupBy('user_id')
            ->map(function (Collection $userVotes, $userId): array {
                $firstVote = $userVotes->first();
                $name = (string) ($firstVote?->user?->name ?? 'Utilisateur');

                return [
                    'user_id' => (int) $userId,
                    'name' => $name,
                    'votes_count' => (int) $userVotes->count(),
                ];
            })
            ->values();

        return [
            'id' => (int) $poll->id,
            'title' => $poll->title,
            'status' => (string) $poll->status,
            'starts_at' => optional($poll->starts_at)->toIso8601String(),
            'ends_at' => optional($poll->ends_at)->toIso8601String(),
            'planning_start_date' => optional($poll->planning_start_date)->toDateString(),
            'planning_end_date' => optional($poll->planning_end_date)->toDateString(),
            'max_votes_per_user' => (int) $poll->max_votes_per_user,
            'total_votes' => (int) $poll->votes->count(),
            'options' => $options,
            'voters_summary' => $votesByUser,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFavoriteRecipesPayload(int $householdId): array
    {
        return MealPollVote::query()
            ->join('meal_poll_options', 'meal_poll_options.id', '=', 'meal_poll_votes.meal_poll_option_id')
            ->join('meal_polls', 'meal_polls.id', '=', 'meal_poll_votes.meal_poll_id')
            ->join('recipes', 'recipes.id', '=', 'meal_poll_options.recipe_id')
            ->where('meal_polls.household_id', $householdId)
            ->whereIn('meal_polls.status', ['closed', 'validated'])
            ->groupBy('meal_poll_options.recipe_id', 'recipes.title')
            ->selectRaw('meal_poll_options.recipe_id as recipe_id')
            ->selectRaw('recipes.title as title')
            ->selectRaw('COUNT(meal_poll_votes.id) as votes_count')
            ->selectRaw('COUNT(DISTINCT meal_poll_votes.meal_poll_id) as polls_count')
            ->orderByDesc('votes_count')
            ->orderBy('recipes.title')
            ->limit(10)
            ->get()
            ->map(static fn($row): array => [
                'recipe_id' => (int) $row->recipe_id,
                'title' => (string) $row->title,
                'votes_count' => (int) $row->votes_count,
                'polls_count' => (int) $row->polls_count,
            ])
            ->values()
            ->all();
    }
}

