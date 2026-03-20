<?php

namespace App\Actions\Household;

use App\Models\Household;
use App\Models\TaskTemplate;
use App\Services\HouseholdManagerService;

class GetHouseholdConfigAction
{
    public function __construct(private readonly HouseholdManagerService $householdManagerService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Household $household): array
    {
        $household->load(['settings', 'mealSettings', 'dietaryTags']);

        $settings = $household->settings;
        $tasksConfig = is_array($settings?->tasks_config) ? $settings->tasks_config : [];
        $calendarConfig = is_array($settings?->calendar_config) ? $settings->calendar_config : [];
        $budgetConfig = is_array($settings?->budget_config) ? $settings->budget_config : [];
        $custodyChangeDay = $this->householdManagerService->normalizeIsoWeekDayForConfig(
            $tasksConfig['custody_change_day'] ?? 5,
            5
        );
        $custodyHomeWeekStart = $this->householdManagerService->resolveCustodyHomeWeekStartForConfig(
            (bool) ($tasksConfig['alternating_custody_enabled'] ?? false),
            $tasksConfig['custody_home_week_start'] ?? null,
            $custodyChangeDay
        );
        $taskTemplates = TaskTemplate::query()
            ->where('household_id', $household->id)
            ->orderBy('id')
            ->get();

        return [
            'household' => $household,
            'settings' => $settings,
            'meal_settings' => $household->mealSettings,
            'tasks_config' => $tasksConfig,
            'calendar_config' => $calendarConfig,
            'budget_config' => $budgetConfig,
            'custody_change_day' => $custodyChangeDay,
            'custody_home_week_start' => $custodyHomeWeekStart,
            'task_templates' => $taskTemplates,
        ];
    }
}

