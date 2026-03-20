<?php

namespace App\Actions\ShoppingList;

use App\Models\Household;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Services\EmbeddingService;
use App\Services\RealtimePublisher;

class UpdateShoppingListItemAction
{
    public function __construct(
        private readonly ToggleShoppingListItemAction $toggleShoppingListItemAction,
        private readonly EmbeddingService $embeddingService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(
        Household $household,
        ShoppingListItem $item,
        User $actor,
        array $validated,
    ): ShoppingListItem {
        $currentItem = $item;

        if (array_key_exists('is_checked', $validated)) {
            $currentItem = $this->toggleShoppingListItemAction->execute(
                household: $household,
                item: $currentItem,
                actor: $actor,
                isChecked: (bool) $validated['is_checked'],
            );
        }

        $updates = [];
        if (array_key_exists('quantity', $validated)) {
            $updates['quantity'] = trim((string) $validated['quantity']);
        }

        if (array_key_exists('name', $validated)) {
            $name = trim((string) $validated['name']);
            $updates['name'] = $name;
            $updates['embedding'] = $this->embeddingService->serializeVector(
                $this->embeddingService->generateVector($name)
            );
        }

        if (count($updates) === 0) {
            return $currentItem->fresh()->load('checkedBy:id,name', 'createdBy:id,name');
        }

        $currentItem->update($updates);

        $this->realtimePublisher->publishHousehold(
            householdId: (int) $household->id,
            module: 'shopping_list',
            type: 'item.updated',
            payload: [
                'list_id' => (int) $currentItem->shopping_list_id,
                'item_id' => (int) $currentItem->id,
                'actor_user_id' => (int) $actor->id,
            ],
        );

        return $currentItem->fresh()->load('checkedBy:id,name', 'createdBy:id,name');
    }
}
