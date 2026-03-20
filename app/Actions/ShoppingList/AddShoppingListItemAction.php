<?php

namespace App\Actions\ShoppingList;

use App\Models\Household;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Services\EmbeddingService;
use App\Services\RealtimePublisher;

class AddShoppingListItemAction
{
    private const SEMANTIC_DISTANCE_THRESHOLD = 0.10;

    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(
        Household $household,
        ShoppingList $list,
        User $actor,
        array $validated,
        bool $isManualAddition,
    ): ShoppingListItem {
        $payload = $this->normalizeIncomingPayload($validated);
        $vector = $this->embeddingService->generateVector((string) $payload['name']);
        $serializedVector = $this->embeddingService->serializeVector($vector);

        $existingItem = $this->resolveExistingItem(
            household: $household,
            list: $list,
            payload: $payload,
            vector: is_array($vector) ? $vector : [],
        );

        if ($existingItem instanceof ShoppingListItem) {
            $existingItem->update([
                'name' => $payload['name'] !== '' ? $payload['name'] : (string) ($existingItem->name ?? ''),
                'unit' => $payload['unit'] !== '' ? $payload['unit'] : (string) ($existingItem->unit ?? ''),
                'quantity' => $this->mergeQuantities(
                    (string) ($existingItem->quantity ?? ''),
                    (string) ($payload['quantity'] ?? '')
                ),
                'is_manual_addition' => (bool) $existingItem->is_manual_addition && $isManualAddition,
                'created_by_user_id' => (int) ($existingItem->created_by_user_id ?? 0) > 0
                    ? (int) $existingItem->created_by_user_id
                    : (int) $actor->id,
                'embedding' => $existingItem->embedding ?: $serializedVector,
            ]);

            $item = $existingItem->fresh();
        } else {
            $item = $list->items()->create([
                'ingredient_id' => $payload['ingredient_id'],
                'name' => $payload['name'],
                'quantity' => $payload['quantity'],
                'unit' => $payload['unit'],
                'embedding' => $serializedVector,
                'is_checked' => false,
                'is_manual_addition' => $isManualAddition,
                'created_by_user_id' => (int) $actor->id,
            ]);
        }

        $this->realtimePublisher->publishHousehold(
            householdId: (int) $household->id,
            module: 'shopping_list',
            type: 'item.upserted',
            payload: [
                'list_id' => (int) $item->shopping_list_id,
                'item_id' => (int) $item->id,
                'actor_user_id' => (int) $actor->id,
            ],
        );

        return $item->load('checkedBy:id,name', 'createdBy:id,name');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, float>  $vector
     */
    private function resolveExistingItem(Household $household, ShoppingList $list, array $payload, array $vector): ?ShoppingListItem
    {
        $ingredientId = isset($payload['ingredient_id']) ? (int) $payload['ingredient_id'] : null;

        if ($ingredientId !== null && $ingredientId > 0) {
            return ShoppingListItem::query()
                ->where('shopping_list_id', $list->id)
                ->where('ingredient_id', $ingredientId)
                ->where('is_checked', false)
                ->first();
        }

        if (count($vector) === 0) {
            return null;
        }

        $closestInList = $this->embeddingService->findClosestSemanticMatch(
            table: 'shopping_list_items',
            vector: $vector,
            whereClause: 'shopping_list_id = ? AND is_checked = false AND embedding IS NOT NULL',
            bindings: [(int) $list->id],
            columns: ['id', 'unit'],
        );
        $closestMatch = $this->isMatchWithinThreshold($closestInList)
            ? $closestInList
            : $this->embeddingService->findClosestSemanticMatch(
                table: 'shopping_list_items',
                vector: $vector,
                whereClause: 'shopping_list_id IN (SELECT id FROM shopping_lists WHERE household_id = ?) AND is_checked = false AND embedding IS NOT NULL',
                bindings: [(int) $household->id],
                columns: ['id', 'unit'],
            );

        if (!is_array($closestMatch)) {
            return null;
        }

        if (!$this->isMatchWithinThreshold($closestMatch)) {
            return null;
        }

        $existingItem = ShoppingListItem::query()->find((int) ($closestMatch['id'] ?? 0));
        if (!$existingItem instanceof ShoppingListItem) {
            return null;
        }

        $existingUnit = trim((string) ($existingItem->unit ?? ''));
        $incomingUnit = trim((string) ($payload['unit'] ?? ''));
        if ($existingUnit !== '' && $incomingUnit !== '' && mb_strtolower($existingUnit) !== mb_strtolower($incomingUnit)) {
            return null;
        }

        return $existingItem;
    }

    /**
     * @param  array<string, mixed>|null  $match
     */
    private function isMatchWithinThreshold(?array $match): bool
    {
        if (!is_array($match)) {
            return false;
        }

        return (float) ($match['distance'] ?? 1) <= self::SEMANTIC_DISTANCE_THRESHOLD;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *     ingredient_id:int|null,
     *     name:string,
     *     unit:string,
     *     quantity:string
     * }
     */
    private function normalizeIncomingPayload(array $validated): array
    {
        $ingredientId = isset($validated['ingredient_id']) ? (int) $validated['ingredient_id'] : null;
        $ingredient = $ingredientId ? Ingredient::find($ingredientId) : null;

        $name = trim((string) ($validated['name'] ?? ''));
        if ($name === '' && $ingredient) {
            $name = (string) $ingredient->name;
        }

        $unit = trim((string) ($validated['unit'] ?? ''));
        $quantity = isset($validated['quantity'])
            ? $this->formatQuantity((float) $validated['quantity'])
            : '';

        return [
            'ingredient_id' => $ingredient?->id,
            'name' => $name,
            'unit' => $unit,
            'quantity' => $quantity,
        ];
    }

    private function mergeQuantities(string $existing, string $incoming): string
    {
        $existingFloat = is_numeric($existing) ? (float) $existing : null;
        $incomingFloat = is_numeric($incoming) ? (float) $incoming : null;

        if ($existingFloat !== null && $incomingFloat !== null) {
            return $this->formatQuantity($existingFloat + $incomingFloat);
        }

        if ($incoming !== '') {
            return $incoming;
        }

        return $existing;
    }

    private function formatQuantity(float $value): string
    {
        $rounded = round($value, 2);
        if ((int) $rounded === (float) $rounded) {
            return (string) (int) $rounded;
        }

        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }
}
