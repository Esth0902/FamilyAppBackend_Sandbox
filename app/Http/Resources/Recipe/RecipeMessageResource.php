<?php

namespace App\Http\Resources\Recipe;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeMessageResource extends JsonResource
{
    public static $wrap = null;

    public static function fromMessage(string $message): self
    {
        return new self(['message' => $message]);
    }

    /**
     * @return array{message:string}
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => (string) ($this->resource['message'] ?? ''),
        ];
    }
}
