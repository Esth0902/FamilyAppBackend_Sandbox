<?php

namespace App\Http\Resources\Recipe;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeAiPayloadResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return is_array($this->resource) ? $this->resource : [];
    }
}
