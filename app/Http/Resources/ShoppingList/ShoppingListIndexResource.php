<?php

namespace App\Http\Resources\ShoppingList;

use App\Models\ShoppingList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ShoppingListIndexResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param Collection<int, ShoppingList> $lists
     */
    public static function fromContext(bool $canManage, Collection $lists): self
    {
        return self::make([
            'can_manage' => $canManage,
            'lists' => $lists,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'can_manage' => (bool) ($this->resource['can_manage'] ?? false),
            'lists' => collect($this->resource['lists'] ?? [])
                ->map(
                    static fn(ShoppingList $list): array => ShoppingListResource::summary($list)->resolve($request)
                )
                ->values()
                ->all(),
        ];
    }
}
