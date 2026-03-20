<?php

namespace App\Actions\MealPoll;

use App\Models\MealPoll;
use App\Models\MealPollOption;
use App\Models\MealPollVote;
use App\Models\User;
use App\Services\RealtimePublisher;

class VoteMealPollAction
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    /**
     * @return array{status:int,message:string,voted:bool,poll:MealPoll}
     */
    public function execute(MealPoll $poll, User $actor, MealPollOption $option): array
    {
        $userId = (int) $actor->id;

        $existingVote = MealPollVote::query()
            ->where('meal_poll_id', $poll->id)
            ->where('user_id', $userId)
            ->where('meal_poll_option_id', $option->id)
            ->first();

        if ($existingVote instanceof MealPollVote) {
            $existingVote->delete();
            $poll->load(['options.recipe', 'votes']);

            $this->realtimePublisher->publishHousehold(
                householdId: (int) $poll->household_id,
                module: 'meal_poll',
                type: 'votes.updated',
                payload: [
                    'poll_id' => (int) $poll->id,
                    'status' => (string) $poll->status,
                    'actor_user_id' => $userId,
                ],
            );

            return [
                'status' => 200,
                'message' => 'Vote retire.',
                'voted' => false,
                'poll' => $poll,
            ];
        }

        $voteCount = MealPollVote::query()
            ->where('meal_poll_id', $poll->id)
            ->where('user_id', $userId)
            ->count();

        $maxVotes = max(1, (int) $poll->max_votes_per_user);
        if ($voteCount >= $maxVotes) {
            $poll->load(['options.recipe', 'votes']);

            return [
                'status' => 422,
                'message' => 'Vous avez atteint le nombre maximum de votes pour ce sondage.',
                'voted' => false,
                'poll' => $poll,
            ];
        }

        MealPollVote::query()->create([
            'meal_poll_id' => (int) $poll->id,
            'user_id' => $userId,
            'meal_poll_option_id' => (int) $option->id,
        ]);

        $poll->load(['options.recipe', 'votes']);
        $this->realtimePublisher->publishHousehold(
            householdId: (int) $poll->household_id,
            module: 'meal_poll',
            type: 'votes.updated',
            payload: [
                'poll_id' => (int) $poll->id,
                'status' => (string) $poll->status,
                'actor_user_id' => $userId,
            ],
        );

        return [
            'status' => 200,
            'message' => 'Vote ajoute.',
            'voted' => true,
            'poll' => $poll,
        ];
    }
}
