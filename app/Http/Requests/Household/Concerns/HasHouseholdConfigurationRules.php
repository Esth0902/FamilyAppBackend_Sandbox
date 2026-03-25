<?php

namespace App\Http\Requests\Household\Concerns;

trait HasHouseholdConfigurationRules
{
    /**
     * @return array<string, array<int, string>|string>
     */
    protected function householdConfigurationRules(bool $modulesRequired): array
    {
        return [
            'household_name' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:255',
            'is_setup_completed' => ['sometimes', 'boolean'],

            'modules' => ($modulesRequired ? 'required' : 'nullable') . '|array',
            'modules.meals.enabled' => 'nullable|boolean',
            'modules.meals.options' => 'nullable|array',
            'modules.meals.options.recipes' => 'nullable|boolean',
            'modules.meals.options.polls' => 'nullable|boolean',
            'modules.meals.options.shopping_list' => 'nullable|boolean',
            'modules.meals.settings' => 'nullable|array',
            'modules.meals.settings.poll_day' => 'nullable',
            'modules.meals.settings.poll_time' => 'nullable|string',
            'modules.meals.settings.poll_duration' => 'nullable|integer|min:1|max:168',
            'modules.meals.settings.max_votes_per_user' => 'nullable|integer|min:1|max:20',
            'modules.meals.settings.default_servings' => 'nullable|integer|min:1|max:30',
            'modules.meals.settings.dietary_tags' => 'nullable|array',
            'modules.meals.settings.dietary_tags.*' => 'nullable|string|max:120',

            'modules.tasks.enabled' => 'nullable|boolean',
            'modules.tasks.settings' => 'nullable|array',
            'modules.tasks.settings.reminders_enabled' => 'nullable|boolean',
            'modules.tasks.settings.alternating_custody_enabled' => 'nullable|boolean',
            'modules.tasks.settings.custody_change_day' => 'nullable|integer|min:1|max:7',
            'modules.tasks.settings.custody_home_week_start' => 'nullable|date_format:Y-m-d',
            'modules.tasks.settings.templates' => 'nullable|array',
            'modules.tasks.settings.templates.*.id' => 'nullable|integer',
            'modules.tasks.settings.templates.*.name' => 'required|string|max:255',
            'modules.tasks.settings.templates.*.description' => 'nullable|string|max:1000',
            'modules.tasks.settings.templates.*.recurrence' => 'required|in:daily,weekly,monthly,once',
            'modules.tasks.settings.templates.*.recurrence_days' => 'nullable|array',
            'modules.tasks.settings.templates.*.recurrence_days.*' => 'nullable|integer|min:1|max:7',
            'modules.tasks.settings.templates.*.is_rotation' => 'nullable|boolean',
            'modules.tasks.settings.templates.*.rotation_cycle_weeks' => 'nullable|integer|in:1,2',
            'modules.tasks.settings.templates.*.is_inter_household_alternating' => 'nullable|boolean',
            'modules.tasks.settings.templates.*.inter_household_week_start' => 'nullable|date_format:Y-m-d',
            'modules.tasks.settings.templates.*.fixed_user_id' => 'nullable|integer',

            'modules.calendar.enabled' => 'nullable|boolean',
            'modules.calendar.settings' => 'nullable|array',

            'modules.budget.enabled' => 'nullable|boolean',
            'modules.budget.settings' => 'nullable|array',
        ];
    }
}
