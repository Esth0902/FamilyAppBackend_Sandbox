<?php

namespace App\Actions\Recipe;

use App\Models\MealSetting;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;

class UpsertRecipeAction
{
    public function __construct(private readonly SyncRecipeIngredientsAction $syncRecipeIngredientsAction)
    {
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function upsertManual(int $householdId, array $validated, ?Recipe $recipe = null): Recipe
    {
        return DB::transaction(function () use ($householdId, $validated, $recipe): Recipe {
            $syncData = $this->syncRecipeIngredientsAction->execute((array) ($validated['ingredients'] ?? []));

            if ($recipe instanceof Recipe) {
                $recipe->update([
                    'title' => (string) $validated['title'],
                    'type' => (string) $validated['type'],
                    'description' => (string) ($validated['description'] ?? ''),
                    'instructions' => (string) ($validated['instructions'] ?? ''),
                    'base_servings' => (int) ($validated['base_servings'] ?? $recipe->base_servings ?? 1),
                ]);
            } else {
                $recipe = Recipe::query()->create([
                    'household_id' => $householdId,
                    'is_global' => false,
                    'title' => (string) $validated['title'],
                    'type' => (string) $validated['type'],
                    'description' => (string) ($validated['description'] ?? ''),
                    'instructions' => (string) ($validated['instructions'] ?? ''),
                    'is_ai_generated' => false,
                    'base_servings' => (int) ($validated['base_servings'] ?? $this->resolveHouseholdDefaultServings($householdId)),
                ]);
            }

            $recipe->ingredients()->sync($syncData);

            return $recipe->load(['ingredients', 'household.mealSettings']);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function createAi(array $validated): Recipe
    {
        return DB::transaction(function () use ($validated): Recipe {
            $recipe = Recipe::query()->create([
                'household_id' => (int) $validated['household_id'],
                'is_global' => false,
                'title' => (string) $validated['title'],
                'type' => (string) $validated['type'],
                'description' => (string) $validated['description'],
                'instructions' => (string) $validated['instructions'],
                'is_ai_generated' => true,
                'base_servings' => 1,
            ]);

            $syncData = $this->syncRecipeIngredientsAction->execute((array) ($validated['ingredients'] ?? []));
            $recipe->ingredients()->sync($syncData);

            return $recipe->load(['ingredients', 'household.mealSettings']);
        });
    }

    private function resolveHouseholdDefaultServings(int $householdId): int
    {
        $defaultServings = (int) (MealSetting::query()
            ->where('household_id', $householdId)
            ->value('default_servings') ?? 0);

        return $defaultServings >= 1 ? $defaultServings : 1;
    }
}
