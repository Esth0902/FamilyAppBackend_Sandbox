<?php

namespace App\Http\Resources\HouseholdConnection;

use App\Models\HouseholdLinkRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HouseholdLinkRequestResource extends JsonResource
{
    public static $wrap = null;

    public static function submitted(HouseholdLinkRequest $linkRequest, int $currentHouseholdId): self
    {
        return self::make([
            'message' => 'Demande de liaison envoyée.',
            'request' => $linkRequest,
            'current_household_id' => $currentHouseholdId,
        ]);
    }

    public static function forHousehold(HouseholdLinkRequest $linkRequest, int $currentHouseholdId): self
    {
        return self::make([
            'request' => $linkRequest,
            'current_household_id' => $currentHouseholdId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $linkRequest = $this->resource['request'] ?? null;
        if (!$linkRequest instanceof HouseholdLinkRequest) {
            return [];
        }

        $payload = $this->toRequestPayload($linkRequest, (int) ($this->resource['current_household_id'] ?? 0));
        if (array_key_exists('message', $this->resource)) {
            return [
                'message' => (string) $this->resource['message'],
                'request' => $payload,
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function toRequestPayload(HouseholdLinkRequest $linkRequest, int $currentHouseholdId): array
    {
        $isOutgoing = (int) $linkRequest->from_household_id === $currentHouseholdId;
        $otherHousehold = $isOutgoing ? $linkRequest->toHousehold : $linkRequest->fromHousehold;

        return [
            'id' => (int) $linkRequest->id,
            'direction' => $isOutgoing ? 'outgoing' : 'incoming',
            'status' => (string) $linkRequest->status,
            'created_at' => optional($linkRequest->created_at)->toIso8601String(),
            'created_at_human' => optional($linkRequest->created_at)?->diffForHumans(),
            'other_household' => $otherHousehold
                ? [
                    'id' => (int) $otherHousehold->id,
                    'name' => (string) $otherHousehold->name,
                ]
                : null,
        ];
    }
}
