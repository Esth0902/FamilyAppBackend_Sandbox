<?php

namespace App\Actions\MealPoll;

use App\Models\Household;
use App\Models\MealPoll;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetMealPollHistoryAction
{
    public function execute(Household $household, int $limit = 20): LengthAwarePaginator
    {
        $limit = max(1, min($limit, 100));

        return MealPoll::query()
            ->where('household_id', $household->id)
            ->where('status', 'validated')
            ->with(['options.recipe', 'options.votes.user', 'votes.user'])
            ->orderByDesc('validated_at')
            ->paginate($limit);
    }
}
