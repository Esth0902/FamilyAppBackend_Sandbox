<?php

namespace App\Actions\ShoppingList;

use App\Models\Household;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Services\RealtimePublisher;

class RemoveShoppingListItemAction
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function execute(Household $household, User $actor, ShoppingListItem $item): void
    {
        $itemId = (int) $item->id;
        $listId = (int) $item->shopping_list_id;
        $item->delete();

        $this->realtimePublisher->publishHousehold(
            householdId: (int) $household->id,
            module: 'shopping_list',
            type: 'item.deleted',
            payload: [
                'list_id' => $listId,
                'item_id' => $itemId,
                'actor_user_id' => (int) $actor->id,
            ],
        );
    }
}
