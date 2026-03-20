<?php

namespace App\Http\Resources\Recipe;

use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Recipe */
class RecipeMutationResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(mixed $resource, private readonly string $message)
    {
        parent::__construct($resource);
    }

    public static function fromRecipe(Recipe $recipe, string $message): self
    {
        return new self($recipe, $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => $this->message,
            'recipe' => RecipeResource::make($this->resource)->resolve($request),
        ];
    }
}
