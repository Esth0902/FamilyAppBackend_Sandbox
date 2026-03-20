<?php

namespace App\Http\Resources\ShoppingList;

use App\Models\ShoppingListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ShoppingListItem */
class ShoppingListItemResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = null;
        if ($this->relationLoaded('ingredient') && $this->ingredient !== null) {
            $rawCategory = trim((string) ($this->ingredient->category ?? ''));
            $category = $rawCategory !== '' ? $rawCategory : null;
        }

        return [
            'id' => (int) $this->id,
            'ingredient_id' => $this->ingredient_id !== null ? (int) $this->ingredient_id : null,
            'name' => (string) $this->name,
            'quantity' => $this->formatQuantity($this->quantity),
            'unit' => $this->unit !== null ? (string) $this->unit : null,
            'is_checked' => (bool) $this->is_checked,
            'checked_by' => $this->checkedBy !== null
                ? [
                    'id' => (int) $this->checkedBy->id,
                    'name' => (string) $this->checkedBy->name,
                ]
                : null,
            'created_by' => $this->createdBy !== null
                ? [
                    'id' => (int) $this->createdBy->id,
                    'name' => (string) $this->createdBy->name,
                ]
                : null,
            'is_manual_addition' => (bool) $this->is_manual_addition,
            'category' => $category,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }

    private function formatQuantity(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $stringValue = trim((string) $raw);
        if ($stringValue === '') {
            return null;
        }

        if (!is_numeric($stringValue)) {
            return $stringValue;
        }

        $value = (float) $stringValue;
        $rounded = round($value, 2);
        if ((int) $rounded === (float) $rounded) {
            return (string) (int) $rounded;
        }

        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }
}
