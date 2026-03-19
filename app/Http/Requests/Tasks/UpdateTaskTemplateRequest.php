<?php

namespace App\Http\Requests\Tasks;

use App\Http\Controllers\Api\Concerns\InteractsWithTaskContext;
use App\Http\Requests\HouseholdAwareRequest;
use App\Models\TaskTemplate;

class UpdateTaskTemplateRequest extends HouseholdAwareRequest
{
    use InteractsWithTaskContext;

    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $this->ensureTasksModuleEnabled($this->household());
        $this->ensureParentRole($this->householdRole());

        $template = $this->route('template');
        if (!$template instanceof TaskTemplate) {
            return false;
        }

        $this->ensureTemplateBelongsToHousehold($template, $this->household());

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'recurrence' => ['sometimes', 'required', 'in:daily,weekly,monthly,once'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'recurrence_days' => ['sometimes', 'nullable', 'array'],
            'recurrence_days.*' => ['integer', 'between:1,7'],
            'assignee_user_ids' => ['sometimes', 'nullable', 'array'],
            'assignee_user_ids.*' => ['integer'],
            'rotation_user_ids' => ['sometimes', 'nullable', 'array'],
            'rotation_user_ids.*' => ['integer'],
            'is_rotation' => ['sometimes', 'boolean'],
            'rotation_cycle_weeks' => ['sometimes', 'nullable', 'integer', 'in:1,2'],
            'is_inter_household_alternating' => ['sometimes', 'boolean'],
            'inter_household_week_start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'fixed_user_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
