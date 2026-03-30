<?php

namespace App\Http\Resources\HouseholdConnection;

use App\Models\HouseholdLinkCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HouseholdLinkCodeResource extends JsonResource
{
    public static $wrap = null;

    public static function generated(HouseholdLinkCode $code, string $householdName): self
    {
        return self::make([
            'message' => 'Code de liaison prêt.',
            'household_name' => $householdName,
            'code' => $code,
        ]);
    }

    public static function fromContext(HouseholdLinkCode $code, string $householdName): self
    {
        return self::make([
            'household_name' => $householdName,
            'code' => $code,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $code = $this->resource['code'] ?? null;
        if (!$code instanceof HouseholdLinkCode) {
            return [];
        }

        $payload = $this->toCodePayload($code, (string) ($this->resource['household_name'] ?? ''));
        if (array_key_exists('message', $this->resource)) {
            return [
                'message' => (string) $this->resource['message'],
                'code' => $payload,
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function toCodePayload(HouseholdLinkCode $code, string $householdName): array
    {
        $expiresAt = $code->expires_at;
        $expiresInMinutes = $expiresAt ? now()->diffInMinutes($expiresAt, false) : null;

        return [
            'value' => (string) $code->code,
            'expires_at' => optional($expiresAt)->toIso8601String(),
            'expires_in_minutes' => is_int($expiresInMinutes) ? max(0, $expiresInMinutes) : null,
            'share_text' => $this->buildShareText($householdName, (string) $code->code),
        ];
    }

    private function buildShareText(string $householdName, string $code): string
    {
        return "Invitation de liaison FamilyFlow\n\n"
            . "Foyer : {$householdName}\n"
            . "Code de liaison : {$code}\n\n"
            . "Ouvre FamilyFlow > Modifier le foyer > Foyer connecté, puis entre ce code.";
    }
}
