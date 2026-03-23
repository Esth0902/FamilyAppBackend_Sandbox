<?php

namespace App\Http\Requests\MealPoll;

use App\Models\MealPoll;
use App\Models\MealSetting;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;

class StoreMealPollRequest extends MealPollContextRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user instanceof User) {
            return false;
        }

        $household = $this->household();
        $this->ensurePollModuleEnabled($household);

        return $user->can('create', [MealPoll::class, (int) $household->id]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:150'],
            'recipe_ids' => ['required', 'array', 'min:2', 'max:20'],
            'recipe_ids.*' => ['required', 'integer', 'exists:recipes,id'],
            'duration_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'max_votes_per_user' => ['nullable', 'integer', 'min:1', 'max:20'],
            'planning_start_date' => ['nullable', 'date_format:Y-m-d', 'required_with:planning_end_date'],
            'planning_end_date' => ['nullable', 'date_format:Y-m-d', 'required_with:planning_start_date', 'after_or_equal:planning_start_date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $household = $this->household();
            $mealSettings = MealSetting::query()->where('household_id', $household->id)->first();

            $hasOpenPoll = MealPoll::query()
                ->where('household_id', $household->id)
                ->where('status', 'open')
                ->where('ends_at', '>', now())
                ->exists();

            if ($hasOpenPoll) {
                $validator->errors()->add('poll', 'Un sondage est déjà ouvert pour ce foyer.');
                return;
            }

            $recipeIds = collect((array) $this->input('recipe_ids'))
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values();

            $ownedRecipeCount = Recipe::query()
                ->mineForHousehold((int) $household->id)
                ->whereIn('id', $recipeIds)
                ->count();

            /*if ($ownedRecipeCount !== $recipeIds->count()) {
                $validator->errors()->add('recipe_ids', 'Certaines recettes ne font pas partie de votre foyer.');
                return;
            }*/

            $maxVotesPerUser = (int) ($this->input('max_votes_per_user') ?? ($mealSettings?->max_votes_per_user ?? 3));
            $maxVotesPerUser = max(1, min($maxVotesPerUser, 20));

            if ($maxVotesPerUser > $recipeIds->count()) {
                $validator->errors()->add(
                    'max_votes_per_user',
                    'Le max de votes ne peut pas dépasser le nombre de plats sélectionnés.'
                );
            }
        });
    }
}
