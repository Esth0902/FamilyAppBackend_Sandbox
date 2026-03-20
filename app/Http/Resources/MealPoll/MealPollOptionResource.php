<?php

namespace App\Http\Resources\MealPoll;

use App\Models\MealPollOption;
use App\Models\MealPollVote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MealPollOption */
class MealPollOptionResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        mixed $resource,
        private readonly int $currentUserId = 0,
        private readonly int $totalVotes = 0,
    ) {
        parent::__construct($resource);
    }

    public static function forPoll(MealPollOption $option, int $currentUserId, int $totalVotes): self
    {
        return new self($option, $currentUserId, $totalVotes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['recipe', 'votes.user']);

        $votes = $this->relationLoaded('votes')
            ? $this->votes
            : collect();
        $votesCountAttribute = $this->resource->getAttribute('votes_count');
        $votesCount = is_numeric($votesCountAttribute)
            ? (int) $votesCountAttribute
            : $votes->count();
        $votesPercentage = $this->totalVotes > 0
            ? round(($votesCount / $this->totalVotes) * 100, 2)
            : 0.0;

        return [
            'id' => (int) $this->id,
            'recipe_id' => (int) $this->recipe_id,
            'votes_count' => $votesCount,
            'votes_percentage' => $votesPercentage,
            'is_voted_by_me' => $this->currentUserId > 0
                && $votes->contains(
                    fn (MealPollVote $vote): bool => (int) $vote->user_id === $this->currentUserId
                ),
            'voters' => $votes
                ->sortBy(
                    static fn (MealPollVote $vote): string => strtolower((string) ($vote->user?->name ?? ''))
                )
                ->values()
                ->map(
                    fn (MealPollVote $vote): array => MealPollVoteResource::make($vote)->resolve($request)
                )
                ->all(),
            'recipe' => [
                'id' => $this->recipe?->id ? (int) $this->recipe->id : null,
                'title' => $this->recipe?->title,
                'type' => $this->recipe?->type,
                'description' => $this->recipe?->description,
            ],
        ];
    }
}
