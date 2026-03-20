<?php

namespace App\Http\Requests\Tasks;

use App\Http\Controllers\Api\Concerns\InteractsWithTaskContext;
use App\Http\Requests\HouseholdAwareRequest;

class StoreTaskTemplateRequest extends HouseholdAwareRequest
{
    use InteractsWithTaskContext;

    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $this->ensureTasksModuleEnabled($this->household());
        $this->ensureParentRole($this->householdRole());

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'recurrence' => ['required', 'in:daily,weekly,monthly,once'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'recurrence_days' => ['nullable', 'array'],
            'recurrence_days.*' => ['integer', 'between:1,7'],
            'assignee_user_ids' => ['nullable', 'array'],
            'assignee_user_ids.*' => ['integer'],
            'rotation_user_ids' => ['nullable', 'array'],
            'rotation_user_ids.*' => ['integer'],
            'is_rotation' => ['nullable', 'boolean'],
            'rotation_cycle_weeks' => ['nullable', 'integer', 'in:1,2'],
            'is_inter_household_alternating' => ['nullable', 'boolean'],
            'inter_household_week_start' => ['nullable', 'date_format:Y-m-d'],
            'fixed_user_id' => ['nullable', 'integer'],
        ];
    }
}
