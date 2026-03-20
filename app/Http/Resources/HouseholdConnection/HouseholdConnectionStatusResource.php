<?php

namespace App\Http\Resources\HouseholdConnection;

use App\Models\Household;
use App\Models\HouseholdLinkCode;
use App\Models\HouseholdLinkRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HouseholdConnectionStatusResource extends JsonResource
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

        $role = (string) ($this->resource['role'] ?? User::ROLE_CHILD);
        $linkedHousehold = $this->resource['linked_household'] ?? null;
        $pendingRequest = $this->resource['pending_request'] ?? null;
        $activeCode = $this->resource['active_code'] ?? null;

        return [
            'connection' => [
                'is_connected' => $linkedHousehold instanceof Household,
                'linked_household' => $linkedHousehold instanceof Household
                    ? [
                        'id' => (int) $linkedHousehold->id,
                        'name' => (string) $linkedHousehold->name,
                    ]
                    : null,
                'pending_request' => $pendingRequest instanceof HouseholdLinkRequest
                    ? HouseholdLinkRequestResource::forHousehold(
                        $pendingRequest,
                        (int) $household->id
                    )->resolve($request)
                    : null,
                'active_code' => $activeCode instanceof HouseholdLinkCode
                    ? HouseholdLinkCodeResource::fromContext(
                        $activeCode,
                        (string) $household->name
                    )->resolve($request)
                    : null,
            ],
            'permissions' => [
                'can_manage_connection' => $role === User::ROLE_PARENT,
                'can_generate_code' => $role === User::ROLE_PARENT
                    && !($linkedHousehold instanceof Household)
                    && !($pendingRequest instanceof HouseholdLinkRequest),
                'can_connect_with_code' => $role === User::ROLE_PARENT
                    && !($linkedHousehold instanceof Household)
                    && !($pendingRequest instanceof HouseholdLinkRequest),
                'can_unlink' => $role === User::ROLE_PARENT
                    && $linkedHousehold instanceof Household,
            ],
        ];
    }
}
