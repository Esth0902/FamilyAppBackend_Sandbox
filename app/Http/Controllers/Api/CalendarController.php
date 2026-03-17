<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesDateRange;
use App\Http\Controllers\Api\Concerns\ResolvesHouseholdContext;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use App\Services\RealtimePublisher;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CalendarController extends Controller
{
    use ResolvesDateRange;
    use ResolvesHouseholdContext;

    private const DEFAULT_RANGE_DAYS = 42;
    private const MAX_RANGE_DAYS = 45;

    public function __construct(
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function board(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        [$fromDate, $toDate] = $this->resolveDateRange($request, self::DEFAULT_RANGE_DAYS, self::MAX_RANGE_DAYS);
        $currentUserId = (int) $request->user()->id;

        $calendarEnabled = $this->isCalendarModuleEnabled($household);
        $calendarSettings = $this->resolveCalendarSettings($household);

        $events = Event::query()
            ->where('household_id', $household->id)
            ->where(function ($query) use ($fromDate, $toDate) {
                $query
                    ->whereBetween('start_at', [$fromDate->copy()->startOfDay(), $toDate->copy()->endOfDay()])
                    ->orWhereBetween('end_at', [$fromDate->copy()->startOfDay(), $toDate->copy()->endOfDay()])
                    ->orWhere(function ($nested) use ($fromDate, $toDate) {
                        $nested
                            ->where('start_at', '<=', $fromDate->copy()->startOfDay())
                            ->where('end_at', '>=', $toDate->copy()->endOfDay());
                    });
            })
            ->with('creator:id,name')
            ->orderBy('start_at')
            ->orderBy('id')
            ->get();

        $mealPlans = MealPlan::query()
            ->where('household_id', $household->id)
            ->whereBetween('date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->with(['items.recipe:id,title,type'])
            ->orderBy('date')
            ->orderByRaw("CASE meal_type WHEN 'matin' THEN 1 WHEN 'midi' THEN 2 WHEN 'soir' THEN 3 ELSE 4 END")
            ->orderBy('id')
            ->get();

        return response()->json([
            'calendar_enabled' => $calendarEnabled,
            'range' => [
                'from' => $fromDate->toDateString(),
                'to' => $toDate->toDateString(),
            ],
            'settings' => [
                'shared_view_enabled' => (bool) ($calendarSettings['shared_view_enabled'] ?? true),
                'absence_tracking_enabled' => (bool) ($calendarSettings['absence_tracking_enabled'] ?? true),
            ],
            'permissions' => [
                'can_create_events' => $calendarEnabled,
                'can_share_with_other_household' => $calendarEnabled
                    && $role === User::ROLE_PARENT
                    && (bool) ($calendarSettings['shared_view_enabled'] ?? true),
                'can_manage_meal_plan' => $role === User::ROLE_PARENT,
            ],
            'events' => $events->map(fn(Event $event): array => $this->toEventPayload($event, $currentUserId, $role))->values(),
            'meal_plan' => $mealPlans->map(fn(MealPlan $mealPlan): array => $this->toMealPlanPayload($mealPlan))->values(),
        ]);
    }

    public function storeEvent(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureCalendarModuleEnabled($household);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after_or_equal:start_at'],
            'is_shared_with_other_household' => ['nullable', 'boolean'],
        ]);

        $calendarSettings = $this->resolveCalendarSettings($household);
        $shouldShare = (bool) ($validated['is_shared_with_other_household'] ?? false);

        if ($shouldShare && !($calendarSettings['shared_view_enabled'] ?? true)) {
            throw ValidationException::withMessages([
                'is_shared_with_other_household' => ['Le partage inter-foyers est desactive pour ce foyer.'],
            ]);
        }

        if ($shouldShare && $role !== User::ROLE_PARENT) {
            abort(403, 'Seul un parent peut partager un evenement avec un autre foyer.');
        }

        $startAt = Carbon::parse((string) $validated['start_at']);
        $endAt = Carbon::parse((string) $validated['end_at']);

        $event = Event::query()->create([
            'household_id' => $household->id,
            'created_by_user_id' => $request->user()->id,
            'title' => trim((string) $validated['title']),
            'description' => $validated['description'] ?? null,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'is_shared_with_other_household' => $shouldShare,
        ])->load('creator:id,name');

        $this->publishCalendarRealtime(
            householdId: (int) $household->id,
            type: 'event.created',
            payload: [
                'event_id' => (int) $event->id,
                'title' => (string) $event->title,
                'start_at' => optional($event->start_at)->toIso8601String(),
                'end_at' => optional($event->end_at)->toIso8601String(),
                'is_shared_with_other_household' => (bool) $event->is_shared_with_other_household,
            ],
        );

        return response()->json([
            'message' => 'Evenement cree.',
            'event' => $this->toEventPayload($event, (int) $request->user()->id, $role),
        ], 201);
    }

    public function updateEvent(Request $request, Event $event): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureCalendarModuleEnabled($household);
        $this->ensureEventBelongsToHousehold($event, $household);
        $this->ensureEventCanBeManaged($event, (int) $request->user()->id, $role);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after_or_equal:start_at'],
            'is_shared_with_other_household' => ['nullable', 'boolean'],
        ]);

        $calendarSettings = $this->resolveCalendarSettings($household);
        $shouldShare = (bool) ($validated['is_shared_with_other_household'] ?? false);

        if ($shouldShare && !($calendarSettings['shared_view_enabled'] ?? true)) {
            throw ValidationException::withMessages([
                'is_shared_with_other_household' => ['Le partage inter-foyers est desactive pour ce foyer.'],
            ]);
        }

        if ($shouldShare && $role !== User::ROLE_PARENT) {
            abort(403, 'Seul un parent peut partager un evenement avec un autre foyer.');
        }

        $event->update([
            'title' => trim((string) $validated['title']),
            'description' => $validated['description'] ?? null,
            'start_at' => Carbon::parse((string) $validated['start_at']),
            'end_at' => Carbon::parse((string) $validated['end_at']),
            'is_shared_with_other_household' => $shouldShare,
        ]);

        $event->load('creator:id,name');

        $this->publishCalendarRealtime(
            householdId: (int) $household->id,
            type: 'event.updated',
            payload: [
                'event_id' => (int) $event->id,
                'title' => (string) $event->title,
                'start_at' => optional($event->start_at)->toIso8601String(),
                'end_at' => optional($event->end_at)->toIso8601String(),
                'is_shared_with_other_household' => (bool) $event->is_shared_with_other_household,
            ],
        );

        return response()->json([
            'message' => 'Evenement mis a jour.',
            'event' => $this->toEventPayload($event, (int) $request->user()->id, $role),
        ]);
    }

    public function destroyEvent(Request $request, Event $event): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureCalendarModuleEnabled($household);
        $this->ensureEventBelongsToHousehold($event, $household);
        $this->ensureEventCanBeManaged($event, (int) $request->user()->id, $role);

        $eventId = (int) $event->id;
        $eventTitle = (string) $event->title;
        $event->delete();

        $this->publishCalendarRealtime(
            householdId: (int) $household->id,
            type: 'event.deleted',
            payload: [
                'event_id' => $eventId,
                'title' => $eventTitle,
            ],
        );

        return response()->json([
            'message' => 'Evenement supprime.',
        ]);
    }

    public function storeMealPlan(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureParentRole($role);

        $validated = $this->validateMealPlanPayload($request);
        [$mealPlanUpdatePayload, $recipeId, $servings] = $this->resolveMealPlanPayload(
            $validated,
            $household
        );

        $mealPlan = MealPlan::query()->updateOrCreate(
            [
                'household_id' => $household->id,
                'date' => (string) $validated['date'],
                'meal_type' => (string) $validated['meal_type'],
            ],
            $mealPlanUpdatePayload
        );

        $mealPlan->items()->delete();
        if ($recipeId !== null) {
            $mealPlan->items()->create([
                'recipe_id' => $recipeId,
                'servings' => $servings,
                'position' => 1,
            ]);
        }

        $mealPlan->load(['items.recipe:id,title,type']);

        $this->publishCalendarRealtime(
            householdId: (int) $household->id,
            type: $mealPlan->wasRecentlyCreated ? 'meal_plan.created' : 'meal_plan.updated',
            payload: [
                'meal_plan_id' => (int) $mealPlan->id,
                'date' => optional($mealPlan->date)->toDateString(),
                'meal_type' => (string) $mealPlan->meal_type,
            ],
        );

        return response()->json([
            'message' => $mealPlan->wasRecentlyCreated ? 'Meal plan cree.' : 'Meal plan mis a jour.',
            'meal_plan' => $this->toMealPlanPayload($mealPlan),
        ], $mealPlan->wasRecentlyCreated ? 201 : 200);
    }

    public function updateMealPlan(Request $request, MealPlan $mealPlan): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureParentRole($role);
        $this->ensureMealPlanBelongsToHousehold($mealPlan, $household);

        $validated = $this->validateMealPlanPayload($request);
        [$mealPlanUpdatePayload, $recipeId, $servings] = $this->resolveMealPlanPayload(
            $validated,
            $household
        );

        $mealPlan->update($mealPlanUpdatePayload);
        $mealPlan->items()->delete();
        if ($recipeId !== null) {
            $mealPlan->items()->create([
                'recipe_id' => $recipeId,
                'servings' => $servings,
                'position' => 1,
            ]);
        }

        $mealPlan->load(['items.recipe:id,title,type']);

        $this->publishCalendarRealtime(
            householdId: (int) $household->id,
            type: 'meal_plan.updated',
            payload: [
                'meal_plan_id' => (int) $mealPlan->id,
                'date' => optional($mealPlan->date)->toDateString(),
                'meal_type' => (string) $mealPlan->meal_type,
            ],
        );

        return response()->json([
            'message' => 'Meal plan mis a jour.',
            'meal_plan' => $this->toMealPlanPayload($mealPlan),
        ]);
    }

    public function destroyMealPlan(Request $request, MealPlan $mealPlan): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureParentRole($role);
        $this->ensureMealPlanBelongsToHousehold($mealPlan, $household);

        $mealPlanId = (int) $mealPlan->id;
        $mealPlanDate = optional($mealPlan->date)->toDateString();
        $mealType = (string) $mealPlan->meal_type;
        $mealPlan->items()->delete();
        $mealPlan->delete();

        $this->publishCalendarRealtime(
            householdId: (int) $household->id,
            type: 'meal_plan.deleted',
            payload: [
                'meal_plan_id' => $mealPlanId,
                'date' => $mealPlanDate,
                'meal_type' => $mealType,
            ],
        );

        return response()->json([
            'message' => 'Meal plan supprime.',
        ]);
    }

    private function validateMealPlanPayload(Request $request): array
    {
        return $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'meal_type' => ['required', 'string', 'in:matin,midi,soir'],
            'recipe_id' => ['nullable', 'integer', 'exists:recipes,id', 'required_without:custom_title'],
            'custom_title' => ['nullable', 'string', 'max:120', 'required_without:recipe_id'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:30'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: int|null, 2: int}
     */
    private function resolveMealPlanPayload(array $validated, Household $household): array
    {
        $recipeId = !empty($validated['recipe_id']) ? (int) $validated['recipe_id'] : null;
        $customTitle = trim((string) ($validated['custom_title'] ?? ''));

        if ($recipeId === null && $customTitle === '') {
            throw ValidationException::withMessages([
                'recipe_id' => ['Choisissez une recette ou saisissez un repas libre.'],
            ]);
        }

        if ($recipeId !== null) {
            $recipe = Recipe::query()
                ->mineForHousehold((int)$household->id)
                ->where('id', $recipeId)
                ->first();

            if (!$recipe) {
                throw ValidationException::withMessages([
                    'recipe_id' => ['La recette selectionnee n appartient pas au foyer.'],
                ]);
            }
        }

        if (!Schema::hasColumn('meal_plans', 'custom_title') && $recipeId === null) {
            throw ValidationException::withMessages([
                'custom_title' => ['La saisie libre n est pas disponible sur ce schema.'],
            ]);
        }

        $servings = (int) ($validated['servings'] ?? 4);
        $mealPlanUpdatePayload = [
            'household_id' => $household->id,
            'date' => (string) $validated['date'],
            'meal_type' => (string) $validated['meal_type'],
            'note' => $validated['note'] ?? null,
        ];

        if (Schema::hasColumn('meal_plans', 'custom_title')) {
            $mealPlanUpdatePayload['custom_title'] = $customTitle !== '' ? $customTitle : null;
        }

        if (Schema::hasColumn('meal_plans', 'recipe_id')) {
            $mealPlanUpdatePayload['recipe_id'] = $recipeId;
        }

        if (Schema::hasColumn('meal_plans', 'servings')) {
            $mealPlanUpdatePayload['servings'] = $servings;
        }

        return [$mealPlanUpdatePayload, $recipeId, $servings];
    }

    private function isCalendarModuleEnabled(Household $household): bool
    {
        $settings = HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->first();

        return (bool) ($settings?->has_calendar ?? false);
    }

    private function ensureCalendarModuleEnabled(Household $household): void
    {
        if (!$this->isCalendarModuleEnabled($household)) {
            abort(403, 'Le module calendrier est desactive pour ce foyer.');
        }
    }

    private function resolveCalendarSettings(Household $household): array
    {
        $settings = HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->first();

        return is_array($settings?->calendar_config) ? $settings->calendar_config : [];
    }

    private function ensureEventBelongsToHousehold(Event $event, Household $household): void
    {
        if ((int) $event->household_id !== (int) $household->id) {
            abort(404, 'Evenement introuvable.');
        }
    }

    private function ensureMealPlanBelongsToHousehold(MealPlan $mealPlan, Household $household): void
    {
        if ((int) $mealPlan->household_id !== (int) $household->id) {
            abort(404, 'Meal plan introuvable.');
        }
    }

    private function ensureEventCanBeManaged(Event $event, int $currentUserId, string $role): void
    {
        if ($role === User::ROLE_PARENT) {
            return;
        }

        if ((int) $event->created_by_user_id !== $currentUserId) {
            abort(403, 'Vous pouvez modifier uniquement vos evenements.');
        }
    }

    private function toEventPayload(Event $event, int $currentUserId, string $role): array
    {
        $canManage = $role === User::ROLE_PARENT || (int) $event->created_by_user_id === $currentUserId;

        return [
            'id' => (int) $event->id,
            'title' => (string) $event->title,
            'description' => $event->description,
            'start_at' => optional($event->start_at)->toIso8601String(),
            'end_at' => optional($event->end_at)->toIso8601String(),
            'is_shared_with_other_household' => (bool) $event->is_shared_with_other_household,
            'created_by' => [
                'id' => $event->creator?->id ? (int) $event->creator->id : null,
                'name' => $event->creator?->name,
            ],
            'permissions' => [
                'can_update' => $canManage,
                'can_delete' => $canManage,
            ],
        ];
    }

    private function toMealPlanPayload(MealPlan $mealPlan): array
    {
        return [
            'id' => (int) $mealPlan->id,
            'date' => optional($mealPlan->date)->toDateString(),
            'meal_type' => (string) $mealPlan->meal_type,
            'custom_title' => $mealPlan->custom_title,
            'note' => $mealPlan->note,
            'recipes' => $mealPlan->items
                ->sortBy('position')
                ->map(function ($item): array {
                    return [
                        'id' => (int) ($item->recipe?->id ?? 0),
                        'title' => (string) ($item->recipe?->title ?? 'Recette'),
                        'type' => $item->recipe?->type,
                        'servings' => (int) ($item->servings ?? 0),
                        'position' => (int) ($item->position ?? 0),
                    ];
                })
                ->values(),
        ];
    }

    private function publishCalendarRealtime(int $householdId, string $type, array $payload = []): void
    {
        $this->realtimePublisher->publishHousehold(
            householdId: $householdId,
            module: 'calendar',
            type: $type,
            payload: $payload + ['household_id' => $householdId],
        );
    }
}
