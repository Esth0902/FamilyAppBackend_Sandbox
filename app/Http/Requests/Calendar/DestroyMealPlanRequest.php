<?php

namespace App\Http\Requests\Calendar;

use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DestroyMealPlanRequest extends CalendarContextRequest
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
        if (!$mealPlan instanceof MealPlan || !$this->mealPlanBelongsToHousehold($mealPlan, $this->household())) {
            throw new NotFoundHttpException('Meal plan introuvable.');
        }

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
