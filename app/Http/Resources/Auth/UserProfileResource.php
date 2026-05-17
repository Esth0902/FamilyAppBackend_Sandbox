<?php

namespace App\Http\Resources\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserProfileResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EloquentCollection<int, \App\Models\Household> $households */
        $households = $this->relationLoaded('households')
            ? $this->households
            : new EloquentCollection();

        $serializedHouseholds = $households
            ->map(static function ($household): array {
                return [
                    'id' => (int) $household->id,
                    'name' => (string) $household->name,
                    'role' => (string) ($household->pivot?->role ?? User::ROLE_CHILD),
                    'nickname' => (string) ($household->pivot?->nickname ?? ''),
                    'pivot' => [
                        'role' => (string) ($household->pivot?->role ?? User::ROLE_CHILD),
                        'nickname' => (string) ($household->pivot?->nickname ?? ''),
                    ],
                ];
            })
            ->values();

        $activeHouseholdId = $serializedHouseholds->count() > 0
            ? (int) ($serializedHouseholds->first()['id'] ?? 0)
            : null;

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'email' => (string) $this->email,
            'must_change_password' => (bool) $this->must_change_password,
            'email_verified_at' => optional($this->email_verified_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'household_id' => $activeHouseholdId,
            'households' => $serializedHouseholds,
            'legal_acceptance' => [
                'cgu_version' => $this->accepted_cgu_version ? (string) $this->accepted_cgu_version : null,
                'cgu_accepted_at' => optional($this->accepted_cgu_at)->toIso8601String(),
                'privacy_policy_version' => $this->accepted_privacy_policy_version
                    ? (string) $this->accepted_privacy_policy_version
                    : null,
                'privacy_policy_accepted_at' => optional($this->accepted_privacy_policy_at)->toIso8601String(),
            ],
        ];
    }
}
