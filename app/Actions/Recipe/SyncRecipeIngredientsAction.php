<?php

namespace App\Actions\Recipe;

use App\Models\Ingredient;
use App\Services\EmbeddingService;

class SyncRecipeIngredientsAction
{
    private const SEMANTIC_DISTANCE_THRESHOLD = 0.10;

    /**
     * @var array<int, string>
     */
    private const INGREDIENT_CATEGORIES = [
        'fruits et légumes',
        'boucherie',
        'poissonnerie',
        'crèmerie',
        'épicerie salée',
        'épicerie sucrée',
        'boissons',
        'surgelés',
        'entretien et hygiène',
        'autre',
    ];

    public function __construct(private readonly EmbeddingService $embeddingService)
    {
    }

    /**
     * @param  array<int, array<string, mixed>>  $ingredientsPayload
     * @return array<int, array{quantity: float, unit: string}>
     */
    public function execute(array $ingredientsPayload): array
    {
        $syncData = [];

        foreach ($ingredientsPayload as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $category = $this->normalizeCategory((string) ($item['category'] ?? 'autre'));
            $quantity = (float) ($item['quantity'] ?? 0);
            $unit = (string) ($item['unit'] ?? '');

            $ingredient = $this->resolveIngredient($name, $category);
            $ingredientId = (int) $ingredient->id;

            if (isset($syncData[$ingredientId])) {
                $syncData[$ingredientId]['quantity'] += $quantity;
            } else {
                $syncData[$ingredientId] = [
                    'quantity' => $quantity,
                    'unit' => $unit,
                ];
            }
        }

        return $syncData;
    }

    private function resolveIngredient(string $name, string $category): Ingredient
    {
        $embedding = $this->embeddingService->generateVector($name);

        if (is_array($embedding)) {
            $closest = $this->embeddingService->findClosestSemanticMatch(
                table: 'ingredients',
                vector: $embedding,
                whereClause: 'embedding IS NOT NULL',
                columns: ['id', 'name', 'category'],
            );

            if (is_array($closest) && (float) ($closest['distance'] ?? 1) <= self::SEMANTIC_DISTANCE_THRESHOLD) {
                $matched = Ingredient::query()->find((int) ($closest['id'] ?? 0));
                if ($matched instanceof Ingredient) {
                    return $matched;
                }
            }
        }

        $exact = Ingredient::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($exact instanceof Ingredient) {
            if (is_array($embedding) && empty($exact->embedding)) {
                $exact->update([
                    'embedding' => $this->embeddingService->serializeVector($embedding),
                ]);
            }

            return $exact;
        }

        return Ingredient::query()->create([
            'name' => $name,
            'category' => $category,
            'embedding' => $this->embeddingService->serializeVector($embedding),
        ]);
    }

    private function normalizeCategory(string $category): string
    {
        $normalized = mb_strtolower(trim($category));

        $aliases = [
            'epicerie salee' => 'épicerie salée',
            'epicerie sucree' => 'épicerie sucrée',
            'fruit et legumes' => 'fruits et légumes',
        ];

        $resolved = $aliases[$normalized] ?? $normalized;

        return in_array($resolved, self::INGREDIENT_CATEGORIES, true) ? $resolved : 'autre';
    }
}
