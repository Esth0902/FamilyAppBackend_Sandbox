<?php

namespace App\Http\Resources\ShoppingList;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShoppingListMessageResource extends JsonResource
{
    public static $wrap = null;

    public static function makeMessage(string $message, ?int $deletedCount = null): self
    {
        return self::make([
            'message' => $message,
            'deleted_count' => $deletedCount,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'message' => (string) ($this->resource['message'] ?? ''),
        ];

        if (array_key_exists('deleted_count', $this->resource) && $this->resource['deleted_count'] !== null) {
            $payload['deleted_count'] = (int) $this->resource['deleted_count'];
        }

        return $payload;
    }
}
