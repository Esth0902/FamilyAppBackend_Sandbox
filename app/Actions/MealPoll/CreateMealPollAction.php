<?php

namespace App\Actions\MealPoll;

use App\Models\Household;
use App\Models\MealPoll;
use App\Models\MealSetting;
use App\Models\User;
use App\Services\PollNotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Support\Facades\DB;

class CreateMealPollAction
{
    public function __construct(
        private readonly PollNotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(Household $household, User $actor, array $validated): MealPoll
    {
        $mealSettings = MealSetting::query()
            ->where('household_id', $household->id)
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

        $poll = DB::transaction(function () use (
            $household,
            $actor,
            $validated,
            $recipeIds,
            $durationHours,
            $maxVotesPerUser,
            $planningStartDate,
            $planningEndDate
        ): MealPoll {
            $poll = MealPoll::query()->create([
                'household_id' => (int) $household->id,
                'title' => trim((string) ($validated['title'] ?? '')) ?: null,
                'created_by_user_id' => (int) $actor->id,
                'starts_at' => now(),
                'ends_at' => now()->addHours($durationHours),
                'planning_start_date' => $planningStartDate,
                'planning_end_date' => $planningEndDate,
                'status' => 'open',
                'max_votes_per_user' => $maxVotesPerUser,
            ]);

            $poll->options()->createMany(
                $recipeIds
                    ->map(fn (int $recipeId): array => ['recipe_id' => $recipeId])
                    ->all()
            );

            return $poll->load(['options.recipe', 'votes']);
        });

        $poll->load('household.users');
        $this->notificationService->notifyPollOpened($poll);
        $this->realtimePublisher->publishHousehold(
            householdId: (int) $poll->household_id,
            module: 'meal_poll',
            type: 'poll.opened',
            payload: [
                'poll_id' => (int) $poll->id,
                'status' => (string) $poll->status,
                'actor_user_id' => (int) $actor->id,
            ],
        );

        return $poll;
    }
}
