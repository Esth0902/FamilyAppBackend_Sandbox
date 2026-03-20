<?php

namespace App\Actions\Calendar\Concerns;

use App\Models\Household;

trait ResolvesLinkedHousehold
{
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
}
