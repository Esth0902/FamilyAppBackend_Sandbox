<?php

namespace App\Http\Resources\Recipe;

use App\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ingredient */
class IngredientResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        mixed $resource,
        private readonly float $scaleFactor = 1.0,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $baseQuantity = (float) ($this->pivot->quantity ?? 0);
        $unit = $this->pivot->unit ?? null;

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'category' => $this->category !== null ? (string) $this->category : null,
            'pivot' => [
                'quantity' => $baseQuantity,
                'unit' => $unit !== null ? (string) $unit : null,
            ],
            'base_quantity' => $baseQuantity,
            'scaled_quantity' => round($baseQuantity * $this->scaleFactor, 2),
        ];
    }
}
