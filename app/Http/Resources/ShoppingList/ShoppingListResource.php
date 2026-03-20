<?php

namespace App\Http\Resources\ShoppingList;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin ShoppingList */
class ShoppingListResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        mixed $resource,
        private readonly bool $withItems = false,
    ) {
        parent::__construct($resource);
    }

    public static function summary(ShoppingList $list): self
    {
        return new self($list, false);
    }

    public static function detail(ShoppingList $list): self
    {
        return new self($list, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'status' => (string) $this->status,
            'items_count' => $this->resolveItemsCount(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];

        if (!$this->withItems) {
            return $payload;
        }

        $items = $this->relationLoaded('items')
            ? $this->items
            : collect();

        $sortedItems = $items
            ->sortBy([
                static fn(ShoppingListItem $item): int => $item->is_checked ? 1 : 0,
                static fn(ShoppingListItem $item): string => mb_strtolower(trim((string) ($item->name ?? ''))),
            ])
            ->values();

        $groupsByStatus = $sortedItems->groupBy(
            static fn(ShoppingListItem $item): string => $item->is_checked ? 'checked' : 'active'
        );

        $groupsByCategory = $sortedItems
            ->groupBy(function (ShoppingListItem $item): string {
                $rawCategory = $item->relationLoaded('ingredient')
                    ? trim((string) ($item->ingredient?->category ?? ''))
                    : '';

                return $rawCategory !== '' ? $rawCategory : 'Sans catégorie';
            })
            ->sortKeys()
            ->map(function (Collection $categoryItems, string $category) use ($request): array {
                return [
                    'category' => $category,
                    'items' => ShoppingListItemResource::collection($categoryItems->values())->resolve($request),
                ];
            })
            ->values()
            ->all();

        $payload['items'] = ShoppingListItemResource::collection($sortedItems)->resolve($request);
        $payload['items_grouped'] = [
            'active' => ShoppingListItemResource::collection($groupsByStatus->get('active', collect()))->resolve($request),
            'checked' => ShoppingListItemResource::collection($groupsByStatus->get('checked', collect()))->resolve($request),
            'by_category' => $groupsByCategory,
        ];

        return $payload;
    }

    private function resolveItemsCount(): int
    {
        if (isset($this->items_count)) {
            return (int) $this->items_count;
        }

        if ($this->relationLoaded('items')) {
            return $this->items->count();
        }

        return 0;
    }
}
