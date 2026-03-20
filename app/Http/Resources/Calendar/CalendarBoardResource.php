<?php

namespace App\Http\Resources\Calendar;

use App\Models\Event;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class CalendarBoardResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param Collection<int, Event> $events
     * @param Collection<int, MealPlan> $mealPlan
     * @param array<int, array{id:int,name:string}> $householdMembers
     */
    public static function fromBoardData(
        bool $calendarEnabled,
        string $from,
        string $to,
        bool $sharedViewEnabled,
        bool $absenceTrackingEnabled,
        bool $canCreateEvents,
        bool $canShareWithOtherHousehold,
        bool $canManageMealPlan,
        bool $canConfirmMealPresence,
        bool $canConfirmEventParticipation,
        Collection $events,
        Collection $mealPlan,
        int $currentUserId,
        int $currentHouseholdId,
        array $householdMembers,
        string $role
    ): self {
        return self::make([
            'calendar_enabled' => $calendarEnabled,
            'from' => $from,
            'to' => $to,
            'shared_view_enabled' => $sharedViewEnabled,
            'absence_tracking_enabled' => $absenceTrackingEnabled,
            'can_create_events' => $canCreateEvents,
            'can_share_with_other_household' => $canShareWithOtherHousehold,
            'can_manage_meal_plan' => $canManageMealPlan,
            'can_confirm_meal_presence' => $canConfirmMealPresence,
            'can_confirm_event_participation' => $canConfirmEventParticipation,
            'events' => $events,
            'meal_plan' => $mealPlan,
            'current_user_id' => $currentUserId,
            'current_household_id' => $currentHouseholdId,
            'household_members' => $householdMembers,
            'role' => $role,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentUserId = (int) ($this->resource['current_user_id'] ?? 0);
        $currentHouseholdId = (int) ($this->resource['current_household_id'] ?? 0);
        $role = (string) ($this->resource['role'] ?? User::ROLE_CHILD);
        $householdMembers = is_array($this->resource['household_members'] ?? null)
            ? $this->resource['household_members']
            : [];

        return [
            'calendar_enabled' => (bool) ($this->resource['calendar_enabled'] ?? false),
            'range' => [
                'from' => $this->resource['from'] ?? null,
                'to' => $this->resource['to'] ?? null,
            ],
            'settings' => [
                'shared_view_enabled' => (bool) ($this->resource['shared_view_enabled'] ?? true),
                'absence_tracking_enabled' => (bool) ($this->resource['absence_tracking_enabled'] ?? true),
            ],
            'permissions' => [
                'can_create_events' => (bool) ($this->resource['can_create_events'] ?? false),
                'can_share_with_other_household' => (bool) ($this->resource['can_share_with_other_household'] ?? false),
                'can_manage_meal_plan' => (bool) ($this->resource['can_manage_meal_plan'] ?? false),
                'can_confirm_meal_presence' => (bool) ($this->resource['can_confirm_meal_presence'] ?? false),
                'can_confirm_event_participation' => (bool) ($this->resource['can_confirm_event_participation'] ?? false),
            ],
            'events' => collect($this->resource['events'] ?? [])
                ->map(
                    static fn (Event $event): array => EventResource::forBoard(
                        event: $event,
                        currentUserId: $currentUserId,
                        role: $role,
                        currentHouseholdId: $currentHouseholdId,
                        householdMembers: $householdMembers
                    )->resolve($request)
                )
                ->values()
                ->all(),
            'meal_plan' => collect($this->resource['meal_plan'] ?? [])
                ->map(
                    static fn (MealPlan $mealPlan): array => MealPlanResource::forBoard(
                        mealPlan: $mealPlan,
                        currentUserId: $currentUserId,
                        householdMembers: $householdMembers
                    )->resolve($request)
                )
                ->values()
                ->all(),
        ];
    }
}
