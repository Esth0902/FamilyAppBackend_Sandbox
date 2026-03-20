<?php

namespace App\Queries\HouseholdConnection;

use App\Models\Household;
use App\Models\HouseholdLinkCode;
use App\Models\HouseholdLinkRequest;
use App\Models\User;

class GetHouseholdConnectionStatusQuery
{
    private const REQUEST_STATUS_PENDING = 'pending';

    public function execute(Household $household, string $role): array
    {
        $linkedHousehold = $this->resolveConnectedHousehold($household);
        $pendingRequest = $this->resolvePendingRequestForHousehold((int) $household->id);
        $activeCode = null;

        if (
            $role === User::ROLE_PARENT
            && !$linkedHousehold
            && !$pendingRequest
        ) {
            $activeCode = $this->findReusableCode((int) $household->id);
        }

        return [
            'household' => $household,
            'role' => $role,
            'linked_household' => $linkedHousehold,
            'pending_request' => $pendingRequest,
            'active_code' => $activeCode,
        ];
    }

    private function resolveConnectedHousehold(Household $household): ?Household
    {
        $linkedHouseholdId = (int) ($household->linked_household_id ?? 0);
        if ($linkedHouseholdId <= 0) {
            return null;
        }

        $linkedHousehold = Household::query()->find($linkedHouseholdId);
        if (!$linkedHousehold instanceof Household) {
            return null;
        }

        return (int) ($linkedHousehold->linked_household_id ?? 0) === (int) $household->id
            ? $linkedHousehold
            : null;
    }

    private function resolvePendingRequestForHousehold(int $householdId): ?HouseholdLinkRequest
    {
        return HouseholdLinkRequest::query()
            ->with(['fromHousehold:id,name', 'toHousehold:id,name'])
            ->where('status', self::REQUEST_STATUS_PENDING)
            ->where(function ($query) use ($householdId): void {
                $query
                    ->where('from_household_id', $householdId)
                    ->orWhere('to_household_id', $householdId);
            })
            ->latest('id')
            ->first();
    }

    private function findReusableCode(int $householdId): ?HouseholdLinkCode
    {
        return HouseholdLinkCode::query()
            ->where('household_id', $householdId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }
}
