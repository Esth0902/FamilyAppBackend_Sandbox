<?php

namespace App\Http\Requests\Calendar;

use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StoreMealPlanRequest extends CalendarContextRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        if ($this->householdRole() !== User::ROLE_PARENT) {
            throw new AuthorizationException('Action réservée aux parents.');
        }

        $mealPlan = $this->route('mealPlan');
        if ($mealPlan instanceof MealPlan && !$this->mealPlanBelongsToHousehold($mealPlan, $this->household())) {
            throw new NotFoundHttpException('Meal plan introuvable.');
        }

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'meal_type' => ['required', 'string', 'in:matin,midi,soir'],
            'recipe_id' => ['nullable', 'integer', 'exists:recipes,id', 'required_without:custom_title'],
            'custom_title' => ['nullable', 'string', 'max:120', 'required_without:recipe_id'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:30'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $recipeId = $this->recipeId();
            $customTitle = trim((string) $this->validated('custom_title', ''));

            if ($recipeId === null && $customTitle === '') {
                $validator->errors()->add('recipe_id', 'Choisissez une recette ou saisissez un repas libre.');
                return;
            }

            if (
                $recipeId !== null
                && !Recipe::query()
                    ->mineForHousehold((int) $this->household()->id)
                    ->where('id', $recipeId)
                    ->exists()
            ) {
                $validator->errors()->add('recipe_id', 'La recette sélectionnée n appartient pas au foyer.');
            }

            if (!Schema::hasColumn('meal_plans', 'custom_title') && $recipeId === null) {
                $validator->errors()->add('custom_title', 'La saisie libre n est pas disponible sur ce schéma.');
            }
        });
    }

    public function recipeId(): ?int
    {
        $value = $this->validated('recipe_id');
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    public function servings(): int
    {
        return (int) $this->validated('servings', 4);
    }

    /**
     * @return array<string, mixed>
     */
    public function mealPlanUpdatePayload(): array
    {
        $recipeId = $this->recipeId();
        $customTitle = trim((string) $this->validated('custom_title', ''));

        $payload = [
            'household_id' => $this->household()->id,
            'date' => (string) $this->validated('date'),
            'meal_type' => (string) $this->validated('meal_type'),
            'note' => $this->validated('note'),
        ];

        if (Schema::hasColumn('meal_plans', 'custom_title')) {
            $payload['custom_title'] = $customTitle !== '' ? $customTitle : null;
        }

        if (Schema::hasColumn('meal_plans', 'recipe_id')) {
            $payload['recipe_id'] = $recipeId;
        }

        if (Schema::hasColumn('meal_plans', 'servings')) {
            $payload['servings'] = $this->servings();
        }

        return $payload;
    }
}
