<?php

namespace App\Http\Resources\HouseholdConnection;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HouseholdConnectionMessageResource extends JsonResource
{
    public static $wrap = null;

    public static function makeMessage(string $message): self
    {
        return self::make(['message' => $message]);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => (string) ($this->resource['message'] ?? ''),
        ];
    }
}
