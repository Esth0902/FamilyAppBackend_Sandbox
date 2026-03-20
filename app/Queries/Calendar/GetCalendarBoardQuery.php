<?php

namespace App\Queries\Calendar;

use App\Http\Controllers\Api\Concerns\InteractsWithCalendarContext;
use App\Http\Requests\Calendar\CalendarBoardRequest;
use App\Models\Event;
use App\Models\MealPlan;
use App\Models\User;

class GetCalendarBoardQuery
{
    use InteractsWithCalendarContext;

    /**
     * @return array<string, mixed>
     */
    public function execute(CalendarBoardRequest $request): array
    {
        $household = $request->household();
        $role = $request->householdRole();
        [$fromDate, $toDate] = $request->range();
        $currentUserId = (int) $request->user()->id;
        $currentHouseholdId = (int) $household->id;
        $householdMembers = $household->users()
            ->select(['users.id', 'users.name'])
            ->orderBy('users.name')
            ->get()
            ->map(static fn (User $member): array => [
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
                        $linkedQuery->where('household_id', $linkedHouseholdId)
                            ->where('is_shared_with_other_household', true);
                    });
                }
            })
            ->where(function ($query) use ($fromDate, $toDate) {
                $query->whereBetween('start_at', [$fromDate->copy()->startOfDay(), $toDate->copy()->endOfDay()])
                    ->orWhereBetween('end_at', [$fromDate->copy()->startOfDay(), $toDate->copy()->endOfDay()])
                    ->orWhere(function ($nested) use ($fromDate, $toDate) {
                        $nested->where('start_at', '<=', $fromDate->copy()->startOfDay())
                            ->where('end_at', '>=', $toDate->copy()->endOfDay());
                    });
            })
            ->with([
                'creator:id,name',
                'participations' => function ($query) use ($currentHouseholdId): void {
                    $query->where('household_id', $currentHouseholdId)->with('user:id,name');
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
                    $query->where('household_id', $currentHouseholdId)->with('user:id,name');
                },
            ])
            ->orderBy('date')
            ->orderByRaw("CASE meal_type WHEN 'matin' THEN 1 WHEN 'midi' THEN 2 WHEN 'soir' THEN 3 ELSE 4 END")
            ->orderBy('id')
            ->get();

        return [
            'calendar_enabled' => $calendarEnabled,
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'shared_view_enabled' => $sharedViewEnabled,
            'absence_tracking_enabled' => (bool) ($calendarSettings['absence_tracking_enabled'] ?? true),
            'can_create_events' => $calendarEnabled,
            'can_share_with_other_household' => $calendarEnabled
                && $role === User::ROLE_PARENT
                && $sharedViewEnabled
                && $hasLinkedHousehold,
            'can_manage_meal_plan' => $role === User::ROLE_PARENT,
            'can_confirm_meal_presence' => $calendarEnabled
                && (bool) ($calendarSettings['absence_tracking_enabled'] ?? true),
            'can_confirm_event_participation' => $calendarEnabled,
            'events' => $events,
            'meal_plan' => $mealPlans,
            'current_user_id' => $currentUserId,
            'current_household_id' => $currentHouseholdId,
            'household_members' => $householdMembers,
            'role' => $role,
        ];
    }
}
