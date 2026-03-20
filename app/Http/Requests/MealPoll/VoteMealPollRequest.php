<?php

namespace App\Http\Requests\MealPoll;

use App\Models\MealPoll;
use App\Models\MealPollOption;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;

class VoteMealPollRequest extends MealPollContextRequest
{
    private ?MealPollOption $resolvedOption = null;

    public function authorize(): bool
    {
        $user = $this->user();
        $poll = $this->pollFromRoute();
        if (!$user instanceof User || !$poll instanceof MealPoll) {
            return false;
        }

        $household = $this->household();
        $this->ensurePollBelongsToHousehold($poll, $household);
        $this->ensurePollIsOpenForVoting($poll);

        return $user->can('vote', $poll);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'option_id' => ['nullable', 'integer', 'required_without:recipe_id'],
            'recipe_id' => ['nullable', 'integer', 'required_without:option_id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->resolvedOption = $this->resolveOption();
            if (!$this->resolvedOption instanceof MealPollOption) {
                $validator->errors()->add('option_id', 'Option de vote invalide.');
            }
        });
    }

    public function selectedOption(): MealPollOption
    {
        if ($this->resolvedOption instanceof MealPollOption) {
            return $this->resolvedOption;
        }

        $resolved = $this->resolveOption();
        if ($resolved instanceof MealPollOption) {
            $this->resolvedOption = $resolved;
            return $resolved;
        }

        throw new \RuntimeException('Aucune option de vote valide n a ete resolue.');
    }

    private function resolveOption(): ?MealPollOption
    {
        $poll = $this->pollFromRoute();
        if (!$poll instanceof MealPoll) {
            return null;
        }

        $optionId = $this->input('option_id');
        if (is_numeric($optionId)) {
            return MealPollOption::query()
                ->where('meal_poll_id', $poll->id)
                ->where('id', (int) $optionId)
                ->first();
        }

        $recipeId = $this->input('recipe_id');
        if (is_numeric($recipeId)) {
            return MealPollOption::query()
                ->where('meal_poll_id', $poll->id)
                ->where('recipe_id', (int) $recipeId)
                ->first();
        }

        return null;
    }
}
