<?php

namespace App\Actions\MealPoll;

use App\Models\Household;
use App\Models\MealPoll;

class GetActiveMealPollAction
{
    public function __construct()
    {
    }

    public function execute(Household $household): ?MealPoll
    {
        $poll = MealPoll::query()
            ->where('household_id', $household->id)
            ->whereIn('status', ['open', 'closed'])
            ->orderByDesc('starts_at')
            ->with(['options.recipe', 'votes'])
            ->first();

        if (!$poll instanceof MealPoll) {
            return null;
        }

        return $poll;
    }
}
