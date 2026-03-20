<?php

namespace App\Http\Resources\MealPoll;

use App\Models\MealPoll;
use App\Models\MealPollOption;
use App\Models\MealPollVote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin MealPoll */
class MealPollResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'options.recipe',
            'options.votes.user',
            'votes.user',
        ]);

        $currentUserId = (int) ($request->user()?->id ?? 0);
        $votes = $this->relationLoaded('votes')
            ? $this->votes
            : collect();
        $totalVotes = $votes->count();
        $myVotedOptionIds = $votes
            ->filter(fn (MealPollVote $vote): bool => (int) $vote->user_id === $currentUserId)
            ->pluck('meal_poll_option_id')
            ->map(fn ($optionId): int => (int) $optionId)
            ->unique()
            ->values();

        return [
            'id' => (int) $this->id,
            'household_id' => (int) $this->household_id,
            'title' => $this->title,
            'status' => (string) $this->status,
            'starts_at' => optional($this->starts_at)->toIso8601String(),
            'ends_at' => optional($this->ends_at)->toIso8601String(),
            'planning_start_date' => optional($this->planning_start_date)->toDateString(),
            'planning_end_date' => optional($this->planning_end_date)->toDateString(),
            'closed_at' => optional($this->closed_at)->toIso8601String(),
            'validated_at' => optional($this->validated_at)->toIso8601String(),
            'max_votes_per_user' => (int) $this->max_votes_per_user,
            'total_votes' => $totalVotes,
            'my_votes_count' => $myVotedOptionIds->count(),
            'my_voted_option_ids' => $myVotedOptionIds->all(),
            'voters_summary' => $this->buildVotersSummary($votes, $request),
            'options' => $this->options
                ->sortBy('id')
                ->values()
                ->map(
                    fn (MealPollOption $option): array => MealPollOptionResource::forPoll(
                        $option,
                        $currentUserId,
                        $totalVotes
                    )->resolve($request)
                )
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, MealPollVote>  $votes
     * @return array<int, array{user_id:int,name:string,votes_count:int}>
     */
    private function buildVotersSummary(Collection $votes, Request $request): array
    {
        return $votes
            ->groupBy(fn (MealPollVote $vote): int => (int) $vote->user_id)
            ->map(function (Collection $votesByUser, int|string $userId) use ($request): array {
                /** @var MealPollVote|null $firstVote */
                $firstVote = $votesByUser->first();

                return MealPollVoteResource::summary(
                    userId: (int) $userId,
                    name: (string) ($firstVote?->user?->name ?? 'Utilisateur'),
                    votesCount: $votesByUser->count(),
                )->resolve($request);
            })
            ->sortByDesc('votes_count')
            ->values()
            ->all();
    }
}
