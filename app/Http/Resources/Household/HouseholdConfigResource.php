<?php

namespace App\Http\Resources\Household;

use App\Models\DietaryTag;
use App\Models\Household;
use App\Models\TaskTemplate;
use App\Services\HouseholdManagerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HouseholdConfigResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $household = $this->resource['household'] ?? null;
        if (!$household instanceof Household) {
            return [];
        }

        $settings = $this->resource['settings'] ?? null;
        $mealSettings = $this->resource['meal_settings'] ?? null;
        $tasksConfig = is_array($this->resource['tasks_config'] ?? null) ? $this->resource['tasks_config'] : [];
        $calendarConfig = is_array($this->resource['calendar_config'] ?? null) ? $this->resource['calendar_config'] : [];
        $budgetConfig = is_array($this->resource['budget_config'] ?? null) ? $this->resource['budget_config'] : [];
        $custodyChangeDay = (int) ($this->resource['custody_change_day'] ?? 5);
        $custodyHomeWeekStart = $this->resource['custody_home_week_start'] ?? null;
        $taskTemplates = collect($this->resource['task_templates'] ?? []);
        $householdManagerService = app(HouseholdManagerService::class);

        return [
            'household' => [
                'id' => (int) $household->id,
                'name' => (string) $household->name,
            ],
            'config' => [
                'household_name' => (string) $household->name,
                'is_setup_completed' => (bool) $household->is_setup_completed,
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
                            'templates' => $taskTemplates
                                ->map(function (TaskTemplate $template) use ($householdManagerService): array {
                                    return [
                                        'id' => (int) $template->id,
                                        'name' => (string) $template->name,
                                        'description' => $template->description,
                                        'recurrence' => (string) $template->recurrence,
                                        'recurrence_days' => $householdManagerService->normalizeTaskRecurrenceDaysForConfig($template->recurrence_days),
                                        'is_rotation' => (bool) $template->is_rotation,
                                        'rotation_cycle_weeks' => $householdManagerService->normalizeRotationCycleWeeksForConfig($template->rotation_cycle_weeks ?? 1),
                                        'is_inter_household_alternating' => (bool) ($template->is_inter_household_alternating ?? false),
                                        'inter_household_week_start' => optional($template->inter_household_week_start)->toDateString(),
                                        'fixed_user_id' => $template->fixed_user_id ? (int) $template->fixed_user_id : null,
                                    ];
                                })
                                ->values(),
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

