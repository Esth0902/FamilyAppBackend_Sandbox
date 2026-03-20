<?php

namespace App\Http\Requests\Calendar;

use App\Models\MealPlan;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ConfirmMealPlanAttendanceRequest extends CalendarContextRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $household = $this->household();
        if (!$this->isCalendarModuleEnabled($household)) {
            throw new AuthorizationException('Le module calendrier est désactivé pour ce foyer.');
        }

        $calendarSettings = $this->resolveCalendarSettings($household);
        if (!(bool) ($calendarSettings['absence_tracking_enabled'] ?? true)) {
            throw new AuthorizationException('Le suivi des absences est désactivé pour ce foyer.');
        }

        $mealPlan = $this->route('mealPlan');
        if (!$mealPlan instanceof MealPlan || !$this->mealPlanBelongsToHousehold($mealPlan, $household)) {
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
            'status' => ['required', 'string', 'in:present,not_home,later'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
