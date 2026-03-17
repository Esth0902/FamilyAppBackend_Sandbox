<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesDateRange;
use App\Http\Controllers\Api\Concerns\ResolvesHouseholdContext;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\MealPlan;
use App\Models\MealPlanAttendance;
use App\Models\Recipe;
use App\Models\UserNotification;
use App\Models\User;
use App\Services\RealtimePublisher;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
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
        $currentHouseholdId = (int) $household->id;
        $householdMembers = $household->users()
            ->select(['users.id', 'users.name'])
            ->orderBy('users.name')
            ->get()
            ->map(static fn(User $member): array => [
                'id' => (int) $member->id,
                'name' => (string) ($member->name ?? 'Membre'),
            ])
            ->values()
            ->all();

        $calendarEnabled = $this->isCalendarModuleEnabled($household);
        $calendarSettings = $this->resolveCalendarSettings($household);
        $linkedHouseholdId = $this->resolveConnectedHouseholdId($household);
        $hasLinkedHousehold = $linkedHouseholdId !== null;
        $sharedViewEnabled = (bool) ($calendarSettings['shared_view_enabled'] ?? true);

        $events = Event::query()
            ->where(function ($query) use ($currentHouseholdId, $linkedHouseholdId, $sharedViewEnabled): void {
                $query->where('household_id', $currentHouseholdId);

                if ($linkedHouseholdId !== null && $sharedViewEnabled) {
                    $query->orWhere(function ($linkedQuery) use ($linkedHouseholdId): void {
                        $linkedQuery
                            ->where('household_id', $linkedHouseholdId)
                            ->where('is_shared_with_other_household', true);
                    });
                }
            })
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
            ->with([
                'creator:id,name',
                'participations' => function ($query) use ($currentHouseholdId): void {
                    $query
                        ->where('household_id', $currentHouseholdId)
                        ->with('user:id,name');
                },
            ])
            ->orderBy('start_at')
            ->orderBy('id')
            ->get();

        $mealPlans = MealPlan::query()
            ->where('household_id', $household->id)
            ->whereBetween('date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->with([
                'items.recipe:id,title,type',
                'attendances' => function ($query) use ($currentHouseholdId): void {
                    $query
                        ->where('household_id', $currentHouseholdId)
                        ->with('user:id,name');
                },
            ])
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
                'shared_view_enabled' => $sharedViewEnabled,
                'absence_tracking_enabled' => (bool) ($calendarSettings['absence_tracking_enabled'] ?? true),
            ],
            'permissions' => [
                'can_create_events' => $calendarEnabled,
                'can_share_with_other_household' => $calendarEnabled
                    && $role === User::ROLE_PARENT
                    && $sharedViewEnabled
                    && $hasLinkedHousehold,
                'can_manage_meal_plan' => $role === User::ROLE_PARENT,
                'can_confirm_meal_presence' => $calendarEnabled
                    && (bool) ($calendarSettings['absence_tracking_enabled'] ?? true),
                'can_confirm_event_participation' => $calendarEnabled,
            ],
            'events' => $events->map(
                fn(Event $event): array => $this->toEventPayload(
                    $event,
                    $currentUserId,
                    $role,
                    $currentHouseholdId,
                    $householdMembers
                )
            )->values(),
            'meal_plan' => $mealPlans->map(
                fn(MealPlan $mealPlan): array => $this->toMealPlanPayload($mealPlan, $currentUserId, $householdMembers)
            )->values(),
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

        if ($shouldShare && !$this->hasConnectedHousehold($household)) {
            throw ValidationException::withMessages([
                'is_shared_with_other_household' => ['Aucun foyer connecte n est disponible pour le partage.'],
            ]);
        }

        if ($shouldShare && $role !== User::ROLE_PARENT) {
            abort(403, 'Seul un parent peut partager un evenement avec un autre foyer.');
        }

        $startAt = Carbon::parse((string) $validated['start_at']);
        $endAt = Carbon::parse((string) $validated['end_at']);
        $linkedHouseholdId = $shouldShare ? $this->resolveConnectedHouseholdId($household) : null;

        $event = Event::query()->create([
            'household_id' => $household->id,
            'created_by_user_id' => $request->user()->id,
            'title' => trim((string) $validated['title']),
            'description' => $validated['description'] ?? null,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'is_shared_with_other_household' => $shouldShare,
        ])->load('creator:id,name');

        $this->notifyCalendarChangeToHouseholdMembers(
            household: $household,
            actor: $request->user(),
            type: 'calendar_event_added',
            title: 'Événement ajouté',
            body: sprintf('L\'événement "%s" a été ajouté au calendrier.', (string) $event->title),
            data: [
                'event_id' => (int) $event->id,
                'event_title' => (string) $event->title,
                'change' => 'added',
            ],
        );

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
        if ($shouldShare && $linkedHouseholdId !== null) {
            $this->publishCalendarRealtime(
                householdId: $linkedHouseholdId,
                type: 'event.created',
                payload: [
                    'event_id' => (int) $event->id,
                    'title' => (string) $event->title,
                    'start_at' => optional($event->start_at)->toIso8601String(),
                    'end_at' => optional($event->end_at)->toIso8601String(),
                    'is_shared_with_other_household' => (bool) $event->is_shared_with_other_household,
                ],
            );
        }

        return response()->json([
            'message' => 'Evenement cree.',
            'event' => $this->toEventPayload($event, (int) $request->user()->id, $role, (int) $household->id, []),
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

        if ($shouldShare && !$this->hasConnectedHousehold($household)) {
            throw ValidationException::withMessages([
                'is_shared_with_other_household' => ['Aucun foyer connecte n est disponible pour le partage.'],
            ]);
        }

        if ($shouldShare && $role !== User::ROLE_PARENT) {
            abort(403, 'Seul un parent peut partager un evenement avec un autre foyer.');
        }

        $wasSharedWithOtherHousehold = (bool) $event->is_shared_with_other_household;
        $linkedHouseholdId = $this->resolveConnectedHouseholdId($household);

        $event->update([
            'title' => trim((string) $validated['title']),
            'description' => $validated['description'] ?? null,
            'start_at' => Carbon::parse((string) $validated['start_at']),
            'end_at' => Carbon::parse((string) $validated['end_at']),
            'is_shared_with_other_household' => $shouldShare,
        ]);

        $event->load('creator:id,name');

        $this->notifyCalendarChangeToHouseholdMembers(
            household: $household,
            actor: $request->user(),
            type: 'calendar_event_updated',
            title: 'Événement modifié',
            body: sprintf('L\'événement "%s" a été modifié.', (string) $event->title),
            data: [
                'event_id' => (int) $event->id,
                'event_title' => (string) $event->title,
                'change' => 'updated',
            ],
        );

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
        if ($linkedHouseholdId !== null) {
            $linkedRealtimePayload = [
                'event_id' => (int) $event->id,
                'title' => (string) $event->title,
                'start_at' => optional($event->start_at)->toIso8601String(),
                'end_at' => optional($event->end_at)->toIso8601String(),
                'is_shared_with_other_household' => (bool) $event->is_shared_with_other_household,
            ];

            if (!$wasSharedWithOtherHousehold && $shouldShare) {
                $this->publishCalendarRealtime(
                    householdId: $linkedHouseholdId,
                    type: 'event.created',
                    payload: $linkedRealtimePayload,
                );
            } elseif ($wasSharedWithOtherHousehold && !$shouldShare) {
                $this->publishCalendarRealtime(
                    householdId: $linkedHouseholdId,
                    type: 'event.deleted',
                    payload: [
                        'event_id' => (int) $event->id,
                        'title' => (string) $event->title,
                    ],
                );
            } elseif ($wasSharedWithOtherHousehold && $shouldShare) {
                $this->publishCalendarRealtime(
                    householdId: $linkedHouseholdId,
                    type: 'event.updated',
                    payload: $linkedRealtimePayload,
                );
            }
        }

        return response()->json([
            'message' => 'Evenement mis a jour.',
            'event' => $this->toEventPayload($event, (int) $request->user()->id, $role, (int) $household->id, []),
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
        $wasSharedWithOtherHousehold = (bool) $event->is_shared_with_other_household;
        $linkedHouseholdId = $wasSharedWithOtherHousehold ? $this->resolveConnectedHouseholdId($household) : null;
        $event->delete();

        $this->notifyCalendarChangeToHouseholdMembers(
            household: $household,
            actor: $request->user(),
            type: 'calendar_event_deleted',
            title: 'Événement supprimé',
            body: sprintf('L\'événement "%s" a été supprimé du calendrier.', $eventTitle),
            data: [
                'event_id' => $eventId,
                'event_title' => $eventTitle,
                'change' => 'deleted',
            ],
        );

        $this->publishCalendarRealtime(
            householdId: (int) $household->id,
            type: 'event.deleted',
            payload: [
                'event_id' => $eventId,
                'title' => $eventTitle,
            ],
        );
        if ($wasSharedWithOtherHousehold && $linkedHouseholdId !== null) {
            $this->publishCalendarRealtime(
                householdId: $linkedHouseholdId,
                type: 'event.deleted',
                payload: [
                    'event_id' => $eventId,
                    'title' => $eventTitle,
                ],
            );
        }

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

        $this->notifyCalendarChangeToHouseholdMembers(
            household: $household,
            actor: $request->user(),
            type: $mealPlan->wasRecentlyCreated ? 'calendar_meal_plan_added' : 'calendar_meal_plan_updated',
            title: $mealPlan->wasRecentlyCreated ? 'Repas planifié ajouté' : 'Repas planifié modifié',
            body: sprintf(
                'Le repas %s du %s a été %s.',
                $this->mealTypeLabel((string) $mealPlan->meal_type),
                (string) optional($mealPlan->date)->toDateString(),
                $mealPlan->wasRecentlyCreated ? 'ajouté' : 'modifié'
            ),
            data: [
                'meal_plan_id' => (int) $mealPlan->id,
                'date' => optional($mealPlan->date)->toDateString(),
                'meal_type' => (string) $mealPlan->meal_type,
                'change' => $mealPlan->wasRecentlyCreated ? 'added' : 'updated',
            ],
        );

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
            'meal_plan' => $this->toMealPlanPayload($mealPlan, (int) $request->user()->id, []),
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

        $this->notifyCalendarChangeToHouseholdMembers(
            household: $household,
            actor: $request->user(),
            type: 'calendar_meal_plan_updated',
            title: 'Repas planifié modifié',
            body: sprintf(
                'Le repas %s du %s a été modifié.',
                $this->mealTypeLabel((string) $mealPlan->meal_type),
                (string) optional($mealPlan->date)->toDateString()
            ),
            data: [
                'meal_plan_id' => (int) $mealPlan->id,
                'date' => optional($mealPlan->date)->toDateString(),
                'meal_type' => (string) $mealPlan->meal_type,
                'change' => 'updated',
            ],
        );

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
            'meal_plan' => $this->toMealPlanPayload($mealPlan, (int) $request->user()->id, []),
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

        $this->notifyCalendarChangeToHouseholdMembers(
            household: $household,
            actor: $request->user(),
            type: 'calendar_meal_plan_deleted',
            title: 'Repas planifié supprimé',
            body: sprintf('Le repas %s du %s a été supprimé.', $this->mealTypeLabel($mealType), (string) $mealPlanDate),
            data: [
                'meal_plan_id' => $mealPlanId,
                'date' => $mealPlanDate,
                'meal_type' => $mealType,
                'change' => 'deleted',
            ],
        );

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

    public function confirmMealPlanAttendance(Request $request, MealPlan $mealPlan): JsonResponse
    {
        [$household] = $this->resolveHouseholdWithRole($request);
        $this->ensureCalendarModuleEnabled($household);
        $this->ensureAbsenceTrackingEnabled($household);
        $this->ensureMealPlanBelongsToHousehold($mealPlan, $household);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:present,not_home,later'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $status = (string) $validated['status'];
        $reason = trim((string) ($validated['reason'] ?? ''));
        if ($status === 'present') {
            $reason = '';
        }

        $attendance = MealPlanAttendance::query()->updateOrCreate(
            [
                'household_id' => $household->id,
                'meal_plan_id' => $mealPlan->id,
                'user_id' => $request->user()->id,
            ],
            [
                'status' => $status,
                'reason' => $reason !== '' ? $reason : null,
                'responded_at' => now(),
            ]
        );
        $this->notifyParentsAboutMealPresence(
            household: $household,
            actor: $request->user(),
            mealPlan: $mealPlan,
            attendance: $attendance,
        );

        $this->publishCalendarRealtime(
            householdId: (int) $household->id,
            type: 'meal_plan.attendance.updated',
            payload: [
                'meal_plan_id' => (int) $mealPlan->id,
                'user_id' => (int) $request->user()->id,
                'status' => (string) $attendance->status,
            ],
        );

        return response()->json([
            'message' => 'Presence au repas enregistree.',
            'attendance' => [
                'status' => (string) $attendance->status,
                'reason' => $attendance->reason,
                'responded_at' => optional($attendance->responded_at)->toIso8601String(),
            ],
        ]);
    }

    public function confirmEventParticipation(Request $request, Event $event): JsonResponse
    {
        [$household] = $this->resolveHouseholdWithRole($request);
        $this->ensureCalendarModuleEnabled($household);
        $this->ensureEventBelongsToHousehold($event, $household);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:participate,not_participate'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $status = (string) $validated['status'];
        $reason = trim((string) ($validated['reason'] ?? ''));
        if ($status === 'participate') {
            $reason = '';
        }

        $participation = EventParticipation::query()->updateOrCreate(
            [
                'household_id' => $household->id,
                'event_id' => $event->id,
                'user_id' => $request->user()->id,
            ],
            [
                'status' => $status,
                'reason' => $reason !== '' ? $reason : null,
                'responded_at' => now(),
            ]
        );
        $this->notifyParentsAboutEventParticipation(
            household: $household,
            actor: $request->user(),
            event: $event,
            participation: $participation,
        );

        $this->publishCalendarRealtime(
            householdId: (int) $household->id,
            type: 'event.participation.updated',
            payload: [
                'event_id' => (int) $event->id,
                'user_id' => (int) $request->user()->id,
                'status' => (string) $participation->status,
            ],
        );

        return response()->json([
            'message' => 'Participation a l evenement enregistree.',
            'participation' => [
                'status' => (string) $participation->status,
                'reason' => $participation->reason,
                'responded_at' => optional($participation->responded_at)->toIso8601String(),
            ],
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

    private function ensureAbsenceTrackingEnabled(Household $household): void
    {
        $calendarSettings = $this->resolveCalendarSettings($household);
        if (!(bool) ($calendarSettings['absence_tracking_enabled'] ?? true)) {
            abort(403, 'Le suivi des absences est desactive pour ce foyer.');
        }
    }

    private function hasConnectedHousehold(Household $household): bool
    {
        return $this->resolveConnectedHouseholdId($household) !== null;
    }

    private function resolveConnectedHouseholdId(Household $household): ?int
    {
        $linkedHouseholdId = (int) ($household->linked_household_id ?? 0);
        if ($linkedHouseholdId <= 0) {
            return null;
        }

        $linkedHousehold = Household::query()
            ->select(['id', 'linked_household_id'])
            ->find($linkedHouseholdId);

        if (
            !$linkedHousehold instanceof Household
            || (int) ($linkedHousehold->linked_household_id ?? 0) !== (int) $household->id
        ) {
            return null;
        }

        return (int) $linkedHousehold->id;
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

    private function toEventPayload(
        Event $event,
        int $currentUserId,
        string $role,
        int $currentHouseholdId,
        array $householdMembers
    ): array
    {
        $belongsToCurrentHousehold = (int) $event->household_id === $currentHouseholdId;
        $canManage = $belongsToCurrentHousehold
            && ($role === User::ROLE_PARENT || (int) $event->created_by_user_id === $currentUserId);
        $participations = $event->relationLoaded('participations')
            ? $event->participations
            : collect();
        $myParticipation = $participations->first(
            static fn(EventParticipation $participation): bool => (int) $participation->user_id === $currentUserId
        );
        $participationOverview = $this->buildEventParticipationOverview($participations, $householdMembers);

        return [
            'id' => (int) $event->id,
            'title' => (string) $event->title,
            'description' => $event->description,
            'start_at' => optional($event->start_at)->toIso8601String(),
            'end_at' => optional($event->end_at)->toIso8601String(),
            'is_shared_with_other_household' => (bool) $event->is_shared_with_other_household,
            'source_household_id' => (int) $event->household_id,
            'created_by' => [
                'id' => $event->creator?->id ? (int) $event->creator->id : null,
                'name' => $event->creator?->name,
            ],
            'my_participation' => $myParticipation
                ? [
                    'status' => (string) $myParticipation->status,
                    'reason' => $myParticipation->reason,
                    'responded_at' => optional($myParticipation->responded_at)->toIso8601String(),
                ]
                : null,
            'participation_overview' => $participationOverview,
            'permissions' => [
                'can_update' => $canManage,
                'can_delete' => $canManage,
                'can_confirm_participation' => $belongsToCurrentHousehold,
            ],
        ];
    }

    private function toMealPlanPayload(MealPlan $mealPlan, int $currentUserId, array $householdMembers): array
    {
        $attendances = $mealPlan->relationLoaded('attendances')
            ? $mealPlan->attendances
            : collect();
        $myAttendance = $attendances->first(
            static fn(MealPlanAttendance $attendance): bool => (int) $attendance->user_id === $currentUserId
        );
        $presenceOverview = $this->buildMealPresenceOverview($attendances, $householdMembers);

        return [
            'id' => (int) $mealPlan->id,
            'date' => optional($mealPlan->date)->toDateString(),
            'meal_type' => (string) $mealPlan->meal_type,
            'custom_title' => $mealPlan->custom_title,
            'note' => $mealPlan->note,
            'my_presence' => $myAttendance
                ? [
                    'status' => (string) $myAttendance->status,
                    'reason' => $myAttendance->reason,
                    'responded_at' => optional($myAttendance->responded_at)->toIso8601String(),
                ]
                : null,
            'presence_overview' => $presenceOverview,
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

    /**
     * @param Collection<int, EventParticipation> $participations
     * @param array<int, array{id:int,name:string}> $householdMembers
     */
    private function buildEventParticipationOverview(Collection $participations, array $householdMembers): array
    {
        $membersById = collect($householdMembers)
            ->mapWithKeys(static fn(array $member): array => [(int) ($member['id'] ?? 0) => [
                'id' => (int) ($member['id'] ?? 0),
                'name' => (string) ($member['name'] ?? 'Membre'),
            ]])
            ->filter(static fn(array $member, int $id): bool => $id > 0)
            ->all();

        $participate = [];
        $notParticipate = [];
        $respondedIds = [];

        foreach ($participations as $participation) {
            $userId = (int) $participation->user_id;
            if ($userId <= 0 || !array_key_exists($userId, $membersById)) {
                continue;
            }

            $respondedIds[$userId] = true;
            $payload = [
                'id' => $userId,
                'name' => $membersById[$userId]['name'],
                'reason' => $participation->reason,
                'responded_at' => optional($participation->responded_at)->toIso8601String(),
            ];

            if ((string) $participation->status === 'participate') {
                $participate[] = $payload;
                continue;
            }

            $notParticipate[] = $payload;
        }

        $unanswered = collect($membersById)
            ->reject(static fn(array $member): bool => isset($respondedIds[(int) $member['id']]))
            ->values()
            ->all();

        return [
            'participate' => array_values($participate),
            'not_participate' => array_values($notParticipate),
            'unanswered' => $unanswered,
        ];
    }

    /**
     * @param Collection<int, MealPlanAttendance> $attendances
     * @param array<int, array{id:int,name:string}> $householdMembers
     */
    private function buildMealPresenceOverview(Collection $attendances, array $householdMembers): array
    {
        $membersById = collect($householdMembers)
            ->mapWithKeys(static fn(array $member): array => [(int) ($member['id'] ?? 0) => [
                'id' => (int) ($member['id'] ?? 0),
                'name' => (string) ($member['name'] ?? 'Membre'),
            ]])
            ->filter(static fn(array $member, int $id): bool => $id > 0)
            ->all();

        $present = [];
        $notHome = [];
        $later = [];
        $respondedIds = [];

        foreach ($attendances as $attendance) {
            $userId = (int) $attendance->user_id;
            if ($userId <= 0 || !array_key_exists($userId, $membersById)) {
                continue;
            }

            $respondedIds[$userId] = true;
            $payload = [
                'id' => $userId,
                'name' => $membersById[$userId]['name'],
                'reason' => $attendance->reason,
                'responded_at' => optional($attendance->responded_at)->toIso8601String(),
            ];

            $status = (string) $attendance->status;
            if ($status === 'present') {
                $present[] = $payload;
                continue;
            }
            if ($status === 'later') {
                $later[] = $payload;
                continue;
            }

            $notHome[] = $payload;
        }

        $unanswered = collect($membersById)
            ->reject(static fn(array $member): bool => isset($respondedIds[(int) $member['id']]))
            ->values()
            ->all();

        return [
            'present' => array_values($present),
            'not_home' => array_values($notHome),
            'later' => array_values($later),
            'unanswered' => $unanswered,
        ];
    }

    private function notifyParentsAboutMealPresence(
        Household $household,
        User $actor,
        MealPlan $mealPlan,
        MealPlanAttendance $attendance
    ): void {
        $status = (string) $attendance->status;
        if (!in_array($status, ['not_home', 'later'], true)) {
            return;
        }

        $actorId = (int) $actor->id;
        $parentIds = $this->resolveParentUserIds($household, $actorId);
        if (empty($parentIds)) {
            return;
        }

        $actorName = (string) ($actor->name ?? 'Un membre');
        $mealTypeLabel = $this->mealTypeLabel((string) $mealPlan->meal_type);
        $dateLabel = (string) optional($mealPlan->date)->toDateString();
        $statusLabel = $status === 'not_home' ? 'ne mangera pas à la maison' : 'mangera plus tard';

        $this->notifyUsers(
            userIds: $parentIds,
            householdId: (int) $household->id,
            type: 'calendar_meal_presence_updated',
            title: 'Présence repas mise à jour',
            body: sprintf('%s a indiqué qu’il %s (%s, %s).', $actorName, $statusLabel, $mealTypeLabel, $dateLabel),
            data: [
                'meal_plan_id' => (int) $mealPlan->id,
                'meal_type' => (string) $mealPlan->meal_type,
                'date' => $dateLabel,
                'status' => $status,
                'reason' => $attendance->reason,
                'actor_user_id' => $actorId,
                'actor_name' => $actorName,
            ],
        );
    }

    private function notifyParentsAboutEventParticipation(
        Household $household,
        User $actor,
        Event $event,
        EventParticipation $participation
    ): void {
        $actorId = (int) $actor->id;
        $parentIds = $this->resolveParentUserIds($household, $actorId);
        if (empty($parentIds)) {
            return;
        }

        $status = (string) $participation->status;
        $actorName = (string) ($actor->name ?? 'Un membre');
        $statusLabel = $status === 'participate' ? 'participe' : 'ne participe pas';

        $this->notifyUsers(
            userIds: $parentIds,
            householdId: (int) $household->id,
            type: 'calendar_event_participation_updated',
            title: 'Participation événement mise à jour',
            body: sprintf('%s a indiqué qu’il %s à l’événement "%s".', $actorName, $statusLabel, (string) $event->title),
            data: [
                'event_id' => (int) $event->id,
                'event_title' => (string) $event->title,
                'status' => $status,
                'reason' => $participation->reason,
                'actor_user_id' => $actorId,
                'actor_name' => $actorName,
            ],
        );
    }

    private function notifyCalendarChangeToHouseholdMembers(
        Household $household,
        User $actor,
        string $type,
        string $title,
        string $body,
        array $data = []
    ): void {
        $actorId = (int) $actor->id;
        $memberIds = $household->users()
            ->pluck('users.id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0 && $id !== $actorId)
            ->values()
            ->all();

        if (empty($memberIds)) {
            return;
        }

        $this->notifyUsers(
            userIds: $memberIds,
            householdId: (int) $household->id,
            type: $type,
            title: $title,
            body: $body,
            data: $data + [
                'actor_user_id' => $actorId,
                'actor_name' => (string) ($actor->name ?? 'Un membre'),
            ],
        );
    }

    /**
     * @return array<int, int>
     */
    private function resolveParentUserIds(Household $household, ?int $excludeUserId = null): array
    {
        return $household->users()
            ->wherePivot('role', User::ROLE_PARENT)
            ->pluck('users.id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0 && ($excludeUserId === null || $id !== $excludeUserId))
            ->values()
            ->all();
    }

    private function notifyUsers(
        array $userIds,
        int $householdId,
        string $type,
        string $title,
        string $body,
        array $data = []
    ): void {
        $ids = collect($userIds)
            ->map(static fn(mixed $id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        foreach ($ids as $userId) {
            $this->notifyUser($userId, $householdId, $type, $title, $body, $data);
        }
    }

    private function notifyUser(
        int $userId,
        int $householdId,
        string $type,
        string $title,
        string $body,
        array $data = []
    ): void {
        if ($userId <= 0 || $householdId <= 0) {
            return;
        }

        $notification = UserNotification::query()->create([
            'household_id' => $householdId,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data + ['household_id' => $householdId],
        ]);

        $this->realtimePublisher->publishUser(
            userId: $userId,
            module: 'notifications',
            type: 'notification_created',
            payload: [
                'notification_id' => (int) $notification->id,
                'household_id' => $householdId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
            ],
        );
    }

    private function mealTypeLabel(string $mealType): string
    {
        return match ($mealType) {
            'matin' => 'matin',
            'midi' => 'midi',
            default => 'soir',
        };
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
