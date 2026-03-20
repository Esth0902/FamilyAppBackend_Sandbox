<?php

namespace App\Actions\MealPoll;

use App\Models\MealPoll;
use App\Models\MealPollVote;
use Illuminate\Support\Facades\DB;

class CollectMealPollVoteStatsAction
{
    /**
     * @return array<int, array{recipe_id:int,votes_count:int}>
     */
    public function execute(MealPoll $poll): array
    {
        $stats = MealPollVote::query()
            ->select('meal_poll_options.recipe_id', DB::raw('COUNT(*) as votes_count'))
            ->join('meal_poll_options', 'meal_poll_options.id', '=', 'meal_poll_votes.meal_poll_option_id')
            ->where('meal_poll_votes.meal_poll_id', $poll->id)
            ->groupBy('meal_poll_options.recipe_id')
            ->orderByDesc('votes_count')
            ->get();

        return $stats
            ->map(static fn ($row): array => [
                'recipe_id' => (int) $row->recipe_id,
                'votes_count' => (int) $row->votes_count,
            ])
            ->values()
            ->all();
    }
}
