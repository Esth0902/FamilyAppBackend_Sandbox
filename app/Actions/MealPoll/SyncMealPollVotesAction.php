<?php

namespace App\Actions\MealPoll;

use App\Models\MealPoll;
use App\Models\MealPollVote;
use App\Models\User;
use App\Services\RealtimePublisher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SyncMealPollVotesAction
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    /**
     * @param  Collection<int, int>  $optionIds
     */
    public function execute(MealPoll $poll, User $actor, Collection $optionIds): MealPoll
    {
        DB::transaction(function () use ($poll, $actor, $optionIds): void {
            MealPollVote::query()
                ->where('meal_poll_id', $poll->id)
                ->where('user_id', $actor->id)
                ->delete();

            foreach ($optionIds as $optionId) {
                MealPollVote::query()->create([
                    'meal_poll_id' => (int) $poll->id,
                    'user_id' => (int) $actor->id,
                    'meal_poll_option_id' => (int) $optionId,
                ]);
            }
        });

        $poll->load(['options.recipe', 'votes']);
        $this->realtimePublisher->publishHousehold(
            householdId: (int) $poll->household_id,
            module: 'meal_poll',
            type: 'votes.updated',
            payload: [
                'poll_id' => (int) $poll->id,
                'status' => (string) $poll->status,
                'actor_user_id' => (int) $actor->id,
            ],
        );

        return $poll;
    }
}
