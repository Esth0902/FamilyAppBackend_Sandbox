<?php

namespace App\Actions\MealPoll;

use App\Models\MealPlan;
use App\Models\MealPoll;
use App\Models\User;
use App\Services\PollNotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ValidateMealPollResultsAction
{
    public function __construct(
        private readonly CollectMealPollVoteStatsAction $collectMealPollVoteStatsAction,
        private readonly PollNotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *     poll: MealPoll,
     *     selected_recipe_ids: array<int, int>,
     *     vote_stats: array<int, array{recipe_id:int,votes_count:int}>
     * }
     */
    public function execute(MealPoll $poll, User $actor, array $validated): array
    {
        $voteStats = $this->collectMealPollVoteStatsAction->execute($poll);

        $defaultSelectedRecipeIds = collect($voteStats)
            ->where('votes_count', '>', 0)
            ->sortByDesc('votes_count')
            ->pluck('recipe_id')
            ->values();

        if ($defaultSelectedRecipeIds->isEmpty()) {
            $defaultSelectedRecipeIds = $poll->options()->pluck('recipe_id')->take(3)->values();
        }

        $selectedRecipeIds = collect((array) ($validated['selected_recipe_ids'] ?? $defaultSelectedRecipeIds))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($selectedRecipeIds->isEmpty()) {
            throw ValidationException::withMessages([
                'selected_recipe_ids' => ['Aucune recette selectionnée pour validation.'],
            ]);
        }

        DB::transaction(function () use ($poll, $actor, $validated): void {
            if ((string) $poll->status !== 'validated') {
                $poll->update([
                    'status' => 'validated',
                    'closed_at' => $poll->closed_at ?? now(),
                    'validated_at' => now(),
                    'validated_by_user_id' => (int) $actor->id,
                ]);
            }

            foreach ((array) ($validated['meal_plan'] ?? []) as $entry) {
                $mealPlanUpdatePayload = [
                    'note' => $entry['note'] ?? null,
                ];

                if (Schema::hasColumn('meal_plans', 'recipe_id')) {
                    $mealPlanUpdatePayload['recipe_id'] = (int) $entry['recipe_id'];
                }

                if (Schema::hasColumn('meal_plans', 'servings')) {
                    $mealPlanUpdatePayload['servings'] = (int) ($entry['servings'] ?? 4);
                }

                $mealPlan = MealPlan::query()->updateOrCreate(
                    [
                        'household_id' => (int) $poll->household_id,
                        'date' => (string) $entry['date'],
                        'meal_type' => (string) $entry['meal_type'],
                    ],
                    $mealPlanUpdatePayload
                );

                $mealPlan->items()->delete();
                $mealPlan->items()->create([
                    'recipe_id' => (int) $entry['recipe_id'],
                    'servings' => (int) ($entry['servings'] ?? 4),
                    'position' => 1,
                ]);
            }
        });

        $poll->refresh()->load(['options.recipe', 'votes']);
        $poll->load('household.users');

        $this->notificationService->notifyPollValidated($poll);
        $this->realtimePublisher->publishHousehold(
            householdId: (int) $poll->household_id,
            module: 'meal_poll',
            type: 'poll.validated',
            payload: [
                'poll_id' => (int) $poll->id,
                'status' => (string) $poll->status,
                'actor_user_id' => (int) $actor->id,
            ],
        );

        return [
            'poll' => $poll,
            'selected_recipe_ids' => $selectedRecipeIds->all(),
            'vote_stats' => $voteStats,
        ];
    }
}
