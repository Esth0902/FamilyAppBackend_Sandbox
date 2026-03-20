<?php

namespace App\Actions\MealPoll;

use App\Models\Household;
use App\Models\MealPoll;
use App\Models\User;
use App\Services\PollNotificationService;
use App\Services\RealtimePublisher;

class GetActiveMealPollAction
{
    public function __construct(
        private readonly PollNotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function execute(Household $household, User $actor): ?MealPoll
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

        if ((string) $poll->status === 'open' && now()->greaterThan($poll->ends_at)) {
            $poll->update([
                'status' => 'closed',
                'closed_at' => $poll->closed_at ?? now(),
            ]);

            $poll->refresh();
            $poll->load('household.users');
            $this->notificationService->notifyPollClosedTooLate($poll);
            $this->realtimePublisher->publishHousehold(
                householdId: (int) $poll->household_id,
                module: 'meal_poll',
                type: 'poll.closed',
                payload: [
                    'poll_id' => (int) $poll->id,
                    'status' => (string) $poll->status,
                    'actor_user_id' => (int) $actor->id,
                ],
            );
        }

        return $poll;
    }
}
