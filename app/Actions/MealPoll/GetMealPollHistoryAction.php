<?php

namespace App\Actions\MealPoll;

use App\Models\Household;
use App\Models\MealPoll;
use Illuminate\Database\Eloquent\Collection;

class GetMealPollHistoryAction
{
    /**
     * @return Collection<int, MealPoll>
     */
    public function execute(Household $household): Collection
    {
        return MealPoll::query()
            ->where('household_id', $household->id)
            ->where('status', 'validated')
            ->with(['options.recipe', 'options.votes.user', 'votes.user'])
            ->orderByDesc('validated_at')
            ->limit(20)
            ->get();
    }
}
