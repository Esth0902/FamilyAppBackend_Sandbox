<?php

namespace App\Actions\Household;

use App\Models\DietaryTag;
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
        $mealSettings = $household->mealSettings;
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
            'household' => [
                'id' => (int) $household->id,
                'name' => (string) $household->name,
            ],
            'config' => [
                'household_name' => (string) $household->name,
                'modules' => [
                    'meals' => [
                        'enabled' => (bool) ($settings?->has_meals ?? true),
                        'options' => [
                            'recipes' => (bool) ($mealSettings?->enable_recipes ?? true),
                            'polls' => (bool) ($mealSettings?->enable_polls ?? true),
                            'shopping_list' => (bool) ($mealSettings?->enable_shopping_list ?? true),
                        ],
                        'settings' => [
                            'poll_day' => (int) ($mealSettings?->poll_day ?? 5),
                            'poll_time' => (string) ($mealSettings?->poll_time ?? '10:00'),
                            'poll_duration' => (int) ($mealSettings?->poll_duration ?? 24),
                            'max_votes_per_user' => (int) ($mealSettings?->max_votes_per_user ?? 3),
                            'default_servings' => (int) ($mealSettings?->default_servings ?? 4),
                            'dietary_tags' => $household->dietaryTags
                                ->pluck('key')
                                ->filter(static fn(mixed $key): bool => is_string($key) && trim($key) !== '')
                                ->values(),
                            'dietary_tag_details' => $household->dietaryTags
                                ->map(static function (DietaryTag $tag): array {
                                    return [
                                        'key' => (string) $tag->key,
                                        'label' => (string) $tag->label,
                                        'type' => (string) $tag->type,
                                    ];
                                })
                                ->values(),
                        ],
                    ],
                    'tasks' => [
                        'enabled' => (bool) ($settings?->has_tasks ?? false),
                        'settings' => [
                            'reminders_enabled' => (bool) ($tasksConfig['reminders_enabled'] ?? true),
                            'alternating_custody_enabled' => (bool) ($tasksConfig['alternating_custody_enabled'] ?? false),
                            'custody_change_day' => $custodyChangeDay,
                            'custody_home_week_start' => $custodyHomeWeekStart,
                            'templates' => $taskTemplates->map(function (TaskTemplate $template): array {
                                return [
                                    'id' => (int) $template->id,
                                    'name' => $template->name,
                                    'description' => $template->description,
                                    'recurrence' => $template->recurrence,
                                    'recurrence_days' => $this->householdManagerService->normalizeTaskRecurrenceDaysForConfig($template->recurrence_days),
                                    'is_rotation' => (bool) $template->is_rotation,
                                    'rotation_cycle_weeks' => $this->householdManagerService->normalizeRotationCycleWeeksForConfig($template->rotation_cycle_weeks ?? 1),
                                    'is_inter_household_alternating' => (bool) ($template->is_inter_household_alternating ?? false),
                                    'inter_household_week_start' => optional($template->inter_household_week_start)->toDateString(),
                                    'fixed_user_id' => $template->fixed_user_id ? (int) $template->fixed_user_id : null,
                                ];
                            })->values(),
                        ],
                    ],
                    'calendar' => [
                        'enabled' => (bool) ($settings?->has_calendar ?? false),
                        'settings' => $calendarConfig,
                    ],
                    'budget' => [
                        'enabled' => (bool) ($settings?->has_budget ?? false),
                        'settings' => $budgetConfig,
                    ],
                ],
            ],
        ];
    }
}

