<?php

namespace App\Http\Resources\Recipe;

use App\Models\MealSetting;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Recipe */
class RecipeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @var array<int, int>
     */
    private static array $householdDefaultServingsCache = [];

    /**
     * @var array<int, int>
     */
    private static array $userDefaultHouseholdCache = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentHouseholdId = $this->resolveCurrentHouseholdId($request);
        $targetServings = $this->resolveTargetServings($request, $currentHouseholdId);
        $baseServings = max(1, (int) ($this->base_servings ?? 1));
        $scaleFactor = $targetServings / $baseServings;

        $isOwnedByHousehold = $currentHouseholdId > 0
            && (int) ($this->household_id ?? 0) === $currentHouseholdId;
        $isSavedGlobal = (bool) $this->is_global
            && $this->isBookmarkedForCurrentHousehold($currentHouseholdId);

        $ingredients = $this->relationLoaded('ingredients')
            ? $this->ingredients
                ->map(fn ($ingredient) => new IngredientResource($ingredient, $scaleFactor))
                ->values()
            : [];

        return [
            'id' => (int) $this->id,
            'household_id' => $this->household_id !== null ? (int) $this->household_id : null,
            'is_global' => (bool) $this->is_global,
            'is_ai_generated' => (bool) $this->is_ai_generated,
            'title' => (string) $this->title,
            'type' => (string) $this->type,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'source_url' => $this->source_url,
            'base_servings' => $baseServings,
            'display_servings' => $targetServings,
            'scale_factor' => round($scaleFactor, 4),
            'is_owned_by_household' => $isOwnedByHousehold,
            'is_in_my_recipes' => $isOwnedByHousehold || $isSavedGlobal,
            'ingredients' => $ingredients,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }

    private function resolveCurrentHouseholdId(Request $request): int
    {
        $householdIdFromAttributes = $request->attributes->get('current_household_id');
        if (is_numeric($householdIdFromAttributes)) {
            return (int) $householdIdFromAttributes;
        }

        $userId = (int) ($request->user()?->id ?? 0);
        if ($userId > 0) {
            if (array_key_exists($userId, self::$userDefaultHouseholdCache)) {
                return self::$userDefaultHouseholdCache[$userId];
            }

            $defaultHouseholdId = (int) ($request->user()?->households()->value('households.id') ?? 0);
            self::$userDefaultHouseholdCache[$userId] = $defaultHouseholdId;
            if ($defaultHouseholdId > 0) {
                return $defaultHouseholdId;
            }
        }

        if (!method_exists($request, 'household')) {
            return 0;
        }

        try {
            $household = $request->household();
            return $household ? (int) ($household->id ?? 0) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function resolveTargetServings(Request $request, int $fallbackHouseholdId): int
    {
        $queryServings = $request->query('servings');
        if (is_numeric($queryServings)) {
            $parsed = (int) $queryServings;
            if ($parsed >= 1 && $parsed <= 30) {
                return $parsed;
            }
        }

        $householdDefault = (int) ($this->household?->mealSettings?->default_servings ?? 0);
        if ($householdDefault < 1 && $fallbackHouseholdId > 0) {
            $householdDefault = $this->resolveHouseholdDefaultServings($fallbackHouseholdId);
        }

        if ($householdDefault >= 1) {
            return $householdDefault;
        }

        return max(1, (int) ($this->base_servings ?? 1));
    }

    private function resolveHouseholdDefaultServings(int $householdId): int
    {
        if (array_key_exists($householdId, self::$householdDefaultServingsCache)) {
            return self::$householdDefaultServingsCache[$householdId];
        }

        $value = (int) (MealSetting::query()
            ->where('household_id', $householdId)
            ->value('default_servings') ?? 0);

        $resolved = $value >= 1 ? $value : 1;
        self::$householdDefaultServingsCache[$householdId] = $resolved;

        return $resolved;
    }

    private function isBookmarkedForCurrentHousehold(int $householdId): bool
    {
        $attributes = $this->resource->getAttributes();
        if (array_key_exists('is_bookmarked_for_household', $attributes)) {
            return (bool) $attributes['is_bookmarked_for_household'];
        }

        if ($this->relationLoaded('savedByHouseholds')) {
            if ($householdId <= 0) {
                return $this->savedByHouseholds->isNotEmpty();
            }

            return $this->savedByHouseholds->contains(
                fn ($household): bool => (int) ($household->id ?? 0) === $householdId
            );
        }

        if ($householdId <= 0) {
            return false;
        }

        return $this->savedByHouseholds()->whereKey($householdId)->exists();
    }
}
