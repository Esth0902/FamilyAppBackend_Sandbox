<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Event;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\MealPlan;
use App\Models\User;

trait InteractsWithCalendarContext
{
    protected function isCalendarModuleEnabled(Household $household): bool
    {
        $settings = HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->first();

        return (bool) ($settings?->has_calendar ?? false);
    }

    protected function hasConnectedHousehold(Household $household): bool
    {
        return $this->resolveConnectedHouseholdId($household) !== null;
    }

    protected function resolveConnectedHouseholdId(Household $household): ?int
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

    protected function resolveCalendarSettings(Household $household): array
    {
        $settings = HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->first();

        return is_array($settings?->calendar_config) ? $settings->calendar_config : [];
    }

    protected function eventBelongsToHousehold(Event $event, Household $household): bool
    {
        return (int) $event->household_id === (int) $household->id;
    }

    protected function mealPlanBelongsToHousehold(MealPlan $mealPlan, Household $household): bool
    {
        return (int) $mealPlan->household_id === (int) $household->id;
    }

    protected function canManageEvent(Event $event, int $currentUserId, string $role): bool
    {
        if ($role === User::ROLE_PARENT) {
            return true;
        }

        return (int) $event->created_by_user_id === $currentUserId;
    }
}
