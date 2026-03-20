<?php

namespace App\Http\Resources\ShoppingList;

use App\Models\MealPlan;
use App\Models\ShoppingList;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ShoppingListDetailResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param Collection<int, MealPlan> $mealPlans
     */
    public static function fromContext(
        bool $canManage,
        bool $canAddManualItems,
        ShoppingList $list,
        string $fromDate,
        string $toDate,
        Collection $mealPlans
    ): self {
        return self::make([
            'can_manage' => $canManage,
            'can_add_manual_items' => $canAddManualItems,
            'list' => $list,
            'planned_meals_from' => $fromDate,
            'planned_meals_to' => $toDate,
            'meal_plans' => $mealPlans,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $list = $this->resource['list'] ?? null;
        if (!$list instanceof ShoppingList) {
            return [];
        }

        return [
            'can_manage' => (bool) ($this->resource['can_manage'] ?? false),
            'can_add_manual_items' => (bool) ($this->resource['can_add_manual_items'] ?? false),
            'list' => ShoppingListResource::detail($list)->resolve($request),
            'planned_meals_from' => (string) ($this->resource['planned_meals_from'] ?? ''),
            'planned_meals_to' => (string) ($this->resource['planned_meals_to'] ?? ''),
            'planned_recipe_suggestions' => $this->buildPlannedRecipeSuggestions($list),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPlannedRecipeSuggestions(ShoppingList $list): array
    {
        $mealPlans = collect($this->resource['meal_plans'] ?? []);

        $existingItems = $list->relationLoaded('items')
            ? $list->items
            : collect();
        $existingIngredientIds = $existingItems
            ->whereNotNull('ingredient_id')
            ->pluck('ingredient_id')
            ->map(static fn($id): int => (int) $id)
            ->all();

        $mealTypeOrder = ['matin' => 0, 'midi' => 1, 'soir' => 2];
        $recipes = [];

        foreach ($mealPlans as $mealPlan) {
            if (!$mealPlan instanceof MealPlan) {
                continue;
            }

            foreach ($mealPlan->items as $mealPlanItem) {
                $recipe = $mealPlanItem->recipe;
                if (!$recipe) {
                    continue;
                }

                $baseServings = max(1, (int) ($recipe->base_servings ?? 1));
                $targetServings = max(1, (int) ($mealPlanItem->servings ?? $baseServings));
                $scale = $targetServings / $baseServings;

                $ingredients = $recipe->ingredients
                    ->map(function ($ingredient) use ($scale, $existingIngredientIds): array {
                        $name = trim((string) ($ingredient->name ?? 'ingredient'));
                        $unit = trim((string) ($ingredient->pivot->unit ?? ''));
                        $ingredientId = $ingredient->id ? (int) $ingredient->id : null;
                        $scaledQty = round((float) ($ingredient->pivot->quantity ?? 0) * $scale, 2);

                        $alreadyInList = $ingredientId !== null
                            ? in_array($ingredientId, $existingIngredientIds, true)
                            : false;

                        return [
                            'ingredient_id' => $ingredientId,
                            'name' => $name,
                            'quantity' => self::formatQuantity($scaledQty),
                            'unit' => $unit,
                            'already_in_list' => $alreadyInList,
                        ];
                    })
                    ->values();

                $recipes[] = [
                    'meal_plan_id' => (int) $mealPlan->id,
                    'recipe_id' => (int) $recipe->id,
                    'recipe_title' => (string) $recipe->title,
                    'date' => $mealPlan->date ? Carbon::parse((string) $mealPlan->date)->toDateString() : null,
                    'meal_type' => (string) $mealPlan->meal_type,
                    'servings' => $targetServings,
                    'already_in_list' => $ingredients->count() > 0
                        ? $ingredients->every(static fn(array $ingredient): bool => (bool) $ingredient['already_in_list'])
                        : false,
                    'ingredients' => $ingredients->all(),
                    'meal_type_order' => $mealTypeOrder[(string) $mealPlan->meal_type] ?? 99,
                ];
            }
        }

        return collect($recipes)
            ->sortBy([
                ['date', 'asc'],
                ['meal_type_order', 'asc'],
                ['recipe_title', 'asc'],
            ])
            ->values()
            ->map(function (array $recipe): array {
                unset($recipe['meal_type_order']);

                return $recipe;
            })
            ->all();
    }

    private static function formatQuantity(float $value): string
    {
        $rounded = round($value, 2);
        if ((int) $rounded === (float) $rounded) {
            return (string) (int) $rounded;
        }

        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }
}
