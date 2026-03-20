<?php

namespace App\Actions\MealPoll;

use App\Models\MealPlan;
use App\Models\MealPoll;
use App\Models\MealPollOption;
use App\Models\User;
use App\Services\PollNotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CloseMealPollAction
{
    public function __construct(
        private readonly PollNotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    /**
     * @return array{poll:MealPoll,winner_recipe_id:int|null}
     */
    public function execute(MealPoll $poll, User $actor): array
    {
        $winnerOption = MealPollOption::query()
            ->where('meal_poll_id', $poll->id)
            ->withCount('votes')
            ->orderByDesc('votes_count')
            ->orderBy('id')
            ->first();
        $winnerRecipeId = $winnerOption?->recipe_id ? (int) $winnerOption->recipe_id : null;

        DB::transaction(function () use ($poll, $winnerRecipeId): void {
            if ((string) $poll->status !== 'closed') {
                $poll->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'close_request_sent_at' => now(),
                ]);
            }

            if ($winnerRecipeId === null) {
                return;
            }

            $targetDate = optional($poll->planning_start_date)->toDateString() ?? now()->toDateString();
            $mealPlanUpdatePayload = [
                'note' => 'Repas gagnant du sondage',
            ];

            if (Schema::hasColumn('meal_plans', 'recipe_id')) {
                $mealPlanUpdatePayload['recipe_id'] = $winnerRecipeId;
            }

            if (Schema::hasColumn('meal_plans', 'servings')) {
                $mealPlanUpdatePayload['servings'] = 4;
            }

            $mealPlan = MealPlan::query()->updateOrCreate(
                [
                    'household_id' => (int) $poll->household_id,
                    'date' => $targetDate,
                    'meal_type' => 'soir',
                ],
                $mealPlanUpdatePayload
            );

            $mealPlan->items()->delete();
            $mealPlan->items()->create([
                'recipe_id' => $winnerRecipeId,
                'servings' => 4,
                'position' => 1,
            ]);
        });

        $poll->refresh()->load(['options.recipe', 'votes']);
        $poll->load('household.users');

        $this->notificationService->notifyPollClosedTooLate($poll);
        $this->notificationService->notifyPollNeedsValidation($poll);
        $this->notificationService->notifyPollWinner($poll, $winnerRecipeId);

        $this->realtimePublisher->publishHousehold(
            householdId: (int) $poll->household_id,
            module: 'meal_poll',
            type: 'poll.closed',
            payload: [
                'poll_id' => (int) $poll->id,
                'status' => (string) $poll->status,
                'winner_recipe_id' => $winnerRecipeId,
                'actor_user_id' => (int) $actor->id,
            ],
        );

        return [
            'poll' => $poll,
            'winner_recipe_id' => $winnerRecipeId,
        ];
    }
}
