<?php

namespace App\Http\Resources\Household;

use App\Models\Household;
use App\Models\MealPoll;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class HouseholdDashboardResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $household = $this->resource['household'] ?? null;
        if (!$household instanceof Household) {
            return [];
        }

        $polls = $this->resource['polls'] ?? collect();
        $pollsPayload = collect($polls)
            ->map(fn(MealPoll $poll): array => $this->toDashboardPollPayload($poll))
            ->values();

        $openPolls = $pollsPayload
            ->filter(fn(array $poll): bool => ($poll['status'] ?? null) === 'open')
            ->values();

        $closedPolls = $pollsPayload
            ->filter(fn(array $poll): bool => in_array((string) ($poll['status'] ?? ''), ['closed', 'validated'], true))
            ->values();

        $favoriteRecipeRows = $this->resource['favorite_recipe_rows'] ?? collect();
        $tasksSummary = is_array($this->resource['tasks_summary'] ?? null)
            ? $this->resource['tasks_summary']
            : [];

        return [
            'household_name' => (string) $household->name,
            'members' => HouseholdMemberResource::collection($household->users)->resolve($request),
            'active_poll' => $openPolls->first(),
            'polls_open' => $openPolls,
            'polls_closed' => $closedPolls,
            'polls' => $pollsPayload,
            'favorite_recipes' => $this->buildFavoriteRecipesPayload($favoriteRecipeRows),
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
            'title' => (string) $poll->title,
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
     * @param mixed $favoriteRecipeRows
     * @return array<int, array<string, mixed>>
     */
    private function buildFavoriteRecipesPayload(mixed $favoriteRecipeRows): array
    {
        return collect($favoriteRecipeRows)
            ->map(static fn($row): array => [
                'recipe_id' => (int) ($row->recipe_id ?? 0),
                'title' => (string) ($row->title ?? ''),
                'votes_count' => (int) ($row->votes_count ?? 0),
                'polls_count' => (int) ($row->polls_count ?? 0),
            ])
            ->values()
            ->all();
    }
}

