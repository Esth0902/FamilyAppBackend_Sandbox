<?php

namespace App\Http\Requests\MealPoll;

use App\Models\MealPoll;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Carbon;

class ValidateMealPollResultsRequest extends MealPollContextRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $poll = $this->pollFromRoute();
        if (!$user instanceof User || !$poll instanceof MealPoll) {
            return false;
        }

        $household = $this->household();
        $this->ensurePollBelongsToHousehold($poll, $household);

        return $user->can('validate', $poll);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'selected_recipe_ids' => ['nullable', 'array', 'min:1'],
            'selected_recipe_ids.*' => ['required', 'integer', 'exists:recipes,id'],
            'meal_plan' => ['nullable', 'array', 'min:1'],
            'meal_plan.*.date' => ['required', 'date'],
            'meal_plan.*.meal_type' => ['required', 'string', 'in:matin,midi,soir'],
            'meal_plan.*.recipe_id' => ['required', 'integer', 'exists:recipes,id'],
            'meal_plan.*.servings' => ['nullable', 'integer', 'min:1', 'max:30'],
            'meal_plan.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $poll = $this->pollFromRoute();
            if (!$poll instanceof MealPoll) {
                $validator->errors()->add('poll', 'Sondage introuvable.');
                return;
            }

            $selectedRecipeIds = collect((array) $this->input('selected_recipe_ids'))
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values();

            if ($selectedRecipeIds->isNotEmpty()) {
                $allowedRecipeIds = Recipe::query()
                    ->mineForHousehold((int) $poll->household_id)
                    ->whereIn('id', $selectedRecipeIds)
                    ->pluck('id');

                /*if ($allowedRecipeIds->count() !== $selectedRecipeIds->count()) {
                    $validator->errors()->add('selected_recipe_ids', 'Certaines recettes selectionnées ne sont pas dans le foyer.');
                    return;
                }*/
            }

            $planningStartDate = optional($poll->planning_start_date)->toDateString();
            $planningEndDate = optional($poll->planning_end_date)->toDateString();

            if (!$planningStartDate || !$planningEndDate) {
                return;
            }

            foreach ((array) $this->input('meal_plan', []) as $entry) {
                $entryDate = Carbon::parse((string) ($entry['date'] ?? ''))->toDateString();
                if ($entryDate < $planningStartDate || $entryDate > $planningEndDate) {
                    $validator->errors()->add(
                        'meal_plan',
                        'La date de planification doit être comprise dans la plage du sondage.'
                    );

                    return;
                }
            }
        });
    }
}
