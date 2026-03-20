<?php

namespace App\Actions\MealPoll;

use App\Models\MealPoll;
use App\Models\MealPollOption;
use App\Models\MealSetting;
use App\Models\User;
use App\Services\RealtimePublisher;
use Illuminate\Support\Facades\DB;

class UpdateMealPollAction
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(MealPoll $poll, User $actor, array $validated): MealPoll
    {
        $mealSettings = MealSetting::query()
            ->where('household_id', $poll->household_id)
            ->first();

        $recipeIds = collect((array) ($validated['recipe_ids'] ?? []))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $durationHours = (int) ($validated['duration_hours'] ?? ($mealSettings?->poll_duration ?? 24));
        $maxVotesPerUser = (int) ($validated['max_votes_per_user'] ?? ($mealSettings?->max_votes_per_user ?? 3));
        $maxVotesPerUser = max(1, min($maxVotesPerUser, 20));
        $planningStartDate = (string) ($validated['planning_start_date'] ?? now()->toDateString());
        $planningEndDate = (string) ($validated['planning_end_date'] ?? now()->addDays(6)->toDateString());

        DB::transaction(function () use (
            $poll,
            $validated,
            $recipeIds,
            $durationHours,
            $maxVotesPerUser,
            $planningStartDate,
            $planningEndDate
        ): void {
            $poll->update([
                'title' => trim((string) ($validated['title'] ?? '')) ?: null,
                'ends_at' => now()->addHours($durationHours),
                'planning_start_date' => $planningStartDate,
                'planning_end_date' => $planningEndDate,
                'max_votes_per_user' => $maxVotesPerUser,
            ]);

            $existingByRecipe = $poll->options()
                ->get()
                ->keyBy(static fn (MealPollOption $option): int => (int) $option->recipe_id);

            $recipeIds->each(function (int $recipeId) use ($poll, $existingByRecipe): void {
                if ($existingByRecipe->has($recipeId)) {
                    return;
                }

                $poll->options()->create([
                    'recipe_id' => $recipeId,
                ]);
            });

            $poll->options()
                ->whereNotIn('recipe_id', $recipeIds->all())
                ->delete();
        });

        $poll->refresh()->load(['options.recipe', 'votes']);

        $this->realtimePublisher->publishHousehold(
            householdId: (int) $poll->household_id,
            module: 'meal_poll',
            type: 'poll.updated',
            payload: [
                'poll_id' => (int) $poll->id,
                'status' => (string) $poll->status,
                'actor_user_id' => (int) $actor->id,
            ],
        );

        return $poll;
    }
}
