<?php

namespace App\Http\Resources\ShoppingList;

use App\Models\ShoppingList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShoppingListMutationResource extends JsonResource
{
    public static $wrap = null;

    public static function created(ShoppingList $list, string $message): self
    {
        return self::make([
            'message' => $message,
            'list' => $list,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $list = $this->resource['list'] ?? null;

        return [
            'message' => (string) ($this->resource['message'] ?? ''),
            'list' => $list instanceof ShoppingList
                ? ShoppingListResource::summary($list)->resolve($request)
                : null,
        ];
    }
}
