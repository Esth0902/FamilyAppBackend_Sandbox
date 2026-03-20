<?php

namespace App\Actions\ShoppingList;

use App\Models\Household;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Services\RealtimePublisher;
use Illuminate\Support\Facades\Schema;

class ToggleShoppingListItemAction
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function execute(
        Household $household,
        ShoppingListItem $item,
        User $actor,
        bool $isChecked,
    ): ShoppingListItem {
        $updates = [
            'is_checked' => $isChecked,
        ];

        if (Schema::hasColumn('shopping_list_items', 'checked_by_user_id')) {
            $updates['checked_by_user_id'] = $isChecked ? (int) $actor->id : null;
        }

        $hasStateChanged = (bool) $item->is_checked !== $isChecked
            || (
                array_key_exists('checked_by_user_id', $updates)
                && (int) ($item->checked_by_user_id ?? 0) !== (int) ($updates['checked_by_user_id'] ?? 0)
            );

        if ($hasStateChanged) {
            $item->update($updates);

            $this->realtimePublisher->publishHousehold(
                householdId: (int) $household->id,
                module: 'shopping_list',
                type: 'item.updated',
                payload: [
                    'list_id' => (int) $item->shopping_list_id,
                    'item_id' => (int) $item->id,
                    'actor_user_id' => (int) $actor->id,
                ],
            );
        }

        return $item->fresh()->load('checkedBy:id,name', 'createdBy:id,name');
    }
}
